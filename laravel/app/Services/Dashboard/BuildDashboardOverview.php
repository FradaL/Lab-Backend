<?php

namespace App\Services\Dashboard;

use App\Audit\OrderAuditEvents;
use App\Models\AuditLog;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class BuildDashboardOverview
{
    private const int ACTIVITY_LIMIT = 4;

    /**
     * @return array{
     *     context: array{date: string, timezone: string, currency: string, generated_at: string},
     *     metrics: array{
     *         orders: array{count: int, previous_count: int, change_percentage: ?float},
     *         attended_patients: array{count: int, previous_count: int, change_percentage: ?float},
     *         pending_orders: array{count: int}
     *     },
     *     recent_activity: list<array<string, mixed>>
     * }
     */
    public function execute(
        Laboratory $laboratory,
        CarbonImmutable $businessDate,
        ?int $branchId = null,
    ): array {
        $todayStart = $businessDate->startOfDay()->utc();
        $tomorrowStart = $businessDate->addDay()->startOfDay()->utc();
        $yesterdayStart = $businessDate->subDay()->startOfDay()->utc();

        $today = $this->metricSnapshot(
            $laboratory,
            $todayStart,
            $tomorrowStart,
            $branchId,
        );
        $yesterday = $this->metricSnapshot(
            $laboratory,
            $yesterdayStart,
            $todayStart,
            $branchId,
        );

        return [
            'context' => [
                'date' => $businessDate->toDateString(),
                'timezone' => $laboratory->timezone,
                'currency' => $laboratory->currency,
                'generated_at' => CarbonImmutable::now($laboratory->timezone)->toIso8601String(),
            ],
            'metrics' => [
                'orders' => $this->comparisonMetric(
                    $today['orders'],
                    $yesterday['orders'],
                ),
                'attended_patients' => $this->comparisonMetric(
                    $today['attended_patients'],
                    $yesterday['attended_patients'],
                ),
                'pending_orders' => [
                    'count' => $today['pending_orders'],
                ],
            ],
            'recent_activity' => $this->recentActivity(
                $laboratory,
                $todayStart,
                $tomorrowStart,
                $branchId,
            ),
        ];
    }

    /** @return array{orders: int, attended_patients: int, pending_orders: int} */
    private function metricSnapshot(
        Laboratory $laboratory,
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?int $branchId,
    ): array {
        $snapshot = LaboratoryOrder::forLaboratory($laboratory)
            ->when(
                $branchId !== null,
                fn (Builder $query): Builder => $query->where('branch_id', $branchId),
            )
            ->where('ordered_at', '>=', $start)
            ->where('ordered_at', '<', $end)
            ->toBase()
            ->selectRaw('COUNT(*) AS orders_count')
            ->selectRaw(
                'COUNT(DISTINCT CASE WHEN status <> ? THEN patient_id END) AS attended_patients_count',
                [LaboratoryOrder::STATUS_CANCELLED],
            )
            ->selectRaw(
                'COUNT(CASE WHEN status = ? THEN 1 END) AS pending_orders_count',
                [LaboratoryOrder::STATUS_PENDING],
            )
            ->first();

        return [
            'orders' => (int) ($snapshot->orders_count ?? 0),
            'attended_patients' => (int) ($snapshot->attended_patients_count ?? 0),
            'pending_orders' => (int) ($snapshot->pending_orders_count ?? 0),
        ];
    }

    /** @return array{count: int, previous_count: int, change_percentage: ?float} */
    private function comparisonMetric(int $current, int $previous): array
    {
        return [
            'count' => $current,
            'previous_count' => $previous,
            'change_percentage' => $previous === 0
                ? null
                : round((($current - $previous) / $previous) * 100, 2),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function recentActivity(
        Laboratory $laboratory,
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?int $branchId,
    ): array {
        $orderIds = LaboratoryOrder::forLaboratory($laboratory)
            ->select('id')
            ->when(
                $branchId !== null,
                fn (Builder $query): Builder => $query->where('branch_id', $branchId),
            );

        $auditLogs = AuditLog::forLaboratory($laboratory)
            ->select(['id', 'event', 'auditable_id', 'created_at'])
            ->where('auditable_type', OrderAuditEvents::SUBJECT_ORDER)
            ->whereIn('event', [
                OrderAuditEvents::CREATED,
                OrderAuditEvents::STATUS_CHANGED,
            ])
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->whereIn('auditable_id', $orderIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::ACTIVITY_LIMIT)
            ->get();

        $orders = LaboratoryOrder::forLaboratory($laboratory)
            ->select(['id', 'patient_id', 'code', 'status'])
            ->with('patient:id,first_names,last_names')
            ->whereKey($auditLogs->pluck('auditable_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        return $auditLogs
            ->map(function (AuditLog $auditLog) use ($orders, $laboratory): ?array {
                $order = $orders->get((int) $auditLog->auditable_id);

                if ($order === null) {
                    return null;
                }

                return [
                    'id' => $auditLog->id,
                    'type' => $auditLog->event,
                    'occurred_at' => $auditLog->created_at
                        ->setTimezone($laboratory->timezone)
                        ->toIso8601String(),
                    'patient' => [
                        'id' => $order->patient->id,
                        'first_names' => $order->patient->first_names,
                        'last_names' => $order->patient->last_names,
                    ],
                    'order' => [
                        'id' => $order->id,
                        'code' => $order->code,
                        'status' => $order->status,
                    ],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}

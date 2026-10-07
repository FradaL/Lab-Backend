<?php

namespace App\Actions\LaboratoryOrders;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\Audit\OrderAuditEvents;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TransitionLaboratoryOrderStatus
{
    public function __construct(
        private readonly AuditWriter $auditWriter,
    ) {}

    public function execute(
        Laboratory $laboratory,
        int $laboratoryOrderId,
        string $targetStatus,
        ?User $actor = null,
    ): LaboratoryOrder {
        $order = DB::transaction(function () use ($laboratory, $laboratoryOrderId, $targetStatus, $actor): LaboratoryOrder {
            $lockedOrder = LaboratoryOrder::forLaboratory($laboratory)
                ->whereKey($laboratoryOrderId)
                ->lockForUpdate()
                ->first();

            if ($lockedOrder === null) {
                abort(404, 'Resource not found.');
            }

            if (! $lockedOrder->canTransitionTo($targetStatus)) {
                throw ValidationException::withMessages([
                    'status' => ['The selected status transition is not allowed.'],
                ]);
            }

            if ($lockedOrder->status !== $targetStatus) {
                $oldStatus = $lockedOrder->status;
                $lockedOrder->status = $targetStatus;
                $lockedOrder->save();

                $this->auditWriter->record($laboratory, $actor, new AuditEvent(
                    OrderAuditEvents::STATUS_CHANGED,
                    OrderAuditEvents::SUBJECT_ORDER,
                    $lockedOrder->getKey(),
                    oldValues: ['status' => $oldStatus],
                    newValues: ['status' => $targetStatus],
                ));
            }

            return $lockedOrder;
        });

        return $order->load([
            'patient',
            'doctor',
            'branch',
            'createdBy',
        ]);
    }
}

<?php

namespace App\Actions\LaboratoryOrders;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\Audit\OrderAuditEvents;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\User;
use App\Services\LaboratoryOrders\RecalculateLaboratoryOrderEconomics;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SetLaboratoryOrderDiscount
{
    public function __construct(
        private readonly RecalculateLaboratoryOrderEconomics $recalculateEconomics,
        private readonly AuditWriter $auditWriter,
    ) {}

    public function execute(
        Laboratory $laboratory,
        int $laboratoryOrderId,
        string $type,
        string $value,
        ?User $actor = null,
    ): LaboratoryOrder {
        $order = DB::transaction(function () use ($laboratory, $laboratoryOrderId, $type, $value, $actor): LaboratoryOrder {
            $order = LaboratoryOrder::forLaboratory($laboratory)
                ->whereKey($laboratoryOrderId)
                ->lockForUpdate()
                ->first();

            if ($order === null) {
                abort(404, 'Resource not found.');
            }

            if ($order->status !== LaboratoryOrder::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'status' => ['Sólo se puede modificar el descuento de órdenes pendientes.'],
                ]);
            }

            $oldValues = $this->discountState($order);

            $order->discount_type = $type;
            $order->discount_value = $value;
            $order->save();

            $this->recalculateEconomics->execute($laboratory, $order);

            $newValues = $this->discountState($order);
            if ($oldValues !== $newValues) {
                $this->auditWriter->record($laboratory, $actor, new AuditEvent(
                    OrderAuditEvents::DISCOUNT_SET,
                    OrderAuditEvents::SUBJECT_ORDER,
                    $order->getKey(),
                    oldValues: $oldValues,
                    newValues: $newValues,
                ));
            }

            return $order;
        });

        return $order->load(['patient', 'doctor', 'branch', 'createdBy']);
    }

    /** @return array{discount_type: ?string, discount_value: ?string, discount: string} */
    private function discountState(LaboratoryOrder $order): array
    {
        return [
            'discount_type' => $order->discount_type,
            'discount_value' => $order->discount_value,
            'discount' => $order->discount,
        ];
    }
}

<?php

namespace App\Actions\LaboratoryOrders;

use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Services\LaboratoryOrders\RecalculateLaboratoryOrderEconomics;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RemoveLaboratoryOrderDiscount
{
    public function __construct(
        private readonly RecalculateLaboratoryOrderEconomics $recalculateEconomics,
    ) {}

    public function execute(Laboratory $laboratory, int $laboratoryOrderId): LaboratoryOrder
    {
        $order = DB::transaction(function () use ($laboratory, $laboratoryOrderId): LaboratoryOrder {
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

            $order->discount_type = null;
            $order->discount_value = null;
            $order->save();

            $this->recalculateEconomics->execute($laboratory, $order);

            return $order;
        });

        return $order->load(['patient', 'doctor', 'branch', 'createdBy']);
    }
}

<?php

namespace App\Actions\LaboratoryOrders;

use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TransitionLaboratoryOrderStatus
{
    public function execute(
        Laboratory $laboratory,
        int $laboratoryOrderId,
        string $targetStatus,
    ): LaboratoryOrder {
        $order = DB::transaction(function () use ($laboratory, $laboratoryOrderId, $targetStatus): LaboratoryOrder {
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
                $lockedOrder->status = $targetStatus;
                $lockedOrder->save();
            }

            return $lockedOrder;
        });

        return $order->load([
            'patient',
            'doctor',
            'commercialClient',
            'priceList',
            'branch',
            'createdBy',
        ]);
    }
}

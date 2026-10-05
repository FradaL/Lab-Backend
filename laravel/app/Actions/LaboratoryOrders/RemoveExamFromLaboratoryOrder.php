<?php

namespace App\Actions\LaboratoryOrders;

use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RemoveExamFromLaboratoryOrder
{
    public function execute(
        Laboratory $laboratory,
        int $laboratoryOrderId,
        int $laboratoryOrderExamId,
    ): void {
        DB::transaction(function () use ($laboratory, $laboratoryOrderId, $laboratoryOrderExamId): void {
            $order = LaboratoryOrder::forLaboratory($laboratory)
                ->whereKey($laboratoryOrderId)
                ->lockForUpdate()
                ->first();

            if ($order === null) {
                abort(404, 'Resource not found.');
            }

            if ($order->status !== LaboratoryOrder::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'status' => ['Sólo se pueden eliminar exámenes de órdenes pendientes.'],
                ]);
            }

            $orderExam = LaboratoryOrderExam::forLaboratory($laboratory)
                ->where('laboratory_order_id', $order->id)
                ->whereKey($laboratoryOrderExamId)
                ->first();

            if ($orderExam === null) {
                abort(404, 'Resource not found.');
            }

            $orderExam->delete();
        });
    }
}

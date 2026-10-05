<?php

namespace App\Actions\LaboratoryOrders;

use App\Models\Laboratory;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Models\PriceList;
use App\Models\PriceListExam;
use App\Services\LaboratoryOrders\RecalculateLaboratoryOrderEconomics;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AddExamToLaboratoryOrder
{
    public function __construct(
        private readonly RecalculateLaboratoryOrderEconomics $recalculateEconomics,
    ) {}

    public function execute(
        Laboratory $laboratory,
        int $laboratoryOrderId,
        int $laboratoryExamId,
    ): LaboratoryOrderExam {
        return DB::transaction(function () use ($laboratory, $laboratoryOrderId, $laboratoryExamId): LaboratoryOrderExam {
            $order = LaboratoryOrder::forLaboratory($laboratory)
                ->whereKey($laboratoryOrderId)
                ->lockForUpdate()
                ->first();

            if ($order === null) {
                abort(404, 'Resource not found.');
            }

            if ($order->status !== LaboratoryOrder::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'status' => ['Sólo se pueden agregar exámenes a órdenes pendientes.'],
                ]);
            }

            $exam = LaboratoryExam::forLaboratory($laboratory)
                ->whereKey($laboratoryExamId)
                ->where('status', LaboratoryExam::STATUS_ACTIVE)
                ->first();

            if ($exam === null) {
                throw ValidationException::withMessages([
                    'laboratory_exam_id' => ['El examen seleccionado no está disponible.'],
                ]);
            }

            $priceList = PriceList::forLaboratory($laboratory)
                ->whereKey($order->price_list_id)
                ->where('status', PriceList::STATUS_ACTIVE)
                ->first();

            if ($priceList === null) {
                throw ValidationException::withMessages([
                    'price_list' => ['La lista de precios de la orden no está activa.'],
                ]);
            }

            if ($priceList->currency !== $order->currency) {
                throw ValidationException::withMessages([
                    'price_list' => ['La moneda de la lista de precios no coincide con la moneda de la orden.'],
                ]);
            }

            $priceListExam = PriceListExam::forLaboratory($laboratory)
                ->where('price_list_id', $order->price_list_id)
                ->where('laboratory_exam_id', $exam->id)
                ->where('status', PriceListExam::STATUS_ACTIVE)
                ->first();

            if ($priceListExam === null) {
                throw ValidationException::withMessages([
                    'laboratory_exam_id' => ['El examen no está disponible en la lista de precios de la orden.'],
                ]);
            }

            $orderExam = $order->orderExams()->create([
                'laboratory_id' => $laboratory->id,
                'laboratory_exam_id' => $exam->id,
                'price_list_id' => $order->price_list_id,
                'unit_price' => $priceListExam->price,
                'exam_code' => $exam->code,
                'exam_name' => $exam->name,
                'price_list_name' => $priceList->name,
            ]);

            $this->recalculateEconomics->execute($laboratory, $order);

            return $orderExam;
        });
    }
}

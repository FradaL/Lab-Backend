<?php

namespace App\Actions\LaboratoryOrders;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\Audit\OrderAuditEvents;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Models\User;
use App\Services\LaboratoryOrders\RecalculateLaboratoryOrderEconomics;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RemoveExamFromLaboratoryOrder
{
    public function __construct(
        private readonly RecalculateLaboratoryOrderEconomics $recalculateEconomics,
        private readonly AuditWriter $auditWriter,
    ) {}

    public function execute(
        Laboratory $laboratory,
        int $laboratoryOrderId,
        int $laboratoryOrderExamId,
        ?User $actor = null,
    ): void {
        DB::transaction(function () use ($laboratory, $laboratoryOrderId, $laboratoryOrderExamId, $actor): void {
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

            $snapshot = [
                'laboratory_order_id' => $orderExam->laboratory_order_id,
                'laboratory_exam_id' => $orderExam->laboratory_exam_id,
                'price_list_id' => $orderExam->price_list_id,
                'exam_code' => $orderExam->exam_code,
                'exam_name' => $orderExam->exam_name,
                'price_list_name' => $orderExam->price_list_name,
                'unit_price' => $orderExam->unit_price,
            ];
            $subjectId = $orderExam->getKey();

            $orderExam->delete();

            $this->recalculateEconomics->execute($laboratory, $order);

            $this->auditWriter->record($laboratory, $actor, new AuditEvent(
                OrderAuditEvents::EXAM_REMOVED,
                OrderAuditEvents::SUBJECT_ORDER_EXAM,
                $subjectId,
                oldValues: $snapshot,
            ));
        });
    }
}

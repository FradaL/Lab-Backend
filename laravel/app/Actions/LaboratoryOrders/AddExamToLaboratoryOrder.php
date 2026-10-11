<?php

namespace App\Actions\LaboratoryOrders;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\Audit\OrderAuditEvents;
use App\ClinicalExams\ClinicalConflict;
use App\ClinicalExams\ClinicalExamService;
use App\Models\Laboratory;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryExamVersion;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Models\PriceList;
use App\Models\PriceListExam;
use App\Models\User;
use App\Services\LaboratoryOrders\RecalculateLaboratoryOrderEconomics;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AddExamToLaboratoryOrder
{
    public function __construct(
        private readonly RecalculateLaboratoryOrderEconomics $recalculateEconomics,
        private readonly AuditWriter $auditWriter,
        private readonly ClinicalExamService $clinicalExams,
    ) {}

    public function execute(
        Laboratory $laboratory,
        int $laboratoryOrderId,
        int $laboratoryExamId,
        ?User $actor = null,
    ): LaboratoryOrderExam {
        return DB::transaction(function () use ($laboratory, $laboratoryOrderId, $laboratoryExamId, $actor): LaboratoryOrderExam {
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

            $priceList = PriceList::forLaboratory($laboratory)
                ->whereKey($order->price_list_id)
                ->sharedLock()
                ->first();

            if ($priceList === null || $priceList->status !== PriceList::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'price_list' => ['La lista de precios de la orden no está activa.'],
                ]);
            }

            if ($priceList->currency !== $order->currency) {
                throw ValidationException::withMessages([
                    'price_list' => ['La moneda de la lista de precios no coincide con la moneda de la orden.'],
                ]);
            }

            $exam = LaboratoryExam::forLaboratory($laboratory)
                ->whereKey($laboratoryExamId)
                ->lockForUpdate()
                ->first();

            if ($exam === null) {
                throw ValidationException::withMessages([
                    'laboratory_exam_id' => ['El examen seleccionado no está disponible.'],
                ]);
            }

            if ($exam->status !== LaboratoryExam::STATUS_ACTIVE) {
                throw new ClinicalConflict('clinical_exam_unavailable', 'An active exam is required.');
            }

            $priceListExam = PriceListExam::forLaboratory($laboratory)
                ->where('price_list_id', $order->price_list_id)
                ->where('laboratory_exam_id', $exam->id)
                ->sharedLock()
                ->first();

            if ($priceListExam === null || $priceListExam->status !== PriceListExam::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'laboratory_exam_id' => ['El examen no está disponible en la lista de precios de la orden.'],
                ]);
            }

            $state = $this->clinicalExams->state($laboratory, $exam->id);
            $legacyAllowed = $state?->current_version_id === null && DB::table('laboratory_order_legacy_exams')
                ->where('laboratory_id', $laboratory->id)->where('laboratory_exam_id', $exam->id)->exists()
                && ! LaboratoryExamVersion::forLaboratory($laboratory)->where('laboratory_exam_id', $exam->id)->where('status', 'published')->exists();
            $clinical = ($state?->current_version_id === null && $legacyAllowed)
                ? null
                : $this->clinicalExams->orderConfiguration($laboratory, $exam->id);

            $orderExam = $order->orderExams()->create([
                'laboratory_id' => $laboratory->id,
                'laboratory_exam_id' => $exam->id,
                'price_list_id' => $order->price_list_id,
                'unit_price' => $priceListExam->price,
                'exam_code' => $exam->code,
                'exam_name' => $exam->name,
                'price_list_name' => $priceList->name,
                'clinical_version_id' => $clinical['clinical_version_id'] ?? null,
                'clinical_configuration_source' => $clinical === null ? 'legacy_compatibility' : 'published_version',
            ]);

            foreach ($clinical['configuration']['sample_requirements'] ?? [] as $position => $requirement) {
                $orderExam->sampleRequirements()->create([
                    'laboratory_id' => $laboratory->id,
                    'laboratory_order_id' => $order->id,
                    'laboratory_exam_id' => $exam->id,
                    'clinical_version_id' => $clinical['clinical_version_id'],
                    'requirement_key' => $requirement['key'],
                    'position' => $position,
                    'sample_type_id' => $requirement['sample_type_id'],
                    'configuration' => $requirement,
                    'status' => 'pending',
                ]);
            }

            $this->recalculateEconomics->execute($laboratory, $order);

            $this->auditWriter->record($laboratory, $actor, new AuditEvent(
                OrderAuditEvents::EXAM_ADDED,
                OrderAuditEvents::SUBJECT_ORDER_EXAM,
                $orderExam->getKey(),
                newValues: $this->snapshot($orderExam),
            ));

            return $orderExam->load('sampleRequirements.operationalSatisfaction');
        });
    }

    /** @return array<string, int|string|null> */
    private function snapshot(LaboratoryOrderExam $orderExam): array
    {
        return [
            'laboratory_order_id' => $orderExam->laboratory_order_id,
            'laboratory_exam_id' => $orderExam->laboratory_exam_id,
            'price_list_id' => $orderExam->price_list_id,
            'exam_code' => $orderExam->exam_code,
            'exam_name' => $orderExam->exam_name,
            'price_list_name' => $orderExam->price_list_name,
            'unit_price' => $orderExam->unit_price,
            'clinical_version_id' => $orderExam->clinical_version_id,
            'clinical_configuration_source' => $orderExam->clinical_configuration_source,
            'configuration_hash' => $orderExam->clinical_version_id === null ? null : $orderExam->clinicalVersion->configuration_hash,
        ];
    }
}

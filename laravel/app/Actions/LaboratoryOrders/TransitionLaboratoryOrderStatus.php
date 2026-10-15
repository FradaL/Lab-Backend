<?php

namespace App\Actions\LaboratoryOrders;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\Audit\OrderAuditEvents;
use App\ClinicalExams\ClinicalConflict;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryResultValidation;
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
                if ($targetStatus === LaboratoryOrder::STATUS_COMPLETED && array_key_exists('clinical_revision', $lockedOrder->getAttributes())) {
                    throw new ClinicalConflict('clinical_finalization_required', 'Use the explicit clinical-finalization endpoint and its clinical permission.');
                }
                if ($targetStatus === LaboratoryOrder::STATUS_CANCELLED && LaboratoryResultValidation::forLaboratory($laboratory)->where('laboratory_order_id', $lockedOrder->id)->exists()) {
                    throw new ClinicalConflict('validated_order_cancellation_requires_procedure', 'Cancelling validated results requires a future explicit exceptional procedure.');
                }
                if ($targetStatus === LaboratoryOrder::STATUS_IN_PROCESS) {
                    if (! $lockedOrder->orderExams()->exists()) {
                        throw ValidationException::withMessages(['status' => ['Una orden vacía no puede iniciar procesamiento.']]);
                    }

                    $processable = $lockedOrder->orderExams()->whereNotNull('processing_started_at')->exists();
                    if (! $processable) {
                        throw new ClinicalConflict('clinical_exam_unavailable', 'At least one line must explicitly start processing before the order can advance.');
                    }
                }

                if ($targetStatus === LaboratoryOrder::STATUS_COMPLETED && $lockedOrder->orderExams()->whereNotNull('processing_started_at')->exists()) {
                    throw new ClinicalConflict('order_clinical_completion_pending', 'Clinical completion requires the future results and validation workflow.');
                }

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

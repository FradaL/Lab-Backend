<?php

namespace App\ClinicalFinalization;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\ClinicalExams\ClinicalConflict;
use App\ClinicalValidation\ValidationAuthority;
use App\Models\ClinicalExceptionDecision;
use App\Models\ClinicalExceptionRequest;
use App\Models\ClinicalLineResolution;
use App\Models\ClinicalOrderFinalization;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Models\LaboratoryResultValidation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ClinicalResolutionService
{
    public function __construct(private AuditWriter $audit, private ValidationAuthority $authority) {}

    public function requestException(Laboratory $lab, User $actor, int $orderId, int $lineId, array $input): array
    {
        $this->input($input, ['clinical_revision', 'result_revision'], ['reason' => ['required', 'string', 'max:2000']]);

        return DB::transaction(function () use ($lab, $actor, $orderId, $lineId, $input) {
            [$order,$line] = $this->locked($lab, $actor, $orderId, $lineId, $input['clinical_revision'], 'orders.clinical_exceptions.request');
            $this->unresolved($line);
            if (LaboratoryResultValidation::forLaboratory($lab)->where('laboratory_order_exam_id', $lineId)->exists()) {
                throw new ClinicalConflict('clinical_exception_not_available', 'Exceptions cannot replace any historical validation.');
            }
            if ($line->clinicalExceptions->contains('status', 'pending')) {
                throw new ClinicalConflict('clinical_exception_pending', 'An active request already exists for this line.');
            }
            $revision = $line->resultCapture?->revision ?? 0;
            if ($revision !== $input['result_revision']) {
                throw new ClinicalConflict('result_revision_conflict', 'Reload the current capture revision.');
            }
            $request = ClinicalExceptionRequest::create(['laboratory_id' => $lab->id, 'laboratory_order_id' => $orderId, 'laboratory_order_exam_id' => $lineId,
                'reason' => $input['reason'], 'status' => 'pending', 'revision' => 1, 'requested_by' => $actor->id, 'requested_at' => now(),
                'capture_revision' => $revision, 'processing_started_at' => $line->processing_started_at, 'clinical_configuration_source' => $line->clinical_configuration_source ?? 'historical']);
            $this->audit->record($lab, $actor, new AuditEvent('order_clinical.exception_requested', 'laboratory_order_exam', $lineId, newValues: ['exception_request_id' => $request->id, 'status' => 'pending'], metadata: ['reason' => $input['reason'], 'laboratory_order_id' => $orderId, 'result_revision' => $revision, 'clinical_configuration_source' => $request->clinical_configuration_source]));

            return ['clinical_revision' => $order->fresh()->clinical_revision, 'exception' => $request->toArray()];
        });
    }

    public function decideException(Laboratory $lab, User $actor, int $orderId, int $lineId, int $requestId, bool $approve, array $input): array
    {
        $this->input($input, ['clinical_revision', 'request_revision'], $approve ? ['reason' => ['sometimes', 'nullable', 'string', 'max:2000']] : ['reason' => ['required', 'string', 'max:2000']]);

        return DB::transaction(function () use ($lab, $actor, $orderId, $lineId, $requestId, $approve, $input) {
            [$order,$line] = $this->locked($lab, $actor, $orderId, $lineId, $input['clinical_revision'], 'orders.clinical_exceptions.approve');
            $request = ClinicalExceptionRequest::forLaboratory($lab)->where('laboratory_order_id', $orderId)->where('laboratory_order_exam_id', $lineId)->whereKey($requestId)->lockForUpdate()->firstOrFail();
            if ($approve && $request->requested_by === $actor->id) {
                abort(403, 'The requester cannot approve their own clinical exception.');
            }
            if ($request->status !== 'pending' || $request->revision !== $input['request_revision']) {
                throw new ClinicalConflict('clinical_exception_revision_conflict', 'The exception was already decided or its revision changed.');
            }
            $this->unresolved($line);
            if ($approve && ($request->capture_revision !== ($line->resultCapture?->revision ?? 0) || $request->processing_started_at?->toISOString() !== $line->processing_started_at?->toISOString() || LaboratoryResultValidation::forLaboratory($lab)->where('laboratory_order_exam_id', $lineId)->exists())) {
                throw new ClinicalConflict('clinical_exception_obsolete', 'The clinical line changed after this request. Reject it and review a new request.');
            }
            $snapshot = $this->lineEvidence($line);
            $decision = ClinicalExceptionDecision::create(['laboratory_id' => $lab->id, 'laboratory_order_id' => $orderId, 'laboratory_order_exam_id' => $lineId, 'exception_request_id' => $requestId,
                'decision' => $approve ? 'approved' : 'rejected', 'reason' => $input['reason'] ?? null, 'decided_by' => $actor->id, 'decided_at' => now(), 'evidence_snapshot' => $snapshot]);
            if ($approve) {
                ClinicalLineResolution::create(['laboratory_id' => $lab->id, 'laboratory_order_id' => $orderId, 'laboratory_order_exam_id' => $lineId, 'resolution_type' => 'authorized_exception',
                    'exception_decision_id' => $decision->id, 'validation_id' => null, 'resolved_by' => $actor->id, 'resolved_at' => now(), 'evidence_snapshot' => $snapshot + ['exception_request_id' => $requestId, 'exception_decision_id' => $decision->id, 'reason' => $request->reason, 'requested_by' => $request->requested_by]]);
            }
            $this->audit->record($lab, $actor, new AuditEvent('order_clinical.exception_'.($approve ? 'approved' : 'rejected'), 'laboratory_order_exam', $lineId,
                oldValues: ['exception_request_id' => $requestId, 'status' => 'pending'], newValues: ['exception_decision_id' => $decision->id, 'status' => $decision->decision], metadata: ['reason' => $input['reason'] ?? null, 'laboratory_order_id' => $orderId]));

            return ['clinical_revision' => $order->fresh()->clinical_revision, 'exception' => $request->fresh()->toArray(), 'decision' => $decision->toArray()];
        });
    }

    public function finalizeLine(Laboratory $lab, User $actor, int $orderId, int $lineId, array $input): array
    {
        $this->input($input, ['clinical_revision', 'result_revision']);

        return DB::transaction(function () use ($lab, $actor, $orderId, $lineId, $input) {
            [$order,$line] = $this->locked($lab, $actor, $orderId, $lineId, $input['clinical_revision'], 'orders.exams.clinical_finalize');
            $this->unresolved($line);
            if ($line->clinicalExceptions->contains('status', 'pending')) {
                throw new ClinicalConflict('clinical_exception_pending', 'An active exception must be decided before line finalization.');
            }
            $capture = $line->resultCapture;
            if ($order->status !== 'in_process' || $line->processing_started_at === null || $capture?->status !== 'validated' || $capture->lastValidation === null || $capture->lastValidation->transition_revision !== $capture->revision) {
                throw new ClinicalConflict('clinical_line_not_validated', 'Explicitly started processing and a current exact validation are required.');
            }
            if ($capture->revision !== $input['result_revision']) {
                throw new ClinicalConflict('result_revision_conflict', 'Reload the current validated revision.');
            }
            $resolution = ClinicalLineResolution::create(['laboratory_id' => $lab->id, 'laboratory_order_id' => $orderId, 'laboratory_order_exam_id' => $lineId, 'resolution_type' => 'validated_result',
                'validation_id' => $capture->last_validation_id, 'exception_decision_id' => null, 'resolved_by' => $actor->id, 'resolved_at' => now(),
                'evidence_snapshot' => $this->lineEvidence($line) + ['validation_id' => $capture->last_validation_id, 'approved_revision' => $capture->lastValidation->approved_revision, 'validation_policy' => $capture->lastValidation->validation_policy]]);
            $this->audit->record($lab, $actor, new AuditEvent('order_clinical.line_finalized', 'laboratory_order_exam', $lineId, newValues: ['resolution_id' => $resolution->id, 'resolution_type' => 'validated_result'], metadata: ['validation_id' => $capture->last_validation_id, 'result_revision' => $capture->revision, 'laboratory_order_id' => $orderId]));

            return ['clinical_revision' => $order->fresh()->clinical_revision, 'resolution' => $resolution->toArray()];
        });
    }

    public function exceptions(Laboratory $lab, User $actor, int $orderId, int $lineId): array
    {
        $this->authority->permission($lab, $actor, 'orders.clinical_exceptions.view');
        LaboratoryOrder::forLaboratory($lab)->whereKey($orderId)->firstOrFail();
        LaboratoryOrderExam::forLaboratory($lab)->where('laboratory_order_id', $orderId)->whereKey($lineId)->firstOrFail();
        $page = ClinicalExceptionRequest::forLaboratory($lab)->where('laboratory_order_exam_id', $lineId)->orderByDesc('id')->paginate(50);
        $decisions = ClinicalExceptionDecision::forLaboratory($lab)->whereIn('exception_request_id', $page->pluck('id'))->get()->keyBy('exception_request_id');

        return ['data' => $page->map(fn ($r) => $r->toArray() + ['decision' => $decisions->get($r->id)?->toArray()])->all(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => 50, 'total' => $page->total()]];
    }

    public function finalizeOrder(Laboratory $lab, User $actor, int $orderId, array $input): array
    {
        $this->input($input, ['clinical_revision']);

        return DB::transaction(function () use ($lab, $actor, $orderId, $input) {
            $order = LaboratoryOrder::forLaboratory($lab)->whereKey($orderId)->lockForUpdate()->firstOrFail();
            Laboratory::whereKey($lab->id)->where('is_active', true)->sharedLock()->firstOrFail();
            $this->authority->permission($lab, $actor, 'orders.clinical_finalize', true);
            if (ClinicalOrderFinalization::forLaboratory($lab)->where('laboratory_order_id', $orderId)->exists()) {
                throw new ClinicalConflict('clinical_order_finalized', 'An immutable finalization already exists.');
            }
            if (! in_array($order->status, ['pending', 'in_process'], true)) {
                throw new ClinicalConflict('clinical_order_not_open', 'Only open orders can be finalized.');
            }
            if ($order->clinical_revision !== $input['clinical_revision']) {
                throw new ClinicalConflict('clinical_revision_conflict', 'Reload clinical composition and resolutions.');
            }
            $lines = $order->orderExams()->orderBy('id')->lockForUpdate()->with(['clinicalResolution', 'clinicalExceptions'])->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['clinical_revision' => 'An empty order cannot be finalized.']);
            }
            if ($lines->contains(fn ($l) => $l->clinicalResolution === null || $l->clinicalExceptions->contains('status', 'pending'))) {
                throw new ClinicalConflict('clinical_order_unresolved', 'Every line requires a definitive clinical resolution with no pending exceptions.');
            }
            if ($order->status === 'pending' && $lines->contains(fn ($l) => $l->clinicalResolution->resolution_type !== 'authorized_exception')) {
                throw new ClinicalConflict('clinical_order_not_open', 'Closing from pending requires authorized exceptions for absolutely every line.');
            }
            $snapshot = self::closureSnapshot($lines);
            $evidence = ClinicalOrderFinalization::create(['laboratory_id' => $lab->id, 'laboratory_order_id' => $orderId, 'clinical_revision' => $order->clinical_revision, 'finalized_by' => $actor->id, 'finalized_at' => now(), 'lines_snapshot' => $snapshot]);
            $old = $order->status;
            $order->update(['status' => 'completed', 'clinical_revision' => $order->clinical_revision + 1]);
            $this->audit->record($lab, $actor, new AuditEvent('order_clinical.finalized', 'laboratory_order', $orderId, oldValues: ['status' => $old, 'clinical_revision' => $input['clinical_revision']], newValues: ['status' => 'completed', 'clinical_revision' => $order->clinical_revision], metadata: ['finalization_id' => $evidence->id, 'resolution_ids' => array_column($snapshot, 'resolution_id')]));
            $this->audit->record($lab, $actor, new AuditEvent('order.status_changed', 'laboratory_order', $orderId, oldValues: ['status' => $old], newValues: ['status' => 'completed']));

            return ['clinical_revision' => $order->clinical_revision, 'status' => 'completed', 'finalization' => self::finalizationMetadata($evidence)];
        });
    }

    public function show(Laboratory $lab, User $actor, int $orderId): array
    {
        $this->authority->permission($lab, $actor, 'orders.clinical_finalization.view');

        return DB::transaction(function () use ($lab, $actor, $orderId) {
            $order = LaboratoryOrder::forLaboratory($lab)->whereKey($orderId)->sharedLock()->with(['clinicalFinalization', 'orderExams.clinicalResolution:id,laboratory_order_exam_id,resolution_type,validation_id,exception_decision_id,resolved_by,resolved_at', 'orderExams.clinicalExceptions:id,laboratory_order_exam_id,status', 'orderExams.resultCapture:id,laboratory_order_exam_id,revision,status,last_validation_id', 'orderExams.resultCapture.lastValidation:id,transition_revision'])->firstOrFail();
            $progress = self::progress($order, $order->orderExams);
            $progress['can_finalize'] = $progress['eligible'] && $actor->can('orders.clinical_finalize');

            return ['laboratory_order_id' => $orderId, 'clinical_revision' => $order->clinical_revision, 'status' => $order->status, 'clinical_finalization' => $progress,
                'finalization' => $order->clinicalFinalization === null ? null : self::finalizationMetadata($order->clinicalFinalization),
                'lines' => $order->orderExams->map(fn ($l) => ['id' => $l->id, 'clinical_configuration_source' => $l->clinical_configuration_source ?? 'historical', 'clinical_version_id' => $l->clinical_version_id,
                    'resolution' => $l->clinicalResolution === null ? null : ['id' => $l->clinicalResolution->id, 'resolution_type' => $l->clinicalResolution->resolution_type, 'validation_id' => $l->clinicalResolution->validation_id, 'exception_decision_id' => $l->clinicalResolution->exception_decision_id, 'resolved_by' => $l->clinicalResolution->resolved_by, 'resolved_at' => $l->clinicalResolution->resolved_at->toISOString()],
                    'pending_exception_ids' => $l->clinicalExceptions->where('status', 'pending')->pluck('id')->all(),
                    'result_revision' => $l->resultCapture?->revision ?? 0,
                    'can_finalize' => $l->clinicalResolution === null && $order->status === 'in_process' && $l->processing_started_at !== null && ! $l->clinicalExceptions->contains('status', 'pending') && $l->resultCapture?->status === 'validated' && $l->resultCapture?->revision === $l->resultCapture?->lastValidation?->transition_revision && $actor->can('orders.exams.clinical_finalize'),
                    'can_request_exception' => $l->clinicalResolution === null && in_array($order->status, ['pending', 'in_process'], true) && ! $l->clinicalExceptions->contains('status', 'pending') && $l->resultCapture?->last_validation_id === null && $actor->can('orders.clinical_exceptions.request')])->all()];
        });
    }

    public static function progress(LaboratoryOrder $order, Collection $lines): array
    {
        $total = $lines->count();
        $resolved = $lines->filter(fn ($l) => $l->clinicalResolution !== null);
        $validated = $resolved->where('clinicalResolution.resolution_type', 'validated_result')->count();
        $exceptions = $resolved->count() - $validated;
        $pendingRequests = $lines->sum(fn ($l) => $l->clinicalExceptions->where('status', 'pending')->count());
        $eligible = $total > 0 && $resolved->count() === $total && $pendingRequests === 0 && ($order->status === 'in_process' || ($order->status === 'pending' && $exceptions === $total));

        $blockers = [];
        if ($total === 0) {
            $blockers[] = 'empty_order';
        }
        if ($total > $resolved->count()) {
            $blockers[] = 'unresolved_lines';
        }
        if ($pendingRequests > 0) {
            $blockers[] = 'pending_exceptions';
        }
        if ($order->status === 'cancelled') {
            $blockers[] = 'order_cancelled';
        }
        if ($order->status === 'completed' && $order->clinicalFinalization === null) {
            $blockers[] = 'historical_completed_without_clinical_evidence';
        }

        return ['blocking_reasons' => $blockers, 'status' => $order->clinicalFinalization !== null ? 'finalized' : ($order->status === 'completed' ? 'historical' : ($order->status === 'cancelled' ? 'cancelled' : 'in_progress')), 'total_lines' => $total, 'resolved_lines' => $resolved->count(), 'validated_lines' => $validated,
            'exception_lines' => $exceptions, 'pending_lines' => $total - $resolved->count(), 'pending_exceptions' => $pendingRequests, 'eligible' => $eligible, 'clinical_revision' => $order->clinical_revision];
    }

    private static function closureSnapshot(Collection $lines): array
    {
        return $lines->map(fn ($l) => ['line_id' => $l->id, 'laboratory_exam_id' => $l->laboratory_exam_id, 'exam_code' => $l->exam_code, 'exam_name' => $l->exam_name,
            'clinical_version_id' => $l->clinical_version_id, 'clinical_configuration_source' => $l->clinical_configuration_source ?? 'historical', 'resolution_id' => $l->clinicalResolution->id,
            'resolution_type' => $l->clinicalResolution->resolution_type, 'validation_id' => $l->clinicalResolution->validation_id, 'exception_decision_id' => $l->clinicalResolution->exception_decision_id])->all();
    }

    private static function finalizationMetadata(ClinicalOrderFinalization $f): array
    {
        return ['id' => $f->id, 'finalized_by' => $f->finalized_by, 'finalized_at' => $f->finalized_at->toISOString(), 'clinical_revision' => $f->clinical_revision, 'lines_snapshot' => $f->lines_snapshot];
    }

    private function locked(Laboratory $lab, User $actor, int $orderId, int $lineId, int $revision, string $permission): array
    {
        $order = LaboratoryOrder::forLaboratory($lab)->whereKey($orderId)->lockForUpdate()->firstOrFail();
        $line = LaboratoryOrderExam::forLaboratory($lab)->where('laboratory_order_id', $orderId)->whereKey($lineId)->lockForUpdate()->with(['clinicalVersion', 'resultCapture.lastValidation', 'clinicalResolution', 'clinicalExceptions'])->firstOrFail();
        Laboratory::whereKey($lab->id)->where('is_active', true)->sharedLock()->firstOrFail();
        $this->authority->permission($lab, $actor, $permission, true);
        if (! in_array($order->status, ['pending', 'in_process'], true) || ClinicalOrderFinalization::forLaboratory($lab)->where('laboratory_order_id', $orderId)->exists()) {
            throw new ClinicalConflict('clinical_order_not_open', 'Clinical operations require an open order.');
        }
        if ($order->clinical_revision !== $revision) {
            throw new ClinicalConflict('clinical_revision_conflict', 'Reload clinical composition and resolutions before continuing.');
        }

        return [$order, $line];
    }

    private function unresolved(LaboratoryOrderExam $line): void
    {
        if ($line->clinicalResolution !== null) {
            throw new ClinicalConflict('clinical_line_resolved', 'This line already has an immutable clinical resolution.');
        }
    }

    private function lineEvidence(LaboratoryOrderExam $line): array
    {
        return ['laboratory_exam_id' => $line->laboratory_exam_id, 'clinical_version_id' => $line->clinical_version_id,
            'clinical_configuration_source' => $line->clinical_configuration_source ?? 'historical', 'exam_code' => $line->exam_code, 'exam_name' => $line->exam_name,
            'processing_started_at' => $line->processing_started_at?->toISOString(), 'result_revision' => $line->resultCapture?->revision ?? 0];
    }

    private function input(array $input, array $revisions, array $extra = []): void
    {
        $rules = $extra;
        foreach ($revisions as $field) {
            $rules[$field] = ['required', 'integer', 'min:0'];
        }
        Validator::make($input, $rules)->validate();
        foreach ($revisions as $field) {
            if (! is_int($input[$field])) {
                throw ValidationException::withMessages([$field => 'Use a JSON integer revision.']);
            }
        }
        if (array_diff(array_keys($input), array_keys($rules)) !== [] || (isset($input['reason']) && trim($input['reason']) === '')) {
            throw ValidationException::withMessages(['input' => 'Only documented fields and nonblank reasons are accepted.']);
        }
    }
}

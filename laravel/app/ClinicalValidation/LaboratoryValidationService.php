<?php

namespace App\ClinicalValidation;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\ClinicalExams\ClinicalConflict;
use App\ClinicalFinalization\ClinicalFinalizationGuard;
use App\ClinicalResults\LaboratoryResultService;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Models\LaboratoryResultRectification;
use App\Models\LaboratoryResultValidation;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class LaboratoryValidationService
{
    public function __construct(private AuditWriter $audit, private ValidationAuthority $authority, private ReferenceResolver $resolver) {}

    public function validate(Laboratory $lab, User $actor, int $orderId, int $lineId, array $input): array
    {
        $this->input($input, false);
        $this->authority->permission($lab, $actor, 'results.validate');

        return DB::transaction(function () use ($lab, $actor, $orderId, $lineId, $input) {
            [$order,$line] = $this->locked($lab, $orderId, $lineId);
            $identity = $this->authority->clinical($lab, $actor, 'results.validate');
            $this->available($order, $line, $input['revision']);
            ClinicalFinalizationGuard::line($order, $line, true);
            if ($line->resultCapture->status !== 'preliminary') {
                throw new ClinicalConflict('result_already_validated', 'The current capture has already been validated.');
            }
            if (! LaboratoryResultService::progress($line)['complete']) {
                throw new ClinicalConflict('result_validation_incomplete', 'All published parameters must contain valid results.');
            }
            $patient = Patient::forLaboratory($lab)->whereKey($order->patient_id)->sharedLock()->firstOrFail();
            $resolution = $this->resolver->resolve($line->clinicalVersion->configuration['parameters'], $patient, $input['reference_context'], $actor->id);
            if ($resolution['blocked']) {
                $errors = [];
                foreach ($resolution['references'] as $reference) {
                    if (in_array($reference['status'], ['insufficient_context', 'ambiguous', 'conflicting_context'], true)) {
                        $errors['reference_context.'.$reference['parameter_code']] = [$reference];
                    }
                }
                throw new ClinicalConflict('result_reference_unresolved', 'Relevant clinical references require sufficient, unambiguous context.', $errors);
            }
            $values = $line->results->keyBy('parameter_code');
            $snapshot = array_map(function ($p) use ($values) {
                $r = $values->get($p['code']);

                return ['parameter_code' => $p['code'], 'parameter_snapshot' => $p, 'value_type' => $r->value_type,
                    'value' => match ($r->value_type) {
                        'numeric' => $r->numeric_value,'text' => $r->text_value,default => $r->option_code
                    },
                    'unit' => $r->unit, 'option_label' => $r->option_label, 'captured_by' => $r->captured_by, 'captured_at' => $r->captured_at->utc()->format('Y-m-d\TH:i:s.u\Z')];
            }, $line->clinicalVersion->configuration['parameters']);
            $evidence = LaboratoryResultValidation::create($identity + [
                'laboratory_id' => $lab->id, 'laboratory_order_id' => $orderId, 'laboratory_order_exam_id' => $lineId, 'laboratory_exam_id' => $line->laboratory_exam_id, 'clinical_version_id' => $line->clinical_version_id,
                'approved_revision' => $input['revision'], 'transition_revision' => $input['revision'] + 1, 'results_snapshot' => $snapshot,
                'reference_snapshot' => $resolution['references'], 'context_snapshot' => $resolution['context'], 'validated_by' => $actor->id, 'validated_at' => now(), 'comment' => $input['comment'] ?? null]);
            $line->resultCapture->update(['status' => 'validated', 'revision' => $input['revision'] + 1, 'last_validation_id' => $evidence->id]);
            $this->audit->record($lab, $actor, new AuditEvent('order_results.validated', 'laboratory_order_exam', $lineId,
                oldValues: ['revision' => $input['revision'], 'clinical_status' => 'preliminary'], newValues: ['revision' => $input['revision'] + 1, 'clinical_status' => 'validated'],
                metadata: ['validation_id' => $evidence->id, 'approved_revision' => $input['revision'], 'validation_policy' => $identity['validation_policy'], 'professional_id' => $identity['professional_id'], 'laboratory_order_id' => $orderId, 'clinical_version_id' => $line->clinical_version_id]));

            return $this->response($line->refresh(), $actor);
        });
    }

    public function rectify(Laboratory $lab, User $actor, int $orderId, int $lineId, array $input): array
    {
        $this->input($input, true);
        $this->authority->permission($lab, $actor, 'results.rectify');

        return DB::transaction(function () use ($lab, $actor, $orderId, $lineId, $input) {
            [$order, $line] = $this->locked($lab, $orderId, $lineId);
            $identity = $this->authority->clinical($lab, $actor, 'results.rectify');
            $this->available($order, $line, $input['revision']);
            ClinicalFinalizationGuard::line($order, $line, true);
            if ($line->resultCapture->status !== 'validated') {
                throw new ClinicalConflict('result_rectification_unavailable', 'Rectification requires a currently validated capture.');
            }
            $rectification = LaboratoryResultRectification::create($identity + ['laboratory_id' => $lab->id, 'laboratory_order_exam_id' => $lineId,
                'validation_id' => $line->resultCapture->last_validation_id, 'from_revision' => $input['revision'], 'to_revision' => $input['revision'] + 1,
                'requested_by' => $actor->id, 'requested_at' => now(), 'reason' => $input['reason']]);
            $line->resultCapture->update(['status' => 'preliminary', 'revision' => $input['revision'] + 1]);
            $this->audit->record($lab, $actor, new AuditEvent('order_results.rectified', 'laboratory_order_exam', $lineId,
                oldValues: ['revision' => $input['revision'], 'clinical_status' => 'validated'], newValues: ['revision' => $input['revision'] + 1, 'clinical_status' => 'preliminary'],
                metadata: ['rectification_id' => $rectification->id, 'validation_id' => $rectification->validation_id, 'reason' => $input['reason'], 'validation_policy' => $identity['validation_policy'], 'professional_id' => $identity['professional_id']]));

            return $this->response($line->refresh(), $actor);
        });
    }

    public function history(Laboratory $lab, User $actor, int $orderId, int $lineId): array
    {
        $this->authority->permission($lab, $actor, 'results.validation_history');
        LaboratoryOrder::forLaboratory($lab)->whereKey($orderId)->firstOrFail();
        LaboratoryOrderExam::forLaboratory($lab)->where('laboratory_order_id', $orderId)->whereKey($lineId)->firstOrFail();
        $page = LaboratoryResultValidation::forLaboratory($lab)->where('laboratory_order_exam_id', $lineId)->orderByDesc('id')->paginate(50);
        $rectifications = LaboratoryResultRectification::forLaboratory($lab)->whereIn('validation_id', $page->pluck('id'))->get()->groupBy('validation_id');

        return ['data' => $page->map(fn ($v) => $v->toArray() + ['rectifications' => ($rectifications->get($v->id) ?? collect())->toArray()])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => 50, 'total' => $page->total()]];
    }

    private function locked(Laboratory $lab, int $orderId, int $lineId): array
    {
        $order = LaboratoryOrder::forLaboratory($lab)->whereKey($orderId)->lockForUpdate()->firstOrFail();
        $line = LaboratoryOrderExam::forLaboratory($lab)->where('laboratory_order_id', $orderId)->whereKey($lineId)->lockForUpdate()->with(['clinicalVersion', 'results', 'resultCapture'])->firstOrFail();

        return [$order, $line];
    }

    private function available(LaboratoryOrder $order, LaboratoryOrderExam $line, int $revision): void
    {
        if ($order->status !== 'in_process' || $line->processing_started_at === null || $line->clinicalVersion?->status !== 'published' || $line->resultCapture === null) {
            throw new ClinicalConflict('result_validation_unavailable', 'An explicitly started, published line with captured results in an order in process is required.');
        }
        if ($line->resultCapture->revision !== $revision) {
            throw new ClinicalConflict('result_revision_conflict', 'Reload the current capture revision.');
        }

    }

    private function input(array $input, bool $rectify): void
    {
        $rules = ['revision' => ['required', 'integer', 'min:1']];
        if ($rectify) {
            $rules['reason'] = ['required', 'string', 'max:2000'];
        } else {
            $rules += ['comment' => ['sometimes', 'nullable', 'string', 'max:1000'],
                'reference_context' => ['required', 'array:reference_date,sex,conditions,confirm_birth_date'],
                'reference_context.reference_date' => ['required', 'date_format:Y-m-d'],
                'reference_context.sex' => ['sometimes', 'nullable', 'string', 'max:60'],
                'reference_context.confirm_birth_date' => ['sometimes', 'boolean'],
                'reference_context.conditions' => ['sometimes', 'array', 'max:30'],
                'reference_context.conditions.*' => ['nullable', 'string', 'max:150']];
        }
        Validator::make($input, $rules)->validate();
        if (! is_int($input['revision']) || array_diff(array_keys($input), array_keys($rules)) !== [] || ($rectify && trim($input['reason']) === '')) {
            throw ValidationException::withMessages(['revision' => 'Use an integer revision and only documented fields, with a nonblank reason for rectification.']);
        }
        foreach (array_keys($input['reference_context']['conditions'] ?? []) as $key) {
            if (! is_string($key) || ! preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,59}$/', $key)) {
                throw ValidationException::withMessages(['reference_context.conditions' => 'Use named condition keys.']);
            }
        }
    }

    private function response(LaboratoryOrderExam $line, User $actor): array
    {
        $line->load(['clinicalVersion', 'results', 'resultCapture.lastValidation']);
        $v = $line->resultCapture->lastValidation;

        return ['clinical_status' => $line->resultCapture->status, 'revision' => $line->resultCapture->revision, 'validated_revision' => $v?->approved_revision,
            'validation' => $v === null ? null : ['id' => $v->id, 'validated_by' => $v->validated_by, 'professional_id' => $v->professional_id, 'validation_policy' => $v->validation_policy, 'validated_at' => $v->validated_at->toISOString()],
            'progress' => LaboratoryResultService::progress($line),
            'permissions' => ['can_validate' => $line->resultCapture->status === 'preliminary' && $actor->can('results.validate'),
                'can_rectify' => $line->resultCapture->status === 'validated' && $actor->can('results.rectify'),
                'can_capture' => $line->resultCapture->status === 'preliminary' && $actor->can('results.capture'),
                'can_edit' => $line->resultCapture->status === 'preliminary' && $actor->can('results.capture') && $actor->can('results.edit'),
                'can_view_validations' => $actor->can('results.validation_history')]];
    }
}

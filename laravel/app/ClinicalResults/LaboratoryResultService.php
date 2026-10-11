<?php

namespace App\ClinicalResults;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\ClinicalExams\ClinicalConflict;
use App\Models\AuditLog;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Models\LaboratoryOrderResult;
use App\Models\LaboratoryOrderResultCapture;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class LaboratoryResultService
{
    public function __construct(private AuditWriter $audit) {}

    public static function inputRules(): array
    {
        return ['revision' => ['required', 'integer', 'min:0'], 'results' => ['required', 'array', 'min:1', 'max:100'],
            'results.*' => ['required', 'array:parameter_code,value'], 'results.*.parameter_code' => ['required', 'string', 'max:60', 'distinct:strict'],
            'results.*.value' => ['present'], 'comment' => ['sometimes', 'nullable', 'string', 'max:1000']];
    }

    public function show(Laboratory $lab, int $orderId, int $lineId): array
    {
        return DB::transaction(function () use ($lab, $orderId, $lineId) {
            $order = LaboratoryOrder::forLaboratory($lab)->whereKey($orderId)->sharedLock()->firstOrFail();
            $line = $this->line($lab, $orderId, $lineId);

            return $this->document($order, $line);
        });
    }

    public function history(Laboratory $lab, int $orderId, int $lineId): array
    {
        LaboratoryOrder::forLaboratory($lab)->whereKey($orderId)->firstOrFail();
        $this->line($lab, $orderId, $lineId);
        $page = AuditLog::forLaboratory($lab)->where('event', 'order_results.saved')->where('auditable_type', 'laboratory_order_exam')->where('auditable_id', $lineId)->orderByDesc('id')->paginate(50);

        return ['data' => $page->map(fn ($event) => ['id' => $event->id, 'actor_id' => $event->user_id, 'occurred_at' => $event->created_at->toISOString(),
            'before' => $event->old_values, 'after' => $event->new_values, 'context' => $event->metadata])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => 50, 'total' => $page->total()]];
    }

    public function save(Laboratory $lab, User $actor, int $orderId, int $lineId, array $input): array
    {
        $this->authorize($lab, $actor, 'results.capture');
        Validator::make($input, self::inputRules())->validate();
        $this->checkStructure($input);

        return DB::transaction(function () use ($lab, $actor, $orderId, $lineId, $input) {
            $order = LaboratoryOrder::forLaboratory($lab)->whereKey($orderId)->lockForUpdate()->firstOrFail();
            // Recheck authorization after a possible wait on the order lock.
            $this->authorize($lab, $actor, 'results.capture');
            $line = $this->line($lab, $orderId, $lineId, true);
            if ($order->status !== 'in_process' || $line->processing_started_at === null || $line->clinicalVersion?->status !== 'published') {
                throw new ClinicalConflict('result_capture_unavailable', 'Capture requires an explicitly started line with its published version in an order in process.');
            }
            $capture = $line->resultCapture;
            $revision = $capture?->revision ?? 0;
            if ($input['revision'] !== $revision) {
                throw new ClinicalConflict('result_revision_conflict', 'The capture revision has changed. Reload the line before saving.');
            }
            if ($capture !== null && $capture->status !== 'preliminary') {
                throw new ClinicalConflict('result_not_editable', 'Only preliminary results may be edited.');
            }
            $parameters = collect($line->clinicalVersion->configuration['parameters'])->keyBy('code');
            $existing = $line->results->keyBy('parameter_code');
            $changes = [];
            $needsEdit = false;
            $canView = Gate::forUser($actor)->allows('results.view');
            foreach ($input['results'] as $i => $entry) {
                $parameter = $parameters->get($entry['parameter_code']);
                if ($parameter === null) {
                    throw ValidationException::withMessages(["results.$i.parameter_code" => 'The parameter does not belong to this line’s fixed published version.']);
                }
                $value = $this->normalize($parameter, $entry['value'], "results.$i.value");
                $previous = $existing->get($entry['parameter_code']);
                $before = $previous ? $this->value($previous) : null;
                if ($previous !== null) {
                    $needsEdit = true;
                }
                // Hidden values must not be discoverable through no-op revision comparisons.
                if ($before === $value && ($previous === null || $canView)) {
                    continue;
                }
                $changes[] = ['parameter' => $parameter, 'previous' => $previous, 'before' => $before, 'after' => $value];
            }
            if ($needsEdit) {
                $this->authorize($lab, $actor, 'results.edit');
            }
            if ($changes === []) {
                return $this->document($order, $line);
            }
            $owners = ['laboratory_id' => $lab->id, 'laboratory_order_id' => $order->id, 'laboratory_order_exam_id' => $line->id,
                'laboratory_exam_id' => $line->laboratory_exam_id, 'clinical_version_id' => $line->clinical_version_id];
            $stamp = ['captured_by' => $actor->id, 'captured_at' => now()];
            if ($capture === null) {
                $capture = LaboratoryOrderResultCapture::create($owners + $stamp + ['revision' => 1, 'status' => 'preliminary']);
            } else {
                $capture->update($stamp + ['revision' => $revision + 1]);
            }
            foreach ($changes as $change) {
                $parameter = $change['parameter'];
                if ($change['after'] === null) {
                    $change['previous']->delete();

                    continue;
                }
                $attributes = $stamp + ['capture_revision' => $capture->revision, 'numeric_value' => null, 'text_value' => null, 'option_code' => null,
                    'option_label' => null, 'unit' => $parameter['type'] === 'numeric' ? ($parameter['unit'] ?? null) : null];
                $attributes[match ($parameter['type']) {
                    'numeric' => 'numeric_value', 'text' => 'text_value', default => 'option_code'
                }] = $change['after'];
                if (in_array($parameter['type'], ['qualitative', 'predefined_options'], true)) {
                    $attributes['option_label'] = collect($parameter['options'])->first(fn ($option) => $option['code'] === $change['after'])['label'];
                }
                if ($change['previous'] !== null) {
                    $change['previous']->update($attributes);
                } else {
                    LaboratoryOrderResult::create($owners + $attributes + ['parameter_code' => $parameter['code'], 'value_type' => $parameter['type'], 'parameter_snapshot' => $parameter]);
                }
            }
            $snapshot = fn (string $side) => array_map(fn ($change) => ['parameter_code' => $change['parameter']['code'], 'type' => $change['parameter']['type'],
                'value' => $change[$side], 'unit' => $change['parameter']['type'] === 'numeric' ? ($change['parameter']['unit'] ?? null) : null,
                'option_label' => in_array($change['parameter']['type'], ['qualitative', 'predefined_options'], true) && $change[$side] !== null
                    ? collect($change['parameter']['options'])->first(fn ($option) => $option['code'] === $change[$side])['label'] : null], $changes);
            $this->audit->record($lab, $actor, new AuditEvent('order_results.saved', 'laboratory_order_exam', $line->id,
                oldValues: ['revision' => $revision, 'results' => $snapshot('before')], newValues: ['revision' => $capture->revision, 'results' => $snapshot('after')],
                metadata: ['laboratory_order_id' => $order->id, 'clinical_version_id' => $line->clinical_version_id, 'comment' => $input['comment'] ?? null]));
            $line->load(['resultCapture', 'results']);

            return $this->document($order, $line);
        });
    }

    public static function progress(LaboratoryOrderExam $line): array
    {
        $parameters = $line->clinicalVersion?->configuration['parameters'] ?? [];
        $codes = $line->results->pluck('parameter_code')->all();
        $pending = array_values(array_filter(array_column($parameters, 'code'), fn ($code) => ! in_array($code, $codes, true)));
        $total = count($parameters);
        $filled = $total - count($pending);

        return ['revision' => $line->resultCapture?->revision ?? 0, 'status' => $filled === 0 ? 'empty' : ($pending === [] ? 'complete' : 'partial'),
            'clinical_status' => 'preliminary', 'required_count' => $total, 'captured_count' => $filled, 'pending_count' => count($pending), 'complete' => $total > 0 && $pending === [], 'pending_parameters' => $pending, 'validation_errors' => []];
    }

    private function line(Laboratory $lab, int $orderId, int $lineId, bool $lock = false): LaboratoryOrderExam
    {
        $query = LaboratoryOrderExam::forLaboratory($lab)->where('laboratory_order_id', $orderId)->whereKey($lineId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->with(['clinicalVersion', 'resultCapture', 'results'])->firstOrFail();
    }

    private function document(LaboratoryOrder $order, LaboratoryOrderExam $line): array
    {
        $values = $line->results->keyBy('parameter_code');
        $available = $order->status === 'in_process' && $line->processing_started_at !== null && $line->clinicalVersion?->status === 'published';

        return ['laboratory_order_id' => $order->id, 'laboratory_order_exam_id' => $line->id, 'laboratory_exam_id' => $line->laboratory_exam_id,
            'exam_code' => $line->exam_code, 'exam_name' => $line->exam_name, 'clinical_version_id' => $line->clinical_version_id,
            'clinical_version_number' => $line->clinicalVersion?->number, 'configuration_hash' => $line->clinicalVersion?->configuration_hash,
            'processing_started_at' => $line->processing_started_at?->toISOString(), 'capture_available' => $available,
            'blocking_reasons' => $available ? [] : ['explicit_started_published_line_in_processing_order_required'],
            'progress' => self::progress($line), 'parameters' => array_map(function ($parameter) use ($values) {
                $row = $values->get($parameter['code']);

                return ['configuration' => $parameter, 'required' => true, 'value' => $row ? $this->value($row) : null,
                    'unit' => $parameter['type'] === 'numeric' ? ($parameter['unit'] ?? null) : null,
                    'option_label' => $row?->option_label, 'captured_by' => $row?->captured_by, 'captured_at' => $row?->captured_at?->toISOString()];
            }, $line->clinicalVersion?->configuration['parameters'] ?? [])];
    }

    private function value(LaboratoryOrderResult $row): string
    {
        return match ($row->value_type) {
            'numeric' => $row->numeric_value, 'text' => $row->text_value, default => $row->option_code
        };
    }

    private function normalize(array $parameter, mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($parameter['type'] === 'numeric') {
            if ((is_string($value) || is_int($value)) && strlen((string) $value) <= 4096
                && preg_match('/\A[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d{1,4})?\z/', (string) $value)) {
                return DB::scalar('SELECT trim_scale(CAST(? AS numeric))::text', [(string) $value]);
            }
        } elseif ($parameter['type'] === 'text') {
            if (is_string($value) && trim($value) !== '' && mb_strlen($value) <= ($parameter['text_max_length'] ?? 100000)) {
                return $value;
            }
        } elseif (is_string($value) && in_array($value, array_column($parameter['options'], 'code'), true)) {
            return $value;
        }
        throw ValidationException::withMessages([$field => 'The value must satisfy the type and constraints of the fixed published parameter.']);
    }

    private function checkStructure(array $input): void
    {
        if (array_diff(array_keys($input), ['revision', 'results', 'comment']) !== [] || ! is_int($input['revision']) || ! array_is_list($input['results'])) {
            throw ValidationException::withMessages(['results' => 'Use a JSON list, an integer revision and only documented fields.']);
        }
    }

    private function authorize(Laboratory $lab, User $actor, string $permission): void
    {
        if ((int) getPermissionsTeamId() !== $lab->id || ! User::whereKey($actor->id)->where('is_active', true)->exists()
            || ! $actor->laboratories()->whereKey($lab->id)->wherePivot('is_active', true)->exists()) {
            abort(403, 'An active actor and membership in the current laboratory are required.');
        }
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        Gate::forUser($actor)->authorize($permission);
    }
}

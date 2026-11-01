<?php

namespace App\ClinicalReplacements;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\ClinicalExams\ClinicalConflict;
use App\ClinicalExams\LaboratorySampleService;
use App\ClinicalExams\SampleCompatibility;
use App\ClinicalFinalization\ClinicalFinalizationGuard;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ClinicalReplacementService
{
    public const EVIDENCE_FIELDS = [
        'sample_problem' => ['problem'],
        'patient_refusal' => ['express_refusal', 'observations'],
        'follow_up' => ['action', 'observations'],
        'medical_instruction' => ['instruction'],
        'technical_problem' => ['problem'],
        'alternatives' => ['alternatives_considered'],
    ];

    public function __construct(private ReplacementAuthority $authority, private LaboratorySampleService $samples, private SampleCompatibility $compatibility, private AuditWriter $audit) {}

    public function createIntention(Laboratory $lab, User $actor, int $orderId, int $lineId, array $input): array
    {
        $data = $this->input($input, ['sample_requirement_id' => ['required', 'integer', 'min:1'], 'sample_id' => ['sometimes', 'nullable', 'integer', 'min:1'], 'reason' => $this->text(), 'justification' => $this->text()]);

        return $this->write($lab, $actor, $orderId, $lineId, $data, ReplacementAuthority::RECORD, function (array $base) use ($data): array {
            $id = DB::table('clinical_replacement_intents')->insertGetId($base + ['sample_requirement_id' => $data['sample_requirement_id'], 'sample_id' => $data['sample_id'] ?? null, 'reason' => $data['reason'], 'justification' => $data['justification']]);
            $this->event($base, $id, $data['sample_requirement_id'], $data['sample_id'] ?? null, 'opened', null, $data['justification']);

            return ['intention_id' => $id];
        });
    }

    public function recordEvidence(Laboratory $lab, User $actor, int $orderId, int $lineId, array $input): array
    {
        $rules = ['kind' => ['required', Rule::in(array_keys(self::EVIDENCE_FIELDS))], 'sample_requirement_id' => ['sometimes', 'nullable', 'integer', 'min:1'], 'sample_id' => ['sometimes', 'nullable', 'integer', 'min:1']];
        $fields = self::EVIDENCE_FIELDS[$input['kind'] ?? ''] ?? [];
        $rules['facts'] = ['required', 'array:'.implode(',', $fields)];
        foreach ($fields as $field) {
            $rules['facts.'.$field] = $field === 'express_refusal' ? ['required', 'accepted'] : $this->text();
        }
        $data = $this->input($input, $rules);
        if ($data['kind'] === 'patient_refusal' && $data['facts']['express_refusal'] !== true) {
            throw ValidationException::withMessages(['facts.express_refusal' => 'Use JSON true for an express refusal.']);
        }

        return $this->write($lab, $actor, $orderId, $lineId, $data, ReplacementAuthority::RECORD, function (array $base) use ($data): array {
            $id = DB::table('clinical_line_evidence')->insertGetId($base + ['sample_requirement_id' => $data['sample_requirement_id'] ?? null, 'sample_id' => $data['sample_id'] ?? null, 'kind' => $data['kind'], 'facts' => json_encode($data['facts'], JSON_THROW_ON_ERROR)]);

            return ['evidence_id' => $id];
        });
    }

    public function linkSample(Laboratory $lab, User $actor, int $orderId, int $lineId, int $intentionId, array $input): array
    {
        $data = $this->input($input, ['sample_id' => ['required', 'integer', 'min:1'], 'justification' => $this->text()]);

        return $this->write($lab, $actor, $orderId, $lineId, $data, ReplacementAuthority::RECORD, function (array $base) use ($lab, $actor, $orderId, $lineId, $intentionId, $data): array {
            $intention = $this->intention($lab, $orderId, $lineId, $intentionId);
            if ($intention->sample_id === $data['sample_id']) {
                throw new ClinicalConflict('replacement_sample_identity_conflict', 'A replacement requires a different physical sample.');
            }
            if (DB::table('clinical_replacement_links')->where('intention_id', $intentionId)->where('sample_id', $data['sample_id'])->exists()) {
                throw new ClinicalConflict('replacement_sample_already_linked', 'This material link already exists.');
            }
            // Existing BE05 owns compatibility, authorization, physical association and its events.
            $this->samples->associate($lab, $actor, $orderId, $data['sample_id'], ['sample_requirement_ids' => [$intention->sample_requirement_id]]);
            $sample = $this->samples->sample($lab, $orderId, $data['sample_id'], true);
            $candidate = $this->compatibility->candidates($lab, $sample)->firstWhere('sample_requirement_id', $intention->sample_requirement_id);
            if (! ($candidate['compatible'] ?? false)) {
                throw new ClinicalConflict('sample_requirement_incompatible', 'Replacement material must remain compatible with the affected requirement.');
            }
            $association = DB::table('laboratory_sample_associations')->where('laboratory_sample_id', $data['sample_id'])->where('sample_requirement_id', $intention->sample_requirement_id)->whereNull('released_at')->firstOrFail();
            $id = DB::table('clinical_replacement_links')->insertGetId($base + ['sample_requirement_id' => $intention->sample_requirement_id, 'sample_id' => $data['sample_id'], 'intention_id' => $intentionId, 'association_id' => $association->id]);
            $this->event($base, $intentionId, $intention->sample_requirement_id, $intention->sample_id, 'material_linked', $id, $data['justification']);

            return ['link_id' => $id];
        });
    }

    public function fulfill(Laboratory $lab, User $actor, int $orderId, int $lineId, int $intentionId, array $input): array
    {
        $data = $this->input($input, ['link_id' => ['required', 'integer', 'min:1'], 'justification' => $this->text()]);

        return $this->write($lab, $actor, $orderId, $lineId, $data, ReplacementAuthority::RECORD, function (array $base) use ($lab, $orderId, $lineId, $intentionId, $data): array {
            $intention = $this->intention($lab, $orderId, $lineId, $intentionId);
            $link = DB::table('clinical_replacement_links')->where('intention_id', $intentionId)->where('id', $data['link_id'])->firstOrFail();
            if (! $this->compatibility->qualifyingAssociations($lab, $orderId)->contains('id', $link->association_id)) {
                throw new ClinicalConflict('replacement_material_not_adequate', 'Fulfillment requires a currently adequate accepted association.');
            }
            $this->event($base, $intentionId, $intention->sample_requirement_id, $intention->sample_id, 'fulfilled', $link->id, $data['justification']);

            return ['intention_id' => $intentionId];
        });
    }

    public function assess(Laboratory $lab, User $actor, int $orderId, int $lineId, array $input): array
    {
        $data = $this->input($input, [
            'subject' => ['required', 'in:replacement,continuation,technical_alternatives'],
            'intention_id' => ['required_if:subject,replacement', 'prohibited_unless:subject,replacement', 'integer', 'min:1'],
            'expected_dependency_token' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'previous_id' => ['present', 'nullable', 'integer', 'min:1'], 'outcome' => ['required', 'in:viable,non_viable'],
            'justification' => $this->text(), 'evidence_ids' => ['required', 'array', 'list', 'min:1', 'max:100'], 'evidence_ids.*' => ['required', 'integer', 'min:1', 'distinct:strict'],
        ]);

        return $this->write($lab, $actor, $orderId, $lineId, $data, ReplacementAuthority::EVALUATE, function (array $base) use ($lab, $orderId, $lineId, $data): array {
            $intention = isset($data['intention_id']) ? $this->intention($lab, $orderId, $lineId, $data['intention_id']) : null;
            $prior = DB::table('clinical_viability_assessments')->where('laboratory_order_exam_id', $lineId)->where('subject', $data['subject'])->where('intention_id', $intention?->id)->orderByDesc('revision')->first();
            if (($prior?->id) !== $data['previous_id']) {
                throw new ClinicalConflict('viability_predecessor_conflict', 'Reload the latest assessment; predecessors cannot branch.');
            }
            $evidence = DB::table('clinical_line_evidence')->where('laboratory_id', $lab->id)->where('laboratory_order_exam_id', $lineId)->whereIn('id', $data['evidence_ids'])->get();
            if ($evidence->count() !== count($data['evidence_ids']) || ($intention && $evidence->contains(fn ($e) => ($e->sample_id !== null && $e->sample_id !== $intention->sample_id) || ($e->sample_requirement_id !== null && $e->sample_requirement_id !== $intention->sample_requirement_id)))) {
                throw ValidationException::withMessages(['evidence_ids' => 'Evidence must belong to the affected clinical context.']);
            }
            $requiredKinds = match ($data['subject']) {
                'replacement' => ['sample_problem'],
                'continuation' => ['follow_up'],
                'technical_alternatives' => ['technical_problem', 'alternatives'],
            };
            if (array_diff($requiredKinds, $evidence->pluck('kind')->all()) !== []) {
                throw ValidationException::withMessages(['evidence_ids' => 'The assessment requires the applicable structured problem, follow-up or alternatives evidence.']);
            }
            $dependencies = DB::scalar('SELECT clinical_replacement_dependencies(?)::text', [$lineId]);
            if (! hash_equals(hash('sha256', $dependencies), $data['expected_dependency_token'])) {
                throw new ClinicalConflict('viability_dependencies_conflict', 'The observed clinical dependencies changed; review them before assessing.');
            }
            $id = DB::table('clinical_viability_assessments')->insertGetId($base + ['sample_requirement_id' => $intention?->sample_requirement_id, 'sample_id' => $intention?->sample_id,
                'intention_id' => $intention?->id, 'subject' => $data['subject'], 'revision' => ($prior?->revision ?? 0) + 1, 'previous_id' => $prior?->id, 'outcome' => $data['outcome'],
                'justification' => $data['justification'], 'evidence_ids' => json_encode($data['evidence_ids'], JSON_THROW_ON_ERROR), 'dependency_snapshot' => $dependencies]);

            return ['assessment_id' => $id];
        });
    }

    /** Read-only consumer contract: no eligibility, exception approval or terminal side effects. */
    public function read(Laboratory $lab, User $actor, int $orderId, int $lineId, int $page = 1): array
    {
        if ($page < 1) {
            throw ValidationException::withMessages(['page' => 'Use a positive page.']);
        }

        return DB::transaction(function () use ($lab, $actor, $orderId, $lineId, $page): array {
            LaboratoryOrder::forLaboratory($lab)->whereKey($orderId)->sharedLock()->firstOrFail();
            LaboratoryOrderExam::forLaboratory($lab)->where('laboratory_order_id', $orderId)->whereKey($lineId)->sharedLock()->firstOrFail();
            $this->authority->check($lab, $actor, ReplacementAuthority::VIEW);
            $revision = (int) (DB::table('clinical_replacement_contexts')->where('laboratory_order_exam_id', $lineId)->value('revision') ?? 0);
            $tables = ['intentions' => 'clinical_replacement_intents', 'evidence' => 'clinical_line_evidence', 'links' => 'clinical_replacement_links', 'events' => 'clinical_replacement_events', 'assessments' => 'clinical_viability_assessments'];
            $result = ['revision' => $revision];
            foreach ($tables as $key => $table) {
                $paginator = DB::table($table)->where('laboratory_id', $lab->id)->where('laboratory_order_exam_id', $lineId)->orderByDesc('id')->paginate(50, ['*'], 'page', $page);
                $result[$key] = ['data' => $paginator->items(), 'meta' => ['current_page' => $page, 'last_page' => $paginator->lastPage(), 'per_page' => 50, 'total' => $paginator->total()]];
            }
            // Latest per subject, independent of history pagination; assessments are never inferred from prose.
            $latest = DB::table('clinical_viability_assessments as a')->where('a.laboratory_order_exam_id', $lineId)->whereNotExists(function ($q): void {
                $q->selectRaw('1')->from('clinical_viability_assessments as newer')->whereColumn('newer.laboratory_order_exam_id', 'a.laboratory_order_exam_id')->whereColumn('newer.subject', 'a.subject')->whereRaw('newer.intention_id IS NOT DISTINCT FROM a.intention_id')->whereColumn('newer.revision', '>', 'a.revision');
            })->select('a.*')->selectRaw('a.dependency_snapshot IS DISTINCT FROM clinical_replacement_dependencies(a.laboratory_order_exam_id) AS requires_review')->orderBy('a.id')->get();
            $result['latest_assessments'] = $latest->map(fn ($row) => $this->decode($row))->all();
            $adequate = $this->compatibility->qualifyingAssociations($lab, $orderId)->pluck('id');
            $states = DB::table('clinical_replacement_intents as i')->where('i.laboratory_order_exam_id', $lineId)
                ->leftJoin('clinical_replacement_events as e', 'e.id', '=', DB::raw('(SELECT max(id) FROM clinical_replacement_events WHERE intention_id=i.id)'))
                ->leftJoin('clinical_replacement_links as l', 'l.id', '=', 'e.link_id')
                ->select(['i.id', 'i.sample_requirement_id', 'i.sample_id', 'e.event', 'l.association_id'])->orderBy('i.id')->get();
            $result['intention_states'] = $states->map(function ($row) use ($adequate, $latest): array {
                $fulfilled = $row->event === 'fulfilled' && $adequate->contains($row->association_id);
                $evaluation = $latest->first(fn ($a) => $a->subject === 'replacement' && $a->intention_id === $row->id);

                return ['intention_id' => $row->id, 'status' => $fulfilled ? 'fulfilled' : 'pending', 'material_linked' => $row->association_id !== null,
                    'latest_assessment_id' => $evaluation?->id, 'current_outcome' => $evaluation && ! $evaluation->requires_review ? $evaluation->outcome : null,
                    'requires_review' => $evaluation === null || (bool) $evaluation->requires_review];
            })->all();
            foreach (array_keys($tables) as $key) {
                $result[$key]['data'] = array_map(fn ($row) => $this->decode($row), $result[$key]['data']);
            }
            $result['pending_viable_intention_ids'] = array_values(array_map(fn ($state) => $state['intention_id'], array_filter($result['intention_states'], fn ($state) => $state['status'] === 'pending' && $state['current_outcome'] === 'viable')));
            $result['review_required'] = collect($result['intention_states'])->contains('requires_review', true) || $latest->contains('requires_review', true);
            $result['dependency_token'] = hash('sha256', DB::scalar('SELECT clinical_replacement_dependencies(?)::text', [$lineId]));

            return $result;
        });
    }

    private function write(Laboratory $lab, User $actor, int $orderId, int $lineId, array $data, string $permission, \Closure $operation): array
    {
        try {
            return DB::transaction(function () use ($lab, $actor, $orderId, $lineId, $data, $permission, $operation): array {
                $order = LaboratoryOrder::forLaboratory($lab)->whereKey($orderId)->lockForUpdate()->firstOrFail();
                $line = LaboratoryOrderExam::forLaboratory($lab)->where('laboratory_order_id', $orderId)->whereKey($lineId)->lockForUpdate()->firstOrFail();
                $this->authority->check($lab, $actor, $permission);
                if (! in_array($order->status, ['pending', 'in_process'], true)) {
                    throw new ClinicalConflict('replacement_order_not_open', 'Replacement evidence requires an open order.');
                }
                ClinicalFinalizationGuard::line($order, $line);
                foreach (['sample_requirement_id', 'sample_id'] as $field) {
                    if (isset($data[$field])) {
                        $q = DB::table($field === 'sample_id' ? 'laboratory_samples' : 'laboratory_order_sample_requirements')->where('laboratory_id', $lab->id)->where('laboratory_order_id', $orderId)->where('id', $data[$field]);
                        if ($field === 'sample_requirement_id') {
                            $q->where('laboratory_order_exam_id', $lineId);
                        }
                        $q->firstOrFail();
                    }
                }
                DB::table('clinical_replacement_contexts')->insertOrIgnore(['laboratory_id' => $lab->id, 'laboratory_order_id' => $orderId, 'laboratory_order_exam_id' => $lineId, 'revision' => 0]);
                $context = DB::table('clinical_replacement_contexts')->where('laboratory_order_exam_id', $lineId)->lockForUpdate()->firstOrFail();
                if ($context->revision !== $data['expected_revision']) {
                    throw new ClinicalConflict('replacement_revision_conflict', 'Reload the replacement evidence revision.');
                }
                $base = ['laboratory_id' => $lab->id, 'laboratory_order_id' => $orderId, 'laboratory_order_exam_id' => $lineId, 'recorded_by' => $actor->id, 'occurred_at' => $data['occurred_at'], 'recorded_at' => now()];
                $result = $operation($base);
                $revision = (int) DB::table('clinical_replacement_contexts')->where('laboratory_order_exam_id', $lineId)->value('revision');
                $this->audit->record($lab, $actor, new AuditEvent('clinical_replacement.recorded', 'laboratory_order_exam', $lineId, oldValues: ['revision' => $context->revision], newValues: $result + ['revision' => $revision], metadata: ['laboratory_order_id' => $orderId]));

                return $result + ['revision' => $revision];
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23514') {
                throw new ClinicalConflict('replacement_integrity_conflict', 'The replacement operation conflicts with its clinical dependencies.');
            }
            throw $exception;
        }
    }

    private function intention(Laboratory $lab, int $orderId, int $lineId, int $id): object
    {
        return DB::table('clinical_replacement_intents')->where('laboratory_id', $lab->id)->where('laboratory_order_id', $orderId)->where('laboratory_order_exam_id', $lineId)->where('id', $id)->firstOrFail();
    }

    private function event(array $base, int $id, int $requirement, ?int $original, string $event, ?int $link, string $justification): void
    {
        DB::table('clinical_replacement_events')->insert($base + ['intention_id' => $id, 'sample_requirement_id' => $requirement, 'sample_id' => $original, 'event' => $event, 'link_id' => $link, 'justification' => $justification]);
    }

    private function input(array $input, array $rules): array
    {
        $rules += ['expected_revision' => ['required', 'integer', 'min:0'], 'occurred_at' => ['required', 'string', 'date', 'before_or_equal:now', 'regex:/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})$/']];
        $data = Validator::make($input, $rules)->validate();
        if (array_diff(array_keys($input), array_keys($rules)) !== []) {
            throw ValidationException::withMessages(['input' => 'Unknown input field.']);
        }
        foreach (['expected_revision', 'sample_requirement_id', 'sample_id', 'intention_id', 'previous_id', 'link_id'] as $field) {
            if (isset($data[$field]) && ! is_int($data[$field])) {
                throw ValidationException::withMessages([$field => 'Use a JSON integer.']);
            }
        }
        foreach ($data['evidence_ids'] ?? [] as $id) {
            if (! is_int($id)) {
                throw ValidationException::withMessages(['evidence_ids' => 'Use JSON integers.']);
            }
        }
        $data['occurred_at'] = CarbonImmutable::parse($data['occurred_at'])->utc();

        return $data;
    }

    private function text(): array
    {
        return ['required', 'string', 'max:4000', 'regex:/\S/'];
    }

    private function decode(object $row): array
    {
        $data = (array) $row;
        foreach (['facts', 'evidence_ids', 'dependency_snapshot'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = json_decode($data[$field], true, flags: JSON_THROW_ON_ERROR);
            }
        }

        return $data;
    }
}

<?php

namespace App\ClinicalExams;

use App\Actions\LaboratoryOrders\TransitionLaboratoryOrderStatus;
use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\ClinicalFinalization\ClinicalFinalizationGuard;
use App\ClinicalFinalization\ClinicalResolutionService;
use App\ClinicalResults\LaboratoryResultService;
use App\Http\Resources\Api\V1\LaboratoryOrderResource;
use App\Http\Resources\Api\V1\LaboratorySampleResource;
use App\Models\Branch;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Models\LaboratoryOrderSampleRequirement;
use App\Models\LaboratorySample;
use App\Models\LaboratorySampleAssociation;
use App\Models\LaboratorySampleEvent;
use App\Models\LaboratorySampleProcessingDependency;
use App\Models\SampleType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LaboratorySampleService
{
    public function __construct(private SampleCompatibility $compatibility, private AuditWriter $audit, private TransitionLaboratoryOrderStatus $transition) {}

    public static function inputRules(string $operation): array
    {
        $date = ['bail', 'string', 'max:40', 'date', 'regex:/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})$/'];
        $registration = [
            'idempotency_key' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9._:-]+$/'],
            'sample_type_id' => ['required', 'integer', 'min:1'],
            'facts' => ['sometimes', 'array:container,additives,volume,volume_unit,collection_conditions,storage_conditions'],
            'facts.container' => ['sometimes', 'string', 'max:150'],
            'facts.additives' => ['sometimes', 'array', 'list', 'max:30'],
            'facts.additives.*' => ['string', 'max:100', 'distinct:strict'],
            'facts.volume' => ['sometimes', 'numeric', 'gt:0'],
            'facts.volume_unit' => ['required_with:facts.volume', 'string', 'max:50'],
            'facts.collection_conditions' => ['sometimes', 'array', 'max:30'],
            'facts.collection_conditions.*' => ['string', 'max:150'],
            'facts.storage_conditions' => ['sometimes', 'array', 'max:30'],
            'facts.storage_conditions.*' => ['string', 'max:150'],
            'context' => ['sometimes', 'array:external_center_name,collector_description,notes'],
            'context.external_center_name' => ['sometimes', 'string', 'max:200'],
            'context.collector_description' => ['sometimes', 'string', 'max:200'],
            'context.notes' => ['sometimes', 'string', 'max:1000'],
        ];

        return match ($operation) {
            'collect' => $registration + ['collected_at' => ['required', ...$date], 'collection_branch_id' => ['required', 'integer', 'min:1'], 'collected_by' => ['sometimes', 'integer', 'min:1']],
            'receiveNew' => $registration + [
                'provenance' => ['required', 'in:internal,patient_provided,external_center'],
                'collected_by' => ['sometimes', 'nullable', 'prohibited_unless:provenance,internal', 'integer', 'min:1'],
                'collection_branch_id' => ['sometimes', 'nullable', 'prohibited_unless:provenance,internal', 'integer', 'min:1'],
                'collected_at' => ['sometimes', 'nullable', ...$date],
                'received_at' => ['required', ...$date], 'receiving_branch_id' => ['required', 'integer', 'min:1'],
            ],
            'receive' => array_intersect_key($registration, array_flip(array_filter(array_keys($registration), fn ($key) => str_starts_with($key, 'facts')))) + ['received_at' => ['required', ...$date], 'receiving_branch_id' => ['required', 'integer', 'min:1']],
            'associate' => ['sample_requirement_ids' => ['required', 'array', 'list', 'min:1', 'max:100'], 'sample_requirement_ids.*' => ['required', 'integer', 'min:1', 'distinct']],
            'reject', 'release' => ['reason' => ['required', 'string', 'max:1000', 'regex:/\S/']],
            'index' => ['page' => ['sometimes', 'integer', 'min:1']],
            'accept', 'start', 'show', 'candidates', 'operational' => [],
            default => throw new \LogicException('Unknown sample operation.'),
        };
    }

    private function input(string $operation, array $data): array
    {
        $rules = self::inputRules($operation);
        $validator = Validator::make($data, $rules);
        $validator->after(function ($validator) use ($data, $rules): void {
            foreach (array_diff(array_keys($data), array_keys($rules)) as $key) {
                $validator->errors()->add($key, 'Unknown input field.');
            }
            if (isset($data['facts']['volume']) && is_numeric($data['facts']['volume']) && ! is_finite((float) $data['facts']['volume'])) {
                $validator->errors()->add('facts.volume', 'Volume must be finite.');
            }
        });
        $valid = $validator->validate();
        foreach (['collected_at', 'received_at'] as $field) {
            if (isset($valid[$field])) {
                $valid[$field] = CarbonImmutable::parse($valid[$field])->utc()->toISOString();
                if (CarbonImmutable::parse($valid[$field])->isFuture()) {
                    throw ValidationException::withMessages([$field => ['An actual event cannot be dated in the future.']]);
                }
            }
        }
        if (isset($valid['received_at'], $valid['collected_at']) && $valid['received_at'] < $valid['collected_at']) {
            throw ValidationException::withMessages(['received_at' => ['Reception cannot precede collection.']]);
        }
        if (isset($valid['facts']['volume'])) {
            $volume = $valid['facts']['volume'];
            $literal = is_string($volume) ? $volume : json_encode($volume, JSON_THROW_ON_ERROR);
            $valid['facts']['volume'] = DB::selectOne('SELECT trim_scale(CAST(? AS numeric))::text AS value', [$literal])->value;
        }
        if (isset($valid['facts']['additives'])) {
            sort($valid['facts']['additives'], SORT_STRING);
        }
        foreach (['sample_type_id', 'collection_branch_id', 'receiving_branch_id', 'collected_by'] as $field) {
            if (isset($valid[$field])) {
                $valid[$field] = (int) $valid[$field];
            }
        }

        return $valid;
    }

    /** The first response is persisted, including its original state, and replayed exactly. */
    public function register(Laboratory $lab, User $actor, int $orderId, string $operation, array $data): array
    {
        $this->authorize($lab, $actor, $operation === 'collect' ? 'samples.collect' : 'samples.receive');
        $data = $this->input($operation, $data);

        return $this->transaction(function () use ($lab, $actor, $orderId, $operation, $data): array {
            LaboratoryOrder::forLaboratory($lab)->findOrFail($orderId);
            $hash = hash('sha256', json_encode($this->canonical(['order_id' => $orderId, 'operation' => $operation, 'input' => $data]), JSON_THROW_ON_ERROR));
            $keys = DB::table('laboratory_sample_idempotencies');
            $keys->insertOrIgnore(['laboratory_id' => $lab->id, 'laboratory_order_id' => $orderId, 'key' => $data['idempotency_key'], 'payload_hash' => $hash]);
            $idempotency = $keys->where('laboratory_id', $lab->id)->where('key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($idempotency->payload_hash !== $hash) {
                throw new ClinicalConflict('sample_idempotency_conflict', 'This idempotency key was used for a different registration.');
            }
            if ($idempotency->response !== null) {
                return $this->registrationResponse($idempotency->response);
            }
            $this->order($lab, $orderId);
            $this->localReference(SampleType::class, $lab, $data['sample_type_id'], 'sample_type_id');
            foreach (['collection_branch_id', 'receiving_branch_id'] as $field) {
                if (isset($data[$field])) {
                    $this->localReference(Branch::class, $lab, $data[$field], $field);
                }
            }
            $collection = $operation === 'collect';
            $provenance = $collection ? 'internal' : $data['provenance'];
            $collector = $provenance === 'internal' ? ($data['collected_by'] ?? ($collection ? $actor->id : null)) : null;
            if ($collector !== null && ! User::whereKey($collector)->whereHas('laboratories', fn ($query) => $query->where('laboratories.id', $lab->id))->exists()) {
                throw ValidationException::withMessages(['collected_by' => ['A known collector belonging to this laboratory is required.']]);
            }
            $sample = LaboratorySample::create([
                'laboratory_id' => $lab->id, 'laboratory_order_id' => $orderId, 'identifier' => (string) Str::ulid(),
                'sample_type_id' => $data['sample_type_id'], 'provenance' => $provenance,
                'status' => $collection ? 'collected' : 'received', 'collected_at' => $data['collected_at'] ?? null,
                'collection_branch_id' => $provenance === 'internal' ? ($data['collection_branch_id'] ?? null) : null, 'collected_by' => $collector,
                'received_at' => $data['received_at'] ?? null, 'receiving_branch_id' => $data['receiving_branch_id'] ?? null,
                'received_by' => $collection ? null : $actor->id, 'facts' => (object) ($data['facts'] ?? []),
            ]);
            if ($sample->collected_at !== null) {
                $this->event($lab, $actor, $sample, 'collected', $sample->collected_at, ($data['context'] ?? []) + ['observed_facts' => (object) $sample->facts, 'reported_collector_id' => $sample->collected_by, 'collection_branch_id' => $sample->collection_branch_id]);
            }
            if (! $collection) {
                $this->event($lab, $actor, $sample, 'received', $sample->received_at, ($data['context'] ?? []) + ['observed_facts' => (object) $sample->facts, 'reported_collector_id' => $sample->collected_by, 'collection_branch_id' => $sample->collection_branch_id]);
            }
            $result = json_encode(['data' => LaboratorySampleResource::make($sample->load(['events', 'associations']))->resolve()], JSON_THROW_ON_ERROR);
            $keys->where('id', $idempotency->id)->update(['laboratory_sample_id' => $sample->id, 'response' => $result]);

            return $this->registrationResponse($result);
        });
    }

    public function receive(Laboratory $lab, User $actor, int $orderId, int $sampleId, array $data): LaboratorySample
    {
        $this->authorize($lab, $actor, 'samples.receive');
        $data = $this->input('receive', $data);

        return $this->transaction(function () use ($lab, $actor, $orderId, $sampleId, $data): LaboratorySample {
            $this->order($lab, $orderId);
            $sample = $this->sample($lab, $orderId, $sampleId, true);
            if ($sample->received_at !== null) {
                if ($sample->received_at->equalTo(CarbonImmutable::parse($data['received_at'])) && $sample->receiving_branch_id === $data['receiving_branch_id']
                    && collect($data['facts'] ?? [])->every(fn ($value, $key) => array_key_exists($key, $sample->facts) && $this->equivalent($sample->facts[$key], $value))) {
                    return $sample;
                }
                throw new ClinicalConflict('sample_reception_conflict', 'The existing reception cannot be replaced.');
            }
            if ($sample->status !== 'collected') {
                throw new ClinicalConflict('sample_state_conflict', 'Only a collected sample can be received.');
            }
            if (CarbonImmutable::parse($data['received_at'])->lessThan($sample->collected_at)) {
                throw ValidationException::withMessages(['received_at' => ['Reception cannot precede collection.']]);
            }
            $this->localReference(Branch::class, $lab, $data['receiving_branch_id'], 'receiving_branch_id');
            $facts = $sample->facts;
            foreach ($data['facts'] ?? [] as $key => $value) {
                if (array_key_exists($key, $facts) && ! $this->equivalent($facts[$key], $value)) {
                    throw new ClinicalConflict('sample_observation_conflict', 'Reception may complete unknown facts but cannot overwrite a collection observation.', ['facts.'.$key => ['The original observation is immutable.']]);
                }
                $facts[$key] = $value;
            }
            $sample->update(['status' => 'received', 'received_at' => $data['received_at'], 'receiving_branch_id' => $data['receiving_branch_id'], 'received_by' => $actor->id, 'facts' => (object) $facts]);
            $this->event($lab, $actor, $sample, 'received', $sample->received_at, ['observed_facts' => (object) ($data['facts'] ?? [])]);

            return $sample;
        });
    }

    public function evaluate(Laboratory $lab, User $actor, int $orderId, int $sampleId, string $target, array $data = []): LaboratorySample
    {
        $this->authorize($lab, $actor, 'samples.evaluate');
        $data = $this->input($target === 'accepted' ? 'accept' : 'reject', $data);

        return $this->transaction(function () use ($lab, $actor, $orderId, $sampleId, $target, $data): LaboratorySample {
            $this->order($lab, $orderId);
            $sample = $this->sample($lab, $orderId, $sampleId, true);
            if ($sample->status === $target) {
                if ($target === 'rejected' && $sample->events()->where('event', 'rejected')->latest('id')->first()?->context['reason'] !== $data['reason']) {
                    throw new ClinicalConflict('sample_rejection_conflict', 'A rejection already exists with another reason.');
                }

                return $sample;
            }
            if (($target === 'accepted' && $sample->status !== 'received') || ($target === 'rejected' && ! in_array($sample->status, ['collected', 'received', 'accepted'], true))) {
                throw new ClinicalConflict('sample_state_conflict', 'This sample transition is not allowed.');
            }
            if ($target === 'rejected' && LaboratorySampleProcessingDependency::forLaboratory($lab)->whereIn('association_id', $sample->associations()->select('id'))->exists()) {
                throw new ClinicalConflict('sample_has_clinical_activity', 'Clinical processing depends on this sample.');
            }
            $sample->update(['status' => $target]);
            $this->event($lab, $actor, $sample, $target, now()->toImmutable(), $data);

            return $sample;
        });
    }

    public function associate(Laboratory $lab, User $actor, int $orderId, int $sampleId, array $data): LaboratorySample
    {
        $this->authorize($lab, $actor, 'samples.associate');
        $data = $this->input('associate', $data);

        return $this->transaction(function () use ($lab, $actor, $orderId, $sampleId, $data): LaboratorySample {
            $order = $this->order($lab, $orderId);
            $sample = $this->sample($lab, $orderId, $sampleId, true);
            $requirements = LaboratoryOrderSampleRequirement::forLaboratory($lab)->where('laboratory_order_id', $orderId)->whereIn('id', $data['sample_requirement_ids'])->orderBy('id')->lockForUpdate()->get();
            if ($requirements->count() !== count($data['sample_requirement_ids'])) {
                abort(404, 'Resource not found.');
            }
            $candidates = $this->compatibility->candidates($lab, $sample)->keyBy('sample_requirement_id');
            foreach ($requirements as $requirement) {
                $line = LaboratoryOrderExam::forLaboratory($lab)->findOrFail($requirement->laboratory_order_exam_id);
                ClinicalFinalizationGuard::line($order, $line);
                $existing = $sample->associations()->where('sample_requirement_id', $requirement->id)->whereNull('released_at')->first();
                if ($existing !== null) {
                    continue;
                }
                $candidate = $candidates->get($requirement->id);
                if (! $candidate['compatible']) {
                    throw new ClinicalConflict('sample_requirement_incompatible', 'The sample cannot cover this requirement.', ['sample_requirement_ids.'.$requirement->id => $candidate['errors']]);
                }
                $associatedAt = now();
                $requirement->clinical_activity_started_at ??= $associatedAt;
                $requirement->save();
                $line = LaboratoryOrderExam::forLaboratory($lab)->findOrFail($requirement->laboratory_order_exam_id);
                $line->clinical_activity_started_at ??= $associatedAt;
                $line->save();
                $association = LaboratorySampleAssociation::create([
                    'laboratory_id' => $lab->id, 'laboratory_order_id' => $orderId, 'laboratory_sample_id' => $sampleId,
                    'sample_requirement_id' => $requirement->id, 'associated_by' => $actor->id, 'associated_at' => $associatedAt,
                ]);
                $this->event($lab, $actor, $sample, 'associated', $associatedAt->toImmutable(), ['association_id' => $association->id, 'sample_requirement_id' => $requirement->id]);
            }

            return $sample;
        });
    }

    public function release(Laboratory $lab, User $actor, int $orderId, int $sampleId, int $associationId, array $data): LaboratorySample
    {
        $this->authorize($lab, $actor, 'samples.associate');
        $data = $this->input('release', $data);

        return $this->transaction(function () use ($lab, $actor, $orderId, $sampleId, $associationId, $data): LaboratorySample {
            $order = $this->order($lab, $orderId);
            $sample = $this->sample($lab, $orderId, $sampleId, true);
            $association = $sample->associations()->whereKey($associationId)->lockForUpdate()->firstOrFail();
            $requirement = LaboratoryOrderSampleRequirement::forLaboratory($lab)->findOrFail($association->sample_requirement_id);
            $line = LaboratoryOrderExam::forLaboratory($lab)->findOrFail($requirement->laboratory_order_exam_id);
            ClinicalFinalizationGuard::line($order, $line);
            if ($association->released_at !== null) {
                if ($association->release_reason !== $data['reason']) {
                    throw new ClinicalConflict('sample_release_conflict', 'This association was released with another reason.');
                }

                return $sample;
            }
            if (LaboratorySampleProcessingDependency::forLaboratory($lab)->where('association_id', $associationId)->exists()) {
                throw new ClinicalConflict('association_has_clinical_activity', 'Clinical processing depends on this association.');
            }
            $association->update(['released_at' => now(), 'released_by' => $actor->id, 'release_reason' => $data['reason']]);
            $this->event($lab, $actor, $sample, 'released', now()->toImmutable(), ['association_id' => $associationId, 'reason' => $data['reason']]);

            return $sample;
        });
    }

    public function start(Laboratory $lab, User $actor, int $orderId, int $lineId): LaboratoryOrderExam
    {
        $this->authorize($lab, $actor, 'orders.start_exam');

        return $this->transaction(function () use ($lab, $actor, $orderId, $lineId): LaboratoryOrderExam {
            $order = $this->order($lab, $orderId);
            $line = LaboratoryOrderExam::forLaboratory($lab)->where('laboratory_order_id', $orderId)->whereKey($lineId)->lockForUpdate()->firstOrFail();
            ClinicalFinalizationGuard::line($order, $line);
            if ($line->processing_started_at !== null) {
                return $line;
            }
            $line->load(['clinicalVersion', 'sampleRequirements']);
            if ($line->clinicalVersion?->status !== 'published') {
                throw new ClinicalConflict('clinical_exam_unavailable', 'A historical or legacy line cannot start clinical processing.');
            }
            if ($line->sampleRequirements->count() !== count($line->clinicalVersion->configuration['sample_requirements'] ?? [])) {
                throw new ClinicalConflict('order_exam_requirements_incomplete', 'The materialized requirements are incomplete.');
            }
            LaboratorySample::forLaboratory($lab)->where('laboratory_order_id', $orderId)->orderBy('id')->lockForUpdate()->get();
            LaboratorySampleAssociation::forLaboratory($lab)->where('laboratory_order_id', $orderId)->orderBy('id')->lockForUpdate()->get();
            $qualified = $this->compatibility->qualifyingAssociations($lab, $orderId)->groupBy('sample_requirement_id');
            foreach ($line->sampleRequirements as $requirement) {
                if (($requirement->configuration['mandatory'] ?? true) && ! $qualified->has($requirement->id)) {
                    throw new ClinicalConflict('order_exam_not_ready', 'At least one mandatory requirement has no adequate accepted sample.', ['sample_requirement_ids' => [$requirement->id]]);
                }
            }
            $startedAt = now();
            foreach ($line->sampleRequirements as $requirement) {
                $association = $qualified->get($requirement->id)?->first();
                if ($association !== null) {
                    LaboratorySampleProcessingDependency::create([
                        'laboratory_id' => $lab->id, 'laboratory_order_id' => $orderId, 'laboratory_order_exam_id' => $lineId,
                        'association_id' => $association->id, 'created_at' => $startedAt,
                        'decision' => ['clinical_version_id' => $line->clinical_version_id, 'sample_requirement_id' => $requirement->id,
                            'requirement' => json_decode($association->requirement_snapshot, true, flags: JSON_THROW_ON_ERROR),
                            'sample' => json_decode($association->sample_snapshot, true, flags: JSON_THROW_ON_ERROR), 'evaluated_at' => CarbonImmutable::parse($association->evaluated_at)->toISOString()],
                    ]);
                }
            }
            $line->clinical_activity_started_at ??= $startedAt;
            $line->processing_started_at = $startedAt;
            $line->processing_started_by = $actor->id;
            $line->save();
            $this->audit->record($lab, $actor, new AuditEvent('order_exam.processing_started', 'laboratory_order_exam', $lineId,
                newValues: ['processing_started_at' => $startedAt, 'processing_started_by' => $actor->id, 'clinical_version_id' => $line->clinical_version_id]));
            if ($order->status === 'pending') {
                $this->transition->execute($lab, $orderId, 'in_process', $actor);
            }

            return $line;
        });
    }

    public function operational(Laboratory $lab, int $orderId): array
    {
        return $this->transaction(function () use ($lab, $orderId): array {
            $order = LaboratoryOrder::forLaboratory($lab)->whereKey($orderId)->sharedLock()->firstOrFail();
            $order->load('clinicalFinalization');
            $lines = $order->orderExams()->with(['clinicalVersion', 'sampleRequirements', 'resultCapture:id,laboratory_order_exam_id,revision,status', 'clinicalResolution:id,laboratory_order_exam_id,resolution_type,resolved_by,resolved_at', 'clinicalExceptions:id,laboratory_order_exam_id,status', 'results:id,laboratory_order_exam_id,parameter_code'])->orderBy('id')->get();
            $samples = LaboratorySample::forLaboratory($lab)->where('laboratory_order_id', $orderId)->with(['events', 'associations'])->orderBy('id')->get();
            $assessments = $this->compatibility->assessments($lab, $orderId);
            $qualified = $assessments->filter(fn ($row) => $row->released_at === null && $row->sample_status === 'accepted' && $row->errors === '[]')->groupBy('sample_requirement_id');
            $assessments = $assessments->groupBy('sample_requirement_id');
            $dependencies = LaboratorySampleProcessingDependency::forLaboratory($lab)->where('laboratory_order_id', $orderId)->orderBy('id')->get()->groupBy('laboratory_order_exam_id');

            return ['clinical_finalization' => ClinicalResolutionService::progress($order, $lines), 'laboratory_order_id' => $orderId, 'order_number' => $order->code, 'status' => $order->status,
                'order' => LaboratoryOrderResource::make($order->load(['patient', 'doctor', 'branch', 'createdBy']))->resolve(),
                'samples' => LaboratorySampleResource::collection($samples)->resolve(),
                'exams' => $lines->map(function ($line) use ($qualified, $assessments, $dependencies, $order): array {
                    $blockers = [];
                    $requirements = $line->sampleRequirements->map(function ($requirement) use ($qualified, $assessments, &$blockers): array {
                        $satisfied = $qualified->has($requirement->id);
                        $mandatory = $requirement->configuration['mandatory'] ?? true;
                        if ($mandatory && ! $satisfied) {
                            $blockers[] = $requirement->id;
                        }

                        return ['id' => $requirement->id, 'requirement_key' => $requirement->requirement_key, 'mandatory' => $mandatory,
                            'status' => $satisfied ? 'satisfied' : 'pending', 'configuration' => $requirement->configuration,
                            'qualifying_association_ids' => $qualified->get($requirement->id)?->pluck('id')->all() ?? [],
                            'association_evaluations' => $assessments->get($requirement->id)?->map(fn ($row) => ['association_id' => $row->id, 'sample_id' => $row->laboratory_sample_id, 'active' => $row->released_at === null, 'sample_status' => $row->sample_status, 'compatibility_errors' => json_decode($row->errors, true, flags: JSON_THROW_ON_ERROR)])->all() ?? []];
                    })->all();
                    $published = $line->clinicalVersion?->status === 'published';
                    $complete = $published && count($requirements) === count($line->clinicalVersion->configuration['sample_requirements'] ?? []);

                    return ['id' => $line->id, 'clinical_version_id' => $line->clinical_version_id,
                        'clinical_configuration_source' => $line->clinical_configuration_source ?? 'historical',
                        'operational_status' => $line->processing_started_at !== null ? 'started' : ($complete && $blockers === [] ? 'ready' : 'pending_samples'),
                        'clinical_resolution' => $line->clinicalResolution === null ? null : ['id' => $line->clinicalResolution->id, 'resolution_type' => $line->clinicalResolution->resolution_type, 'resolved_by' => $line->clinicalResolution->resolved_by, 'resolved_at' => $line->clinicalResolution->resolved_at->toISOString()],
                        'can_start' => $line->clinicalResolution === null && $line->processing_started_at === null && $complete && $blockers === [] && in_array($order->status, ['pending', 'in_process'], true),
                        'processing_dependencies' => $dependencies->get($line->id)?->map(fn ($dependency) => ['association_id' => $dependency->association_id, 'decision' => $dependency->decision])->all() ?? [],
                        'blocking_requirement_ids' => $blockers, 'blocking_reasons' => $published ? (! $complete ? ['materialized_requirements_incomplete'] : ($blockers === [] ? [] : ['no_adequate_accepted_sample'])) : ['published_clinical_version_required'],
                        'processing_started_at' => $line->processing_started_at?->toISOString(), 'processing_started_by' => $line->processing_started_by,
                        'result_capture' => LaboratoryResultService::progress($line),
                        'sample_requirements' => $requirements];
                })->all()];
        });
    }

    public function sample(Laboratory $lab, int $orderId, int $sampleId, bool $lock = false): LaboratorySample
    {
        $query = LaboratorySample::forLaboratory($lab)->where('laboratory_order_id', $orderId)->whereKey($sampleId);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    private function order(Laboratory $lab, int $orderId): LaboratoryOrder
    {
        $order = LaboratoryOrder::forLaboratory($lab)->whereKey($orderId)->lockForUpdate()->firstOrFail();
        if (! in_array($order->status, ['pending', 'in_process'], true)) {
            throw new ClinicalConflict('sample_order_state_conflict', 'This order no longer permits sample operations.');
        }

        return $order;
    }

    private function authorize(Laboratory $lab, User $actor, string $permission): void
    {
        if ((int) getPermissionsTeamId() !== $lab->id
            || ! User::whereKey($actor->id)->where('is_active', true)->exists()
            || ! $actor->laboratories()->whereKey($lab->id)->wherePivot('is_active', true)->exists()) {
            abort(403, 'An active actor and membership in the current laboratory are required.');
        }
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        Gate::forUser($actor)->authorize($permission);
    }

    private function localReference(string $model, Laboratory $lab, int $id, string $field): void
    {
        if ($model::query()->where('laboratory_id', $lab->id)->whereKey($id)->sharedLock()->first() === null) {
            throw ValidationException::withMessages([$field => ['A local reference is required.']]);
        }
    }

    private function event(Laboratory $lab, User $actor, LaboratorySample $sample, string $event, \DateTimeInterface $occurredAt, array $context = []): void
    {
        LaboratorySampleEvent::create(['laboratory_id' => $lab->id, 'laboratory_order_id' => $sample->laboratory_order_id,
            'laboratory_sample_id' => $sample->id, 'event' => $event, 'actor_id' => $actor->id,
            'occurred_at' => $occurredAt, 'recorded_at' => now(), 'context' => (object) $context]);
        $this->audit->record($lab, $actor, new AuditEvent('sample.'.$event, 'laboratory_sample', $sample->id,
            newValues: ['status' => $sample->status, 'laboratory_order_id' => $sample->laboratory_order_id, 'occurred_at' => $occurredAt],
            metadata: array_intersect_key($context, array_flip(['association_id', 'sample_requirement_id', 'reason']))));
    }

    private function transaction(\Closure $operation): mixed
    {
        try {
            return DB::transaction($operation);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23514') {
                foreach ([
                    'Association is not clinically compatible' => 'sample_requirement_incompatible',
                    'A processing dependency requires' => 'order_exam_not_ready',
                    'Processing start requires' => 'order_exam_not_ready',
                    'A processing dependency prevents sample rejection' => 'sample_has_clinical_activity',
                    'Cannot release a processing dependency' => 'association_has_clinical_activity',
                    'Invalid physical sample transition' => 'sample_state_conflict',
                    'Observed facts may only be completed' => 'sample_observation_conflict',
                ] as $message => $code) {
                    if (str_contains($exception->getMessage(), $message)) {
                        throw new ClinicalConflict($code, 'The sample operation conflicts with its current clinical state.');
                    }
                }
            }
            throw $exception;
        }
    }

    private function equivalent(mixed $left, mixed $right): bool
    {
        return json_encode(is_array($left) ? $this->canonical($left) : $left, JSON_THROW_ON_ERROR) === json_encode(is_array($right) ? $this->canonical($right) : $right, JSON_THROW_ON_ERROR);
    }

    private function registrationResponse(string $json): array
    {
        $response = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $response['data']['facts'] = (object) $response['data']['facts'];
        foreach ($response['data']['events'] as &$event) {
            $event['context'] = (object) $event['context'];
        }

        return $response;
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonical($item);
            }
        }

        return $value;
    }
}

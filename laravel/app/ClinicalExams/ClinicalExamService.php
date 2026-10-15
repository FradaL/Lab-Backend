<?php

namespace App\ClinicalExams;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\Models\GlobalExamTemplate;
use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryExamVersion;
use App\Models\SampleType;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ClinicalExamService
{
    public function __construct(private ClinicalConfigurationValidator $validator, private AuditWriter $audit) {}

    public function create(Laboratory $lab, User $actor, array $input, ?GlobalExamTemplate $template = null): LaboratoryExamVersion
    {
        try {
            return DB::transaction(function () use ($lab, $actor, $input, $template) {
                if ($template !== null) {
                    // Lock the immutable template to serialize concurrent imports of this artifact.
                    $template = GlobalExamTemplate::whereKey($template->id)->lockForUpdate()->firstOrFail();
                    if (DB::table('laboratory_exam_clinical_states')->where('laboratory_id', $lab->id)->where('global_exam_template_id', $template->id)->exists()) {
                        throw new ClinicalConflict('template_already_imported', 'This template has already been imported into this laboratory.');
                    }
                }
                if (! LaboratoryArea::forLaboratory($lab)->whereKey($input['laboratory_area_id'])->where('status', 'active')->exists()) {
                    throw ValidationException::withMessages(['laboratory_area_id' => 'An active local laboratory area is required.']);
                }
                $sampleId = $input['sample_type_id'] ?? null;
                if ($sampleId !== null && ! SampleType::forLaboratory($lab)->whereKey($sampleId)->where('status', 'active')->exists()) {
                    throw ValidationException::withMessages(['sample_type_id' => 'An active local sample type is required.']);
                }
                $configuration = $template?->configuration ?? ($input['configuration'] ?? []);
                $configuration['schema_version'] ??= 1;
                $configuration['code'] = $input['code'];
                $configuration['name'] = $input['name'];
                if ($template !== null) {
                    $usedKeys = [];
                    foreach ($configuration['sample_requirements'] ?? [] as $i => $requirement) {
                        $key = $requirement['key'];
                        $usedKeys[] = $key;
                        $configuration['sample_requirements'][$i]['sample_type_id'] = $input['sample_type_map'][$key] ?? null;
                        unset($configuration['sample_requirements'][$i]['sample_type_name']);
                    }
                    if (array_diff(array_keys($input['sample_type_map'] ?? []), $usedKeys) !== []) {
                        throw ValidationException::withMessages(['sample_type_map' => 'Unknown template sample requirement key.']);
                    }
                }
                $this->validateDraft($configuration, $lab);
                $exam = LaboratoryExam::create([
                    'laboratory_id' => $lab->id, 'laboratory_area_id' => $input['laboratory_area_id'],
                    'sample_type_id' => $sampleId, 'code' => $input['code'], 'name' => $input['name'], 'status' => 'inactive',
                ]);
                DB::table('laboratory_exam_clinical_states')->insert([
                    'laboratory_id' => $lab->id, 'laboratory_exam_id' => $exam->id,
                    'global_exam_template_id' => $template?->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $version = $this->newDraft($lab, $exam, $configuration, null);
                $this->record($lab, $actor, $version, $template === null ? 'draft_created' : 'template_imported', ['template_id' => $template?->id]);

                return $version;
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                if (str_contains($exception->getMessage(), 'clinical_template_import_unique')) {
                    throw new ClinicalConflict('template_already_imported', 'This template has already been imported into this laboratory.');
                }
                if (str_contains($exception->getMessage(), 'laboratory_exams_laboratory_code_unique')) {
                    throw new ClinicalConflict('exam_code_conflict', 'The exam code is already reserved in this laboratory.');
                }
            }
            throw $exception;
        }
    }

    public function fork(Laboratory $lab, User $actor, int $examId, ?int $sourceId): LaboratoryExamVersion
    {
        return DB::transaction(function () use ($lab, $actor, $examId, $sourceId) {
            $exam = $this->exam($lab, $examId, true);
            $state = $this->state($lab, $examId);
            $current = $state?->current_version_id;
            if ($sourceId !== null) {
                $source = $this->version($lab, $examId, $sourceId);
                if ($source->status !== 'published') {
                    throw new ClinicalConflict('source_not_published', 'Only published versions can be copied.');
                }
                $configuration = $source->configuration;
                // Named sample snapshots are recalculated at the next publication.
                foreach ($configuration['sample_requirements'] ?? [] as $i => $requirement) {
                    unset($configuration['sample_requirements'][$i]['sample_type_name']);
                }
            } else {
                if ($current !== null) {
                    throw new ClinicalConflict('source_version_required', 'Specify a published source version.');
                }
                $configuration = ['schema_version' => 1, 'code' => $exam->code, 'name' => $exam->name];
            }
            if ($state === null) {
                DB::table('laboratory_exam_clinical_states')->insert([
                    'laboratory_id' => $lab->id, 'laboratory_exam_id' => $examId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $version = $this->newDraft($lab, $exam, $configuration, $current);
            $this->record($lab, $actor, $version, 'draft_created', ['source_version_id' => $sourceId]);

            return $version;
        });
    }

    public function update(Laboratory $lab, User $actor, int $examId, int $versionId, int $revision, array $configuration): LaboratoryExamVersion
    {
        return DB::transaction(function () use ($lab, $actor, $examId, $versionId, $revision, $configuration) {
            $this->exam($lab, $examId, true);
            $version = $this->version($lab, $examId, $versionId, true);
            $this->editable($version, $revision);
            $configuration['schema_version'] ??= 1;
            $this->validateDraft($configuration, $lab);
            $oldRevision = $version->revision;
            $version->configuration = $configuration;
            $version->revision++;
            $version->save();
            $this->record($lab, $actor, $version, 'draft_updated', ['previous_revision' => $oldRevision]);

            return $version;
        });
    }

    public function publish(Laboratory $lab, User $actor, int $examId, int $versionId, int $revision): LaboratoryExamVersion
    {
        return DB::transaction(function () use ($lab, $actor, $examId, $versionId, $revision) {
            $exam = $this->exam($lab, $examId, true);
            $version = $this->version($lab, $examId, $versionId, true);
            $this->editable($version, $revision);
            $state = $this->state($lab, $examId);
            if ($version->base_version_id !== $state->current_version_id) {
                throw new ClinicalConflict('publication_conflict', 'Another version was published after this draft was prepared. Create a new draft.');
            }
            $configuration = $version->configuration;
            $errors = $this->validator->errors($configuration, $lab, true);
            if (($configuration['code'] ?? null) !== $exam->code) {
                $errors['code'][] = 'The clinical code must match the current catalog identity.';
            }
            if ($errors !== []) {
                throw new ClinicalConflict('publication_invalid', 'The draft is not publishable.', $errors, 422);
            }
            foreach ($configuration['sample_requirements'] ?? [] as $i => $requirement) {
                $sample = SampleType::forLaboratory($lab)->whereKey($requirement['sample_type_id'])->sharedLock()->firstOrFail();
                if ($sample->status !== 'active') {
                    throw new ClinicalConflict('publication_invalid', 'The sample type is no longer active.', ['sample_requirements' => ['Inactive sample type.']], 422);
                }
                $configuration['sample_requirements'][$i]['sample_type_name'] = $sample->name;
            }
            $version->fill([
                'configuration' => $configuration, 'configuration_hash' => $this->hash($configuration),
                'status' => 'published', 'published_at' => now(), 'published_by' => $actor->id,
                'revision' => $version->revision + 1,
            ])->save();
            DB::table('laboratory_exam_clinical_states')->where('laboratory_exam_id', $examId)->where('laboratory_id', $lab->id)
                ->update(['current_version_id' => $version->id, 'updated_at' => now()]);
            $this->record($lab, $actor, $version, 'published', ['previous_version_id' => $state->current_version_id]);

            return $version;
        });
    }

    /** Used by the future order-add transaction while holding the exam row lock. */
    public function orderConfiguration(Laboratory $lab, int $examId): array
    {
        return DB::transaction(function () use ($lab, $examId) {
            $exam = $this->exam($lab, $examId, true);
            $version = $this->current($lab, $examId);
            if ($exam->status !== 'active' || $version === null) {
                throw new ClinicalConflict('clinical_exam_unavailable', 'An active exam with a published clinical version is required.');
            }

            return [
                'schema_version' => 1, 'laboratory_exam_id' => $examId, 'clinical_version_id' => $version->id,
                'clinical_version_number' => $version->number, 'configuration_hash' => $version->configuration_hash,
                'configuration' => $version->configuration,
                'result_context_contract' => [
                    'capture_at' => 'interpretation_or_validation',
                    'required_snapshot_fields' => ['reference_date', 'age_days', 'sex', 'conditions', 'clinical_version_id', 'parameter_code', 'reference_key'],
                    'priority_rule' => 'highest_matching_priority', 'missing_context_rule' => 'authorized_audited_resolution',
                    'no_match_or_tie_rule' => 'authorized_audited_resolution',
                ],
            ];
        });
    }

    public function current(Laboratory $lab, int $examId): ?LaboratoryExamVersion
    {
        $this->exam($lab, $examId);
        $id = $this->state($lab, $examId)?->current_version_id;

        return $id === null ? null : $this->version($lab, $examId, $id);
    }

    public function exam(Laboratory $lab, int $id, bool $lock = false): LaboratoryExam
    {
        return LaboratoryExam::forLaboratory($lab)->whereKey($id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
    }

    public function version(Laboratory $lab, int $examId, int $id, bool $lock = false): LaboratoryExamVersion
    {
        return LaboratoryExamVersion::forLaboratory($lab)->where('laboratory_exam_id', $examId)->whereKey($id)
            ->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
    }

    public function state(Laboratory $lab, int $examId): ?object
    {
        return DB::table('laboratory_exam_clinical_states')->where('laboratory_id', $lab->id)->where('laboratory_exam_id', $examId)->first();
    }

    public function hash(array $configuration): string
    {
        $canonical = function (array $value) use (&$canonical): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as $key => $entry) {
                if (is_array($entry)) {
                    $value[$key] = $canonical($entry);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($canonical($configuration), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function validateDraft(array $configuration, Laboratory $lab): void
    {
        $errors = $this->validator->errors($configuration, $lab);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function editable(LaboratoryExamVersion $version, int $revision): void
    {
        if ($version->status !== 'draft') {
            throw new ClinicalConflict('version_not_editable', 'Published versions are immutable.');
        }
        if ($version->revision !== $revision) {
            throw new ClinicalConflict('revision_conflict', 'The draft has changed. Reload it before writing.');
        }
    }

    private function newDraft(Laboratory $lab, LaboratoryExam $exam, array $configuration, ?int $base): LaboratoryExamVersion
    {
        return LaboratoryExamVersion::create([
            'laboratory_id' => $lab->id, 'laboratory_exam_id' => $exam->id,
            'number' => (LaboratoryExamVersion::where('laboratory_exam_id', $exam->id)->max('number') ?? 0) + 1,
            'base_version_id' => $base, 'configuration' => $configuration,
        ])->refresh();
    }

    private function record(Laboratory $lab, User $actor, LaboratoryExamVersion $version, string $event, array $metadata): void
    {
        $this->audit->record($lab, $actor, new AuditEvent(
            'laboratory_exam.'.$event, 'laboratory_exam_version', $version->id,
            newValues: ['revision' => $version->revision, 'status' => $version->status, 'configuration_hash' => $this->hash($version->configuration)],
            metadata: ['laboratory_exam_id' => $version->laboratory_exam_id, 'version_number' => $version->number] + $metadata,
        ));
    }
}

<?php

namespace App\ClinicalExams;

use App\Models\GlobalClinicalReason;
use App\Models\GlobalClinicalReasonVersion;
use App\Models\Laboratory;
use App\Models\LaboratoryClinicalReason;
use App\Models\LaboratoryClinicalReasonVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Low-level persistence only; SaaS/owner authorization belongs to the future application boundary. */
class ClinicalReasonVersionStore
{
    public function appendGlobal(int $reasonId, User $actor, int $expectedNumber, array $definition): GlobalClinicalReasonVersion
    {
        $this->validate($definition, $expectedNumber);

        return DB::transaction(function () use ($reasonId, $actor, $expectedNumber, $definition): GlobalClinicalReasonVersion {
            $reason = GlobalClinicalReason::whereKey($reasonId)->lockForUpdate()->firstOrFail();
            $number = $this->nextNumber($reason->versions()->max('number'), $expectedNumber);

            return $reason->versions()->create($definition + ['number' => $number, 'created_by' => $actor->id])->refresh();
        });
    }

    public function appendLocal(Laboratory $laboratory, int $reasonId, User $actor, int $expectedNumber, array $definition): LaboratoryClinicalReasonVersion
    {
        $this->validate($definition, $expectedNumber);

        return DB::transaction(function () use ($laboratory, $reasonId, $actor, $expectedNumber, $definition): LaboratoryClinicalReasonVersion {
            $reason = LaboratoryClinicalReason::forLaboratory($laboratory)->whereKey($reasonId)->lockForUpdate()->firstOrFail();
            $number = $this->nextNumber($reason->versions()->max('number'), $expectedNumber);

            return $reason->versions()->create($definition + ['laboratory_id' => $laboratory->id, 'number' => $number, 'created_by' => $actor->id])->refresh();
        });
    }

    private function nextNumber(?int $current, int $expected): int
    {
        if (($current ?? 0) !== $expected) {
            throw new ClinicalConflict('clinical_reason_version_conflict', 'Reload the current clinical reason version before appending.');
        }

        return $expected + 1;
    }

    private function validate(array $definition, int $expectedNumber): void
    {
        Validator::make($definition, [
            'name' => ['required', 'string', 'max:200', 'regex:/\S/'],
            'description' => ['required', 'string', 'regex:/\S/'],
            'classification' => ['required', Rule::in(['incidence_only', 'terminal_candidate'])],
            'evidence_requirements' => ['required', 'array:schema_version,requirements'],
            'evidence_requirements.schema_version' => ['required', Rule::in([1])],
            'evidence_requirements.requirements' => ['present', 'array', 'list'],
            'evidence_requirements.requirements.*' => ['required', 'string', 'distinct:strict', 'regex:/^[a-z][a-z0-9_]{0,99}$/'],
            'is_active' => ['required', 'boolean'],
            'change_reason' => ['required', 'string', 'regex:/\S/'],
        ])->validate();
        if ($expectedNumber < 0 || array_diff(array_keys($definition), ['name', 'description', 'classification', 'evidence_requirements', 'is_active', 'change_reason']) !== []
            || $definition['evidence_requirements']['schema_version'] !== 1 || ! is_bool($definition['is_active'])) {
            throw ValidationException::withMessages(['definition' => 'Use documented fields, schema_version integer 1, a boolean active flag and a nonnegative expected number.']);
        }
    }
}

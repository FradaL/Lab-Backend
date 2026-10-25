<?php

namespace Tests\Feature\ClinicalReasons;

use App\Models\GlobalClinicalReason;
use App\Models\Laboratory;
use App\Models\LaboratoryClinicalReason;

trait ClinicalReasonScenario
{
    private function reasonScenario(string $scope = 'global'): array
    {
        $laboratory = Laboratory::factory()->create();
        $actor = $this->createLaboratoryMember($laboratory);
        $reason = $scope === 'global'
            ? GlobalClinicalReason::create(['code' => 'synthetic_reason', 'created_by' => $actor->id])
            : LaboratoryClinicalReason::create(['laboratory_id' => $laboratory->id, 'code' => 'synthetic_reason', 'created_by' => $actor->id]);

        return [$laboratory, $actor, $reason];
    }

    private function definition(array $overrides = []): array
    {
        return array_replace([
            'name' => 'FICTICIO: incidencia',
            'description' => 'Definición sintética, sin valor diagnóstico.',
            'classification' => 'incidence_only',
            'evidence_requirements' => ['schema_version' => 1, 'requirements' => ['problem_description']],
            'is_active' => true,
            'change_reason' => 'FICTICIO: definición inicial',
        ], $overrides);
    }
}

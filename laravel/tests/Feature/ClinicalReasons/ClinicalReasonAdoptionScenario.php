<?php

namespace Tests\Feature\ClinicalReasons;

use App\Authorization\ClinicalReasonTenantAuthority as Tenant;
use App\ClinicalExams\ClinicalReasonAdoptionManager;
use App\ClinicalExams\GlobalClinicalReasonImpactManager as Impacts;
use App\ClinicalExams\GlobalClinicalReasonManager as GlobalManager;
use App\Models\Laboratory;
use App\Models\Subscription;
use App\Models\User;
use App\UserAdministration\SaasAccessManager;
use Database\Seeders\RolePermissionSeeder;

trait ClinicalReasonAdoptionScenario
{
    private function operator(): array
    {
        $admin = User::factory()->create();
        $operator = User::factory()->create();
        config(['saas.bootstrap.enabled' => true, 'saas.bootstrap.user_id' => $admin->id]);
        $access = app(SaasAccessManager::class);
        $access->bootstrap();
        foreach ([GlobalManager::VIEW, GlobalManager::CREATE, GlobalManager::PUBLISH, GlobalManager::MANAGE, Impacts::CAPABILITY] as $capability) {
            $access->grant($admin, $operator->id, ['capability' => $capability, 'reason' => 'FICTICIO: provisión explícita.']);
        }

        return [$operator, $admin];
    }

    private function definition(array $changes = []): array
    {
        return array_replace(['name' => 'FICTICIO', 'description' => 'FICTICIO: definición', 'classification' => 'incidence_only', 'evidence_requirements' => ['schema_version' => 1, 'requirements' => []], 'is_active' => true, 'change_reason' => 'FICTICIO: definición inicial.'], $changes);
    }

    private function globalReason(User $operator, string $code = 'ficticio'): array
    {
        return app(GlobalManager::class)->create($operator, ['code' => $code, 'definition' => $this->definition()])['data'];
    }

    private function declareImpact(User $operator, array $reason, string $classification = 'non_restrictive', int $expected = 0): array
    {
        return app(Impacts::class)->declare($operator, $reason['reason']->id, $reason['version']->id, ['expected_revision' => $expected, 'classification' => $classification, 'justification' => 'FICTICIO: evaluación global.']);
    }

    private function tenant(string $role = 'owner', ?User $user = null): array
    {
        $lab = Laboratory::factory()->create();
        Subscription::factory()->create(['laboratory_id' => $lab->id]);
        $user ??= $this->createLaboratoryMember($lab);
        if (! $user->laboratories()->whereKey($lab->id)->exists()) {
            $user->laboratories()->attach($lab);
        }
        $this->seed(RolePermissionSeeder::class);
        $this->assignLaboratoryRole($user, $lab, $role);

        return [$lab, $user];
    }

    private function selectReason(Laboratory $lab, User $owner, array $global): array
    {
        $this->setPermissionTeam($lab);

        return app(ClinicalReasonAdoptionManager::class)->adopt($lab, $owner, ['reason_id' => $global['reason']->id, 'version_id' => $global['version']->id, 'justification' => 'FICTICIO: selección individual.'])['data'];
    }

    private function reviewer(Laboratory $lab): User
    {
        $user = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermissions($user, $lab, [Tenant::REVIEW, Tenant::VIEW, Tenant::HISTORY, 'orders.clinical_exceptions.request', 'orders.clinical_exceptions.approve']);

        return $user;
    }
}

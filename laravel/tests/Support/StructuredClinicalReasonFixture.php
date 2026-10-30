<?php

namespace Tests\Support;

use App\ClinicalExams\ClinicalReasonVersionStore;
use App\ClinicalExams\GlobalClinicalReasonImpactManager;
use App\ClinicalReplacements\ClinicalReplacementService;
use App\Models\ClinicalReasonAdoption;
use App\Models\ClinicalReasonAdoptionVersion;
use App\Models\GlobalClinicalReason;
use App\Models\Laboratory;
use App\Models\LaboratoryOrderExam;
use App\Models\SaasAuditLog;
use App\Models\User;
use App\UserAdministration\SaasAccessManager;
use Illuminate\Support\Facades\DB;

/** Synthetic database fixture; HTTP adoption and classification have their own integration suites. */
final class StructuredClinicalReasonFixture
{
    public static function global(string $name = 'FICTICIO', ?string $code = null): array
    {
        $adminId = SaasAuditLog::where('source', 'bootstrap')->value('affected_user_id');
        $admin = $adminId ? User::findOrFail($adminId) : User::factory()->create();
        if (! $adminId) {
            config(['saas.bootstrap.enabled' => true, 'saas.bootstrap.user_id' => $admin->id]);
            app(SaasAccessManager::class)->bootstrap();
        }
        $operator = User::factory()->create();
        app(SaasAccessManager::class)->grant($admin, $operator->id, ['capability' => GlobalClinicalReasonImpactManager::CAPABILITY, 'reason' => 'FICTICIO: clasificador de prueba.']);
        $reason = GlobalClinicalReason::create(['code' => $code ?? 'fixture_'.($operator->id), 'created_by' => $operator->id]);
        $version = app(ClinicalReasonVersionStore::class)->appendGlobal($reason->id, $operator, 0, [
            'name' => $name, 'description' => 'FICTICIO: definición histórica', 'classification' => 'terminal_candidate',
            'evidence_requirements' => ['schema_version' => 1, 'requirements' => $code === null ? ['medical_instruction'] : []], 'is_active' => true, 'change_reason' => 'FICTICIO: inicial',
        ]);
        app(GlobalClinicalReasonImpactManager::class)->declare($operator, $reason->id, $version->id, ['expected_revision' => 0, 'classification' => 'non_restrictive', 'justification' => 'FICTICIO: impacto explícito.']);

        return [$reason, $version, $operator];
    }

    public static function prepareDemoCatalog(): void
    {
        [$reason] = self::global('FICTICIO: motivo demo explícito');
        config(['saas.demo_clinical_reason_id' => $reason->id]);
    }

    public static function medicalEvidence(Laboratory $lab, User $actor): void
    {
        foreach (LaboratoryOrderExam::forLaboratory($lab)->whereDoesntHave('clinicalResolution')->get() as $line) {
            if (DB::table('clinical_line_evidence')->where('laboratory_order_exam_id', $line->id)->where('kind', 'medical_instruction')->exists()) {
                continue;
            }
            $revision = (int) DB::table('clinical_replacement_contexts')->where('laboratory_order_exam_id', $line->id)->value('revision');
            setPermissionsTeamId($lab->id);
            app(ClinicalReplacementService::class)->recordEvidence($lab, $actor, $line->laboratory_order_id, $line->id, ['expected_revision' => $revision, 'occurred_at' => now()->subMinute()->toISOString(), 'kind' => 'medical_instruction', 'facts' => ['instruction' => 'FICTICIO: indicación documentada para escenario de excepción.']]);
        }
    }

    public static function forLaboratory(Laboratory $lab, User $actor, string $name = 'FICTICIO'): array
    {
        self::medicalEvidence($lab, $actor);
        $adoption = ClinicalReasonAdoption::forLaboratory($lab)->with('latestVersion')->first();
        if (! $adoption) {
            [$reason, $version] = self::global($name);
            $adoption = ClinicalReasonAdoption::create(['laboratory_id' => $lab->id, 'reason_id' => $reason->id, 'created_by' => $actor->id]);
            ClinicalReasonAdoptionVersion::create(['laboratory_id' => $lab->id, 'reason_id' => $reason->id, 'adoption_id' => $adoption->id, 'version_id' => $version->id, 'revision' => 1, 'previous_id' => null, 'justification' => 'FICTICIO: selección de prueba.', 'created_by' => $actor->id]);
        }
        $selection = $adoption->latestVersion;

        return ['source' => 'global', 'reason_id' => $adoption->reason_id, 'version_id' => $selection->version_id, 'adoption_id' => $adoption->id, 'adoption_version_id' => $selection->id];
    }
}

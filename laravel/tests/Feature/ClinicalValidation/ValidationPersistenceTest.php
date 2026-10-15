<?php

namespace Tests\Feature\ClinicalValidation;

use App\ClinicalResults\LaboratoryResultService;
use App\ClinicalValidation\LaboratoryValidationService;
use App\ClinicalValidation\ValidationAdministrationService;
use App\Models\Laboratory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\ClinicalResults\ClinicalResultScenario;
use Tests\TestCase;

class ValidationPersistenceTest extends TestCase
{
    use ClinicalResultScenario, RefreshDatabase;

    #[DataProvider('sqlAttempts')]
    public function test_postgres_protects_clinical_state_and_evidence(string $attempt): void
    {
        [$lab,$actor,$order,$line] = $this->scenario();
        app(ValidationAdministrationService::class)->setPolicy($lab, $actor, ['validation_policy' => 'permission_based']);
        app(LaboratoryResultService::class)->save($lab, $actor, $order->id, $line->id, ['revision' => 0, 'results' => [['parameter_code' => 'N', 'value' => '1'], ['parameter_code' => 'T', 'value' => 'DEMO'], ['parameter_code' => 'Q', 'value' => 'YES'], ['parameter_code' => 'O', 'value' => 'A']]]);
        $service = app(LaboratoryValidationService::class);
        if ($attempt !== 'without_evidence') {
            $service->validate($lab, $actor, $order->id, $line->id, ['revision' => 1, 'reference_context' => ['reference_date' => '2026-10-11']]);
        }
        if ($attempt === 'rectification_delete') {
            $service->rectify($lab, $actor, $order->id, $line->id, ['revision' => 2, 'reason' => 'FICTICIO']);
        }
        $this->expectException(QueryException::class);
        match ($attempt) {
            'without_evidence' => DB::table('laboratory_order_result_captures')->where('laboratory_order_exam_id', $line->id)->update(['revision' => 2, 'status' => 'validated']),
            'result_delete' => DB::table('laboratory_order_results')->where('laboratory_order_exam_id', $line->id)->delete(),
            'result_update' => DB::table('laboratory_order_results')->where('parameter_code', 'N')->update(['numeric_value' => '2', 'capture_revision' => 3]),
            'capture_reopen' => DB::table('laboratory_order_result_captures')->update(['status' => 'preliminary', 'revision' => 3]),
            'validation_delete' => DB::table('laboratory_result_validations')->delete(),
            'audit_delete' => DB::table('audit_logs')->where('event', 'order_results.validated')->delete(),
            'rectification_delete' => DB::table('laboratory_result_rectifications')->delete(),
        };
    }

    public static function sqlAttempts(): array
    {
        return array_map(fn ($v) => [$v], ['without_evidence', 'result_delete', 'result_update', 'capture_reopen', 'validation_delete', 'audit_delete', 'rectification_delete']);
    }

    public function test_policy_and_professional_admin_are_separate_and_tenant_scoped(): void
    {
        [$lab,$actor] = $this->scenario();
        $other = Laboratory::factory()->create();
        $otherMember = $this->createLaboratoryMember($other);
        $this->postJson('/api/v1/laboratory/validation-professionals', ['user_id' => $otherMember->id, 'professional_name' => 'DEMO', 'profession' => 'DEMO'])->assertUnprocessable();
        $this->assertSame('professional_required', $other->fresh()->validation_policy);
        $this->putJson('/api/v1/laboratory/validation-policy', ['validation_policy' => 'permission_based', 'laboratory_id' => $other->id])->assertUnprocessable();
        $this->assertSame('professional_required', $lab->fresh()->validation_policy);
        setPermissionsTeamId($lab->id);
        $actor->revokePermissionTo('results.validation_policy.manage');
        $this->putJson('/api/v1/laboratory/validation-policy', ['validation_policy' => 'permission_based'])->assertForbidden();
        $this->getJson('/api/v1/laboratory/validation-professionals')->assertOk();
    }

    public function test_demo_second_execution_preserves_validation_rectification_and_manual_policy_changes(): void
    {
        $this->seed(DatabaseSeeder::class);
        $tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename");
        $snapshot = function () use ($tables) {
            $data = [];
            foreach ($tables as $t) {
                $rows = DB::table($t->tablename)->get()->map(fn ($r) => (array) $r)->all();
                usort($rows, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
                $data[$t->tablename] = $rows;
            }

            return $data;
        };
        $first = $snapshot();
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($first, $snapshot());
        $this->assertDatabaseCount('laboratory_result_validations', 14);
        $this->assertSame(5, DB::table('laboratory_result_validations')->whereIn('laboratory_id', Laboratory::where('nit', 'like', 'DEMO-BE07-%')->select('id'))->count());
        $this->assertDatabaseCount('laboratory_result_rectifications', 2);
        $this->assertDatabaseCount('validation_professionals', 5);
        $lab = Laboratory::where('nit', 'DEMO-BE07-1')->sole();
        $admin = User::where('email', 'be07-admin-0@donqerlab.test')->sole();
        setPermissionsTeamId($lab->id);
        app(ValidationAdministrationService::class)->setPolicy($lab, $admin, ['validation_policy' => 'permission_based']);
        $changed = $snapshot();
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($changed, $snapshot());
    }
}

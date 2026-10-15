<?php

namespace Tests\Feature\ClinicalFinalization;

use App\ClinicalExams\ClinicalConflict;
use App\ClinicalFinalization\ClinicalResolutionService;
use App\ClinicalResults\LaboratoryResultService;
use App\ClinicalValidation\LaboratoryValidationService;
use App\ClinicalValidation\ValidationAdministrationService;
use App\Models\AuditLog;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\ClinicalResults\ClinicalResultScenario;
use Tests\TestCase;

class ClinicalFinalizationIntegrityTest extends TestCase
{
    use ClinicalResultScenario,RefreshDatabase;

    private function request(array $scenario): array
    {
        [$lab,$actor,$order,$line] = $scenario;

        return app(ClinicalResolutionService::class)->requestException($lab, $actor, $order->id, $line->id, ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 0, 'reason' => 'FICTICIO']);
    }

    private function reviewer($lab)
    {
        $actor = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermissions($actor, $lab, ['orders.clinical_exceptions.approve']);

        return $actor;
    }

    public function test_changed_capture_makes_request_obsolete_but_can_be_rejected_and_requested_again(): void
    {
        $scenario = $this->scenario();
        [$lab,$actor,$order,$line] = $scenario;
        $r = $this->request($scenario);
        $reviewer = $this->reviewer($lab);
        $service = app(ClinicalResolutionService::class);
        app(LaboratoryResultService::class)->save($lab, $actor, $order->id, $line->id, ['revision' => 0, 'results' => [['parameter_code' => 'N', 'value' => '2']]]);
        try {
            $service->decideException($lab, $reviewer, $order->id, $line->id, $r['exception']['id'], true, ['clinical_revision' => $r['clinical_revision'], 'request_revision' => 1]);
            $this->fail('Obsolete approval must fail');
        } catch (ClinicalConflict $e) {
            $this->assertSame('clinical_exception_obsolete', $e->errorCode);
        }
        $this->assertDatabaseCount('clinical_line_resolutions', 0);
        $reject = $service->decideException($lab, $reviewer, $order->id, $line->id, $r['exception']['id'], false, ['clinical_revision' => $r['clinical_revision'], 'request_revision' => 1, 'reason' => 'FICTICIO changed capture']);
        $service->requestException($lab, $actor, $order->id, $line->id, ['clinical_revision' => $reject['clinical_revision'], 'result_revision' => 1, 'reason' => 'FICTICIO reconsidered']);
        $this->assertDatabaseCount('clinical_exception_requests', 2);
        $this->assertDatabaseCount('clinical_exception_decisions', 1);
        $this->assertDatabaseCount('laboratory_order_results', 1);
    }

    public function test_current_and_historical_validations_cannot_be_hidden_by_exceptions(): void
    {
        [$lab,$actor,$order,$line] = $this->scenario();
        app(ValidationAdministrationService::class)->setPolicy($lab, $actor, ['validation_policy' => 'permission_based']);
        app(LaboratoryResultService::class)->save($lab, $actor, $order->id, $line->id, ['revision' => 0, 'results' => [['parameter_code' => 'N', 'value' => '1'], ['parameter_code' => 'T', 'value' => 'DEMO'], ['parameter_code' => 'Q', 'value' => 'YES'], ['parameter_code' => 'O', 'value' => 'A']]]);
        $validation = app(LaboratoryValidationService::class);
        $validation->validate($lab, $actor, $order->id, $line->id, ['revision' => 1, 'reference_context' => ['reference_date' => '2026-10-11']]);
        $base = "/api/v1/laboratory-orders/$order->id/exams/$line->id";
        $this->postJson($base.'/exceptions', ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 2, 'reason' => 'FICTICIO'])->assertConflict()->assertJsonPath('code', 'clinical_exception_not_available');
        setPermissionsTeamId($lab->id);
        $validation->rectify($lab, $actor, $order->id, $line->id, ['revision' => 2, 'reason' => 'FICTICIO']);
        $this->postJson($base.'/finalize', ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 3])->assertConflict()->assertJsonPath('code', 'clinical_line_not_validated');
        $this->postJson($base.'/exceptions', ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 3, 'reason' => 'FICTICIO'])->assertConflict()->assertJsonPath('code', 'clinical_exception_not_available');
        $this->assertDatabaseCount('laboratory_result_validations', 1);
        $this->assertDatabaseCount('clinical_exception_requests', 0);
    }

    #[DataProvider('protectedWrites')]
    public function test_sql_rejects_mutation_of_closed_evidence_and_composition(string $table, string $operation): void
    {
        $scenario = $this->scenario(false);
        [$lab,$actor,$order,$line] = $scenario;
        $r = $this->request($scenario);
        $reviewer = $this->reviewer($lab);
        $service = app(ClinicalResolutionService::class);
        $service->decideException($lab, $reviewer, $order->id, $line->id, $r['exception']['id'], true, ['clinical_revision' => $r['clinical_revision'], 'request_revision' => 1]);
        $service->finalizeOrder($lab, $actor, $order->id, ['clinical_revision' => $order->fresh()->clinical_revision]);
        DB::beginTransaction();
        try {
            $query = DB::table($table);
            if ($table === 'audit_logs') {
                $query->where('event', 'like', 'order_clinical.%');
            }
            if ($operation === 'delete') {
                $query->delete();
            } else {
                $values = match ($table) {
                    'laboratory_orders' => ['notes' => 'tampered'],'laboratory_order_exams' => ['exam_name' => 'tampered'],'clinical_exception_requests' => ['reason' => 'tampered'],'clinical_exception_decisions' => ['reason' => 'tampered'],'audit_logs' => ['event' => 'tampered'],default => ['evidence_snapshot' => '{}']
                };
                if ($table === 'clinical_order_finalizations') {
                    $values = ['lines_snapshot' => '[]'];
                }$query->update($values);
            }
            $this->fail('PostgreSQL must reject the write');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        } finally {
            DB::rollBack();
        }
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertDatabaseCount('clinical_order_finalizations', 1);
    }

    public static function protectedWrites(): array
    {
        $cases = [];
        foreach (['clinical_exception_requests', 'clinical_exception_decisions', 'clinical_line_resolutions', 'clinical_order_finalizations', 'laboratory_orders', 'laboratory_order_exams', 'audit_logs'] as $table) {
            foreach (['update', 'delete'] as $op) {
                $cases[$table.' '.$op] = [$table, $op];
            }
        }

        return $cases;
    }

    #[DataProvider('auditFailures')]
    public function test_late_audit_failure_rolls_back_clinical_transition(string $operation, string $event): void
    {
        $scenario = $this->scenario(false);
        [$lab,$actor,$order,$line] = $scenario;
        $service = app(ClinicalResolutionService::class);
        $reviewer = $this->reviewer($lab);
        $r = null;
        if ($operation !== 'request') {
            $r = $this->request($scenario);
        }
        if ($operation === 'close') {
            $service->decideException($lab, $reviewer, $order->id, $line->id, $r['exception']['id'], true, ['clinical_revision' => $r['clinical_revision'], 'request_revision' => 1]);
        }
        $before = [];
        foreach (['laboratory_orders', 'clinical_exception_requests', 'clinical_exception_decisions', 'clinical_line_resolutions', 'clinical_order_finalizations', 'audit_logs'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        AuditLog::creating(function ($audit) use ($event) {
            if ($audit->event === $event) {
                throw new \RuntimeException('Injected BE08 audit failure');
            }
        });
        try {
            match ($operation) {
                'request' => $this->request($scenario),'approve' => $service->decideException($lab, $reviewer, $order->id, $line->id, $r['exception']['id'], true, ['clinical_revision' => $r['clinical_revision'], 'request_revision' => 1]),'close' => $service->finalizeOrder($lab, $actor, $order->id, ['clinical_revision' => $order->fresh()->clinical_revision])
            };
            $this->fail('Audit should fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected BE08 audit failure', $e->getMessage());
        }
        foreach ($before as $table => $snapshot) {
            $this->assertSame($snapshot, DB::table($table)->orderBy('id')->get()->toJson());
        }
    }

    public static function auditFailures(): array
    {
        return [['request', 'order_clinical.exception_requested'], ['approve', 'order_clinical.exception_approved'], ['close', 'order.status_changed']];
    }

    public function test_empty_cancelled_and_unstarted_orders_cannot_finalize_and_permissions_are_separate(): void
    {
        [$lab,$actor,$order,$line] = $this->scenario(false);
        $base = "/api/v1/laboratory-orders/$order->id";
        $this->postJson($base."/exams/$line->id/finalize", ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 0])->assertConflict()->assertJsonPath('code', 'clinical_line_not_validated');
        setPermissionsTeamId($lab->id);
        $actor->revokePermissionTo('orders.clinical_exceptions.request');
        $this->postJson($base."/exams/$line->id/exceptions", ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 0, 'reason' => 'FICTICIO'])->assertForbidden();
        $empty = LaboratoryOrder::factory()->for($lab)->create(['status' => 'pending']);
        $this->postJson("/api/v1/laboratory-orders/$empty->id/finalize", ['clinical_revision' => 0])->assertUnprocessable();
        $order->update(['status' => 'cancelled']);
        $this->postJson($base.'/finalize', ['clinical_revision' => $order->fresh()->clinical_revision])->assertConflict()->assertJsonPath('code', 'clinical_order_not_open');
    }

    public function test_foreign_laboratory_and_wrong_order_line_are_not_disclosed(): void
    {
        [$lab,$actor,$order,$line] = $this->scenario(false);
        $other = Laboratory::factory()->create();
        $foreign = LaboratoryOrder::factory()->for($other)->create();
        $this->getJson("/api/v1/laboratory-orders/$foreign->id/clinical-finalization")->assertNotFound();
        $this->postJson("/api/v1/laboratory-orders/$foreign->id/exams/$line->id/exceptions", ['clinical_revision' => 0, 'result_revision' => 0, 'reason' => 'FICTICIO'])->assertNotFound();
        $this->assertDatabaseCount('clinical_exception_requests', 0);
    }

    public function test_composite_fk_rejects_foreign_lab_exception_and_sql_completion_without_evidence(): void
    {
        [$lab,$actor,$order,$line] = $this->scenario(false);
        $foreign = Laboratory::factory()->create();
        DB::beginTransaction();
        try {
            DB::table('clinical_exception_requests')->insert(['laboratory_id' => $foreign->id, 'laboratory_order_id' => $order->id, 'laboratory_order_exam_id' => $line->id, 'revision' => 1, 'status' => 'pending', 'reason' => 'FICTICIO', 'requested_by' => $actor->id, 'requested_at' => now(), 'capture_revision' => 0, 'clinical_configuration_source' => 'published_version']);
            $this->fail('Tenant FK must reject this request');
        } catch (QueryException $e) {
            $this->assertSame('23503', $e->errorInfo[0]);
        } finally {
            DB::rollBack();
        }
        DB::beginTransaction();
        try {
            DB::table('laboratory_orders')->where('id', $order->id)->update(['status' => 'completed', 'clinical_revision' => 2]);
            $this->fail('Completion without evidence must fail');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        } finally {
            DB::rollBack();
        }
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertDatabaseCount('clinical_exception_requests', 0);
    }

    public function test_line_finalization_audit_failure_restores_resolution_and_revision(): void
    {
        [$lab,$actor,$order,$line] = $this->scenario();
        app(ValidationAdministrationService::class)->setPolicy($lab, $actor, ['validation_policy' => 'permission_based']);
        app(LaboratoryResultService::class)->save($lab, $actor, $order->id, $line->id, ['revision' => 0, 'results' => [['parameter_code' => 'N', 'value' => '1'], ['parameter_code' => 'T', 'value' => 'DEMO'], ['parameter_code' => 'Q', 'value' => 'YES'], ['parameter_code' => 'O', 'value' => 'A']]]);
        app(LaboratoryValidationService::class)->validate($lab, $actor, $order->id, $line->id, ['revision' => 1, 'reference_context' => ['reference_date' => '2026-10-11']]);
        $revision = $order->fresh()->clinical_revision;
        $audits = AuditLog::count();
        AuditLog::creating(function ($event) {
            if ($event->event === 'order_clinical.line_finalized') {
                throw new \RuntimeException('BE08 line audit failure');
            }
        });
        try {
            app(ClinicalResolutionService::class)->finalizeLine($lab, $actor, $order->id, $line->id, ['clinical_revision' => $revision, 'result_revision' => 2]);
            $this->fail('Audit should fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('BE08 line audit failure', $e->getMessage());
        }
        $this->assertDatabaseCount('clinical_line_resolutions', 0);
        $this->assertSame($revision, $order->fresh()->clinical_revision);
        $this->assertSame($audits, AuditLog::count());
        $this->assertDatabaseHas('laboratory_order_result_captures', ['laboratory_order_exam_id' => $line->id, 'revision' => 2, 'status' => 'validated']);
    }

    #[DataProvider('deniedOperations')]
    public function test_new_permissions_are_independent_of_existing_order_and_result_permissions(string $method, string $suffix, array $body): void
    {
        $scenario = $this->scenario(false);
        [$lab,$actor,$order,$line] = $scenario;
        $request = $this->request($scenario);
        $reader = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermissions($reader, $lab, ['orders.view', 'results.view', 'results.validate']);
        $suffix = str_replace(['{line}', '{request}'], [$line->id, $request['exception']['id']], $suffix);
        $this->actingAs($reader)->json($method, '/api/v1/laboratory-orders/'.$order->id.$suffix, $body)->assertForbidden();
        $this->assertDatabaseCount('clinical_line_resolutions', 0);
        $this->assertDatabaseCount('clinical_exception_requests', 1);
    }

    public static function deniedOperations(): array
    {
        return [
            'line finalize' => ['POST', '/exams/{line}/finalize', ['clinical_revision' => 2, 'result_revision' => 0]],
            'order close' => ['POST', '/finalize', ['clinical_revision' => 2]],
            'request' => ['POST', '/exams/{line}/exceptions', ['clinical_revision' => 2, 'result_revision' => 0, 'reason' => 'FICTICIO']],
            'approve' => ['POST', '/exams/{line}/exceptions/{request}/approve', ['clinical_revision' => 2, 'request_revision' => 1]],
            'reject' => ['POST', '/exams/{line}/exceptions/{request}/reject', ['clinical_revision' => 2, 'request_revision' => 1, 'reason' => 'FICTICIO']],
            'exceptions read' => ['GET', '/exams/{line}/exceptions', []],
            'closure read' => ['GET', '/clinical-finalization', []],
        ];
    }
}

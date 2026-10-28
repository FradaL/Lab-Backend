<?php

namespace Tests\Feature\ClinicalReasons;

use App\ClinicalExams\GlobalClinicalReasonImpactManager as Manager;
use App\Models\GlobalClinicalReasonImpact;
use App\Models\GlobalClinicalReasonVersion;
use App\Models\SaasAuditLog;
use App\Models\User;
use App\UserAdministration\SaasAccessManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class GlobalClinicalReasonImpactTest extends TestCase
{
    use ClinicalReasonAdoptionScenario, RefreshDatabase;

    private function path(array $global): string
    {
        return '/api/v1/platform/clinical-reasons/'.$global['reason']->id.'/versions/'.$global['version']->id.'/impact';
    }

    public function test_missing_declaration_is_unclassified_and_reading_does_not_infer_or_write(): void
    {
        [$operator] = $this->operator();
        $global = $this->globalReason($operator);
        $before = SaasAuditLog::count();
        $this->actingAs($operator)->withHeader('X-Laboratory-ID', '999999')->getJson($this->path($global))->assertOk()->assertJsonPath('data.classification', 'unclassified')->assertJsonPath('data.declaration', null);
        $this->assertDatabaseCount('global_clinical_reason_impacts', 0);
        $this->assertSame($before, SaasAuditLog::count());
    }

    public function test_declaring_and_correcting_impact_preserves_versions_and_audited_predecessors(): void
    {
        [$operator,$admin] = $this->operator();
        $global = $this->globalReason($operator);
        $before = $global['version']->getRawOriginal();
        $path = $this->path($global);
        $first = $this->actingAs($operator)->postJson($path.'/declarations', ['expected_revision' => 0, 'classification' => 'restrictive', 'justification' => 'FICTICIO: evaluación global explícita.'])->assertCreated()->assertJsonPath('data.revision', 1);
        $this->postJson($path.'/declarations', ['expected_revision' => 1, 'classification' => 'non_restrictive', 'justification' => 'FICTICIO: corrección fundamentada.'])->assertCreated()->assertJsonPath('data.previous_id', $first->json('data.id'))->assertJsonPath('data.revision', 2);
        $this->getJson($path)->assertOk()->assertJsonPath('data.classification', 'non_restrictive');
        $this->getJson($path.'/declarations')->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertSame($before, GlobalClinicalReasonVersion::findOrFail($global['version']->id)->getRawOriginal());
        $this->assertDatabaseHas('saas_audit_logs', ['global_impact_declaration_id' => $first->json('data.id'), 'actor_id' => $operator->id, 'capability_code' => Manager::CAPABILITY, 'result' => 'succeeded', 'global_reason_id' => null]);
        $this->actingAs($admin)->getJson('/api/v1/platform/access/history')->assertOk()->assertJsonPath('meta.total', 6);
    }

    public function test_global_and_tenant_administrators_do_not_inherit_classification_authority(): void
    {
        [$operator,$admin] = $this->operator();
        $global = $this->globalReason($operator);
        $this->actingAs($admin)->getJson($this->path($global))->assertForbidden();
        $this->postJson($this->path($global).'/declarations', [])->assertForbidden();
        try {
            app(Manager::class)->declare($admin, $global['reason']->id, $global['version']->id, []);
            $this->fail('Service must authorize.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        foreach (['owner', 'administrator'] as $role) {
            [$lab,$user] = $this->tenant($role);
            $this->actingAs($user)->withHeader('X-Laboratory-ID', (string) $lab->id)->getJson($this->path($global))->assertForbidden();
        }
        $this->assertDatabaseCount('global_clinical_reason_impacts', 0);
    }

    public function test_revoked_classifier_is_forbidden_with_no_new_declaration(): void
    {
        [$operator,$admin] = $this->operator();
        $global = $this->globalReason($operator);
        $assignment = DB::table('saas_capability_assignments')->where('user_id', $operator->id)->where('capability_code', Manager::CAPABILITY)->first();
        app(SaasAccessManager::class)->revoke($admin, $operator->id, $assignment->id, ['revision' => 1, 'reason' => 'FICTICIO']);
        $this->actingAs($operator)->postJson($this->path($global).'/declarations', ['expected_revision' => 0, 'classification' => 'restrictive', 'justification' => 'FICTICIO'])->assertForbidden();
        $this->assertDatabaseCount('global_clinical_reason_impacts', 0);
    }

    public function test_classify_capability_alone_is_sufficient_without_granting_other_saas_powers(): void
    {
        [$operator, $admin] = $this->operator();
        $global = $this->globalReason($operator);
        $classifier = User::factory()->create();
        app(SaasAccessManager::class)->grant($admin, $classifier->id, ['capability' => Manager::CAPABILITY, 'reason' => 'FICTICIO: facultad independiente.']);
        $this->actingAs($classifier)->postJson($this->path($global).'/declarations', ['expected_revision' => 0, 'classification' => 'non_restrictive', 'justification' => 'FICTICIO: clasificación explícita.'])->assertCreated();
        $this->getJson('/api/v1/platform/clinical-reasons')->assertForbidden();
        $this->getJson('/api/v1/platform/access/users')->assertForbidden();
    }

    public function test_unauthenticated_and_invalid_or_stale_declarations_do_not_write(): void
    {
        [$operator] = $this->operator();
        $global = $this->globalReason($operator);
        $path = $this->path($global);
        $this->getJson($path)->assertUnauthorized();
        $this->actingAs($operator)->postJson($path.'/declarations', [])->assertUnprocessable();
        $body = ['expected_revision' => 0, 'classification' => 'restrictive', 'justification' => 'FICTICIO'];
        $this->postJson($path.'/declarations', $body + ['created_by' => 999])->assertUnprocessable();
        $this->postJson($path.'/declarations', array_replace($body, ['expected_revision' => '0']))->assertUnprocessable();
        $this->postJson($path.'/declarations', array_replace($body, ['classification' => 'automatic']))->assertUnprocessable();
        $this->postJson($path.'/declarations', $body)->assertCreated();
        $this->postJson($path.'/declarations', $body)->assertConflict()->assertJsonPath('code', 'clinical_reason_impact_revision_conflict');
        $this->getJson('/api/v1/platform/clinical-reasons/999999/versions/'.$global['version']->id.'/impact')->assertNotFound();
        $this->assertDatabaseCount('global_clinical_reason_impacts', 1);
    }

    public function test_audit_failure_rolls_back_the_global_declaration(): void
    {
        [$operator] = $this->operator();
        $global = $this->globalReason($operator);
        $count = SaasAuditLog::count();
        SaasAuditLog::created(fn () => throw new \RuntimeException('FICTICIO audit failure'));
        try {
            $this->declareImpact($operator, $global, 'restrictive');
            $this->fail('Must fail atomically.');
        } catch (\RuntimeException $e) {
            $this->assertSame('FICTICIO audit failure', $e->getMessage());
        } finally {
            SaasAuditLog::flushEventListeners();
            SaasAuditLog::clearBootedModels();
        }
        $this->assertDatabaseCount('global_clinical_reason_impacts', 0);
        $this->assertSame($count, SaasAuditLog::count());
    }

    public function test_database_prevents_mutating_or_deleting_declarations(): void
    {
        [$operator] = $this->operator();
        $global = $this->globalReason($operator);
        $impact = $this->declareImpact($operator, $global)['data'];
        foreach (['update', 'delete'] as $operation) {
            try {
                DB::transaction(fn () => $operation === 'update' ? DB::table('global_clinical_reason_impacts')->where('id', $impact->id)->update(['classification' => 'restrictive']) : DB::table('global_clinical_reason_impacts')->where('id', $impact->id)->delete());
                $this->fail('Must preserve history.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
            }
        }
        $this->assertSame('non_restrictive', GlobalClinicalReasonImpact::findOrFail($impact->id)->classification);
    }

    public function test_database_refuses_an_impact_without_its_required_audit_at_commit(): void
    {
        [$operator] = $this->operator();
        $global = $this->globalReason($operator);
        try {
            DB::transaction(function () use ($operator, $global): void {
                GlobalClinicalReasonImpact::create(['reason_id' => $global['reason']->id, 'version_id' => $global['version']->id, 'from_version_id' => null, 'revision' => 1, 'previous_id' => null, 'classification' => 'restrictive', 'justification' => 'FICTICIO', 'created_by' => $operator->id]);
                DB::statement('SET CONSTRAINTS require_global_impact_audit IMMEDIATE');
            });
            $this->fail('Audit is required before commit.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $this->assertDatabaseCount('global_clinical_reason_impacts', 0);
    }
}

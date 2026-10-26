<?php

namespace Tests\Feature\ClinicalReasons;

use App\Authorization\ClinicalReasonTenantAuthority as Tenant;
use App\ClinicalExams\ClinicalConflict;
use App\ClinicalExams\ClinicalReasonAdoptionManager as Manager;
use App\ClinicalExams\ClinicalReasonEligibilityPolicy as Policy;
use App\ClinicalExams\GlobalClinicalReasonManager as GlobalManager;
use App\Http\Controllers\Api\V1\ClinicalReasonAdoptionController;
use App\Http\Controllers\Api\V1\GlobalClinicalReasonImpactController;
use App\Models\AuditLog;
use App\Models\ClinicalReasonAdoption;
use App\Models\ClinicalReasonAdoptionVersion;
use App\Models\ClinicalReasonImpactReview;
use App\Models\SaasAuditLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class ClinicalReasonAdoptionTest extends TestCase
{
    use ClinicalReasonAdoptionScenario, RefreshDatabase;

    private const BASE = '/api/v1/laboratory/clinical-reason-adoptions';

    private function resolution(string $outcome = 'favorable', int $revision = 0): array
    {
        return ['expected_revision' => $revision, 'outcome' => $outcome, 'assessment' => 'FICTICIO: evaluación clínica documentada de las restricciones y su aplicabilidad.', 'justification' => 'FICTICIO: fundamento de la evaluación.'];
    }

    public function test_selection_is_individual_and_updates_never_overwrite_the_adopted_version(): void
    {
        [$operator] = $this->operator();
        $first = $this->globalReason($operator);
        $this->globalReason($operator, 'omitido');
        [$lab,$owner] = $this->tenant();
        $this->assertDatabaseCount('clinical_reason_adoptions', 0);
        $r = $this->actingAs($owner)->withHeader('X-Laboratory-ID', (string) $lab->id)->postJson(self::BASE, ['reason_id' => $first['reason']->id, 'version_id' => $first['version']->id, 'justification' => 'FICTICIO'])->assertCreated();
        $id = $r->json('data.adoption.id');
        $original = ClinicalReasonAdoptionVersion::sole()->getRawOriginal();
        $new = app(GlobalManager::class)->publish($operator, $first['reason']->id, ['expected_number' => 1, 'definition' => $this->definition(['name' => 'FICTICIO: nueva versión'])])['data'];
        $this->assertSame($first['version']->id, ClinicalReasonAdoption::findOrFail($id)->latestVersion->version_id);
        $this->getJson(self::BASE.'/'.$id.'/updates')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.declared_impact', 'unclassified')->assertJsonPath('data.0.differences.name.available', 'FICTICIO: nueva versión');
        $this->postJson(self::BASE.'/'.$id.'/versions', ['expected_revision' => 1, 'version_id' => $new['version']->id, 'justification' => 'FICTICIO: adopción explícita'])->assertCreated()->assertJsonPath('data.selection.revision', 2);
        $this->getJson(self::BASE.'/'.$id.'/versions')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson(self::BASE)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/laboratory/clinical-reasons/global')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/laboratory/clinical-reasons/global/'.$first['reason']->id)->assertOk();
        $this->getJson('/api/v1/laboratory/clinical-reasons/global/'.$first['reason']->id.'/versions')->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertSame($original, ClinicalReasonAdoptionVersion::findOrFail($original['id'])->getRawOriginal());
        $this->assertDatabaseCount('laboratory_clinical_reasons', 0);
        $this->assertSame(2, AuditLog::where('laboratory_id', $lab->id)->whereIn('event', ['clinical_reason.adopted', 'clinical_reason.adoption_updated'])->count());
    }

    public function test_restrictive_change_creates_pending_obligations_and_only_favorable_evaluation_unblocks(): void
    {
        [$operator] = $this->operator();
        $global = $this->globalReason($operator);
        $this->declareImpact($operator, $global);
        [$lab,$owner] = $this->tenant();
        $selection = $this->selectReason($lab, $owner, $global);
        $id = $selection['adoption']->id;
        $reviewer = $this->reviewer($lab);
        $this->declareImpact($operator, $global, 'restrictive', 1);
        $review = ClinicalReasonImpactReview::sole();
        $this->assertSame('pending', $review->status);
        $this->assertSame($global['version']->id, $review->adopted_version_id);
        $this->actingAs($owner)->withHeader('X-Laboratory-ID', (string) $lab->id)->getJson(self::BASE.'/'.$id.'/eligibility')->assertOk()->assertJsonPath('data.can_request', false)->assertJsonPath('data.can_approve', false);
        $url = self::BASE.'/'.$id.'/reviews/'.$review->id.'/resolutions';
        $this->postJson($url, $this->resolution())->assertForbidden();
        $this->actingAs($reviewer)->postJson($url, ['expected_revision' => 0, 'outcome' => 'favorable', 'justification' => 'FICTICIO'])->assertUnprocessable()->assertJsonValidationErrors('assessment');
        $this->postJson($url, $this->resolution('non_favorable'))->assertCreated()->assertJsonPath('review_status', 'pending');
        $this->getJson(self::BASE.'/'.$id.'/eligibility')->assertOk()->assertJsonPath('data.can_request', false);
        $this->postJson($url, $this->resolution('favorable', 1))->assertCreated()->assertJsonPath('review_status', 'resolved');
        $this->getJson($url)->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson(self::BASE.'/'.$id.'/reviews')->assertOk()->assertJsonPath('data.0.status', 'resolved');
        $this->getJson(self::BASE.'/'.$id.'/eligibility')->assertOk()->assertJsonPath('data.can_request', true)->assertJsonPath('data.can_approve', true);
        $this->postJson($url, $this->resolution('favorable', 2))->assertConflict();
        $this->assertDatabaseHas('audit_logs', ['laboratory_id' => $lab->id, 'user_id' => $reviewer->id, 'event' => 'clinical_reason.review_evaluated']);
        $this->getJson('/api/v1/platform/access/users')->assertForbidden();
        $this->getJson('/api/v1/platform/clinical-reasons')->assertForbidden();
    }

    public function test_changing_adoption_or_correcting_impact_does_not_remove_a_review_obligation(): void
    {
        [$operator] = $this->operator();
        $global = $this->globalReason($operator);
        $this->declareImpact($operator, $global);
        [$lab,$owner] = $this->tenant();
        $selection = $this->selectReason($lab, $owner, $global);
        $id = $selection['adoption']->id;
        $new = app(GlobalManager::class)->publish($operator, $global['reason']->id, ['expected_number' => 1, 'definition' => $this->definition()])['data'];
        $decl = $this->declareImpact($operator, $new, 'restrictive');
        $review = ClinicalReasonImpactReview::sole();
        $this->setPermissionTeam($lab);
        app(Manager::class)->update($lab, $owner, $id, ['expected_revision' => 1, 'version_id' => $new['version']->id, 'justification' => 'FICTICIO']);
        $this->declareImpact($operator, $new, 'non_restrictive', 1);
        $this->assertDatabaseCount('clinical_reason_impact_reviews', 1);
        $this->assertSame($decl['data']->id, $review->fresh()->impact_id);
        $this->assertSame('pending', $review->fresh()->status);
        $this->setPermissionTeam($lab);
        $status = app(Policy::class)->eligibility($lab, $owner, $id);
        $this->assertFalse($status['can_approve']);
        $this->assertSame([$review->id], $status['pending_review_ids']);
    }

    public function test_first_adoption_of_an_old_version_detects_current_restrictions_without_auto_update(): void
    {
        [$operator] = $this->operator();
        $global = $this->globalReason($operator);
        $this->declareImpact($operator, $global);
        $new = app(GlobalManager::class)->publish($operator, $global['reason']->id, ['expected_number' => 1, 'definition' => $this->definition()])['data'];
        $this->declareImpact($operator, $new, 'restrictive');
        [$lab,$owner] = $this->tenant();
        $selection = $this->selectReason($lab, $owner, $global);
        $this->assertSame($global['version']->id, $selection['selection']->version_id);
        $this->assertDatabaseCount('clinical_reason_impact_reviews', 1);
        $status = app(Policy::class)->eligibility($lab, $owner, $selection['adoption']->id);
        $this->assertFalse($status['can_request']);
        $this->assertFalse($status['can_approve']);
    }

    public function test_unclassified_allows_administrative_adoption_but_never_authorizes_clinical_use(): void
    {
        [$operator] = $this->operator();
        $global = $this->globalReason($operator);
        [$lab,$owner] = $this->tenant();
        $selection = $this->selectReason($lab, $owner, $global);
        $status = app(Policy::class)->eligibility($lab, $owner, $selection['adoption']->id);
        $this->assertSame(['impact_unclassified'], $status['approval_blockers']);
        $this->assertDatabaseCount('clinical_reason_impact_reviews', 0);
        $this->declareImpact($operator, $global);
        $this->assertTrue(app(Policy::class)->eligibility($lab, $owner, $selection['adoption']->id)['can_approve']);
    }

    public function test_deactivation_creates_review_without_inventing_a_restrictive_classification(): void
    {
        [$operator] = $this->operator();
        $global = $this->globalReason($operator);
        $this->declareImpact($operator, $global);
        [$lab,$owner] = $this->tenant();
        $selection = $this->selectReason($lab, $owner, $global);
        $inactive = app(GlobalManager::class)->activation($operator, $global['reason']->id, ['expected_number' => 1, 'is_active' => false, 'change_reason' => 'FICTICIO: motivo de desactivación'])['data'];
        $review = ClinicalReasonImpactReview::sole();
        $this->assertSame('deactivation', $review->cause_type);
        $this->assertNull($review->impact_id);
        $this->declareImpact($operator, $inactive, 'non_restrictive');
        $reviewer = $this->reviewer($lab);
        app(Manager::class)->resolve($lab, $reviewer, $selection['adoption']->id, $review->id, $this->resolution());
        $status = app(Policy::class)->eligibility($lab, $reviewer, $selection['adoption']->id);
        $this->assertFalse($status['can_request']);
        $this->assertTrue($status['can_approve']);
        $this->assertSame(['reason_inactive'], $status['request_blockers']);
    }

    public function test_reusable_policy_blocks_only_dependent_request_and_approval_operations(): void
    {
        [$operator] = $this->operator();
        $a = $this->globalReason($operator);
        $b = $this->globalReason($operator, 'independiente');
        $this->declareImpact($operator, $a);
        $this->declareImpact($operator, $b);
        [$lab,$owner] = $this->tenant();
        $sa = $this->selectReason($lab, $owner, $a);
        $sb = $this->selectReason($lab, $owner, $b);
        $clinical = $this->reviewer($lab);
        $this->declareImpact($operator, $a, 'restrictive', 1);
        foreach (['request', 'approve'] as $op) {
            try {
                app(Policy::class)->assertAllowed($lab, $clinical, $sa['adoption']->id, $op);
                $this->fail('Dependent operation must block.');
            } catch (ClinicalConflict $e) {
                $this->assertSame('clinical_reason_use_blocked', $e->errorCode);
            }
            app(Policy::class)->assertAllowed($lab, $clinical, $sb['adoption']->id, $op);
        }
        $this->assertDatabaseCount('clinical_exception_requests', 0);
        $this->actingAs($owner)->withHeader('X-Laboratory-ID', (string) $lab->id)->getJson(self::BASE.'/'.$sa['adoption']->id.'/versions')->assertOk();
    }

    public function test_cross_tenant_adoptions_reviews_and_versions_are_not_visible_or_mutable(): void
    {
        [$operator] = $this->operator();
        $g = $this->globalReason($operator);
        $this->declareImpact($operator, $g, 'restrictive');
        [$a,$ownerA] = $this->tenant();
        $selection = $this->selectReason($a, $ownerA, $g);
        $review = ClinicalReasonImpactReview::sole();
        [$b,$ownerB] = $this->tenant();
        $this->assignDirectLaboratoryPermission($ownerB, $b, Tenant::REVIEW);
        $this->actingAs($ownerB)->withHeader('X-Laboratory-ID', (string) $b->id)->getJson(self::BASE)->assertOk()->assertJsonPath('meta.total', 0);
        foreach (['versions', 'updates', 'eligibility', 'reviews'] as $suffix) {
            $this->getJson(self::BASE.'/'.$selection['adoption']->id.'/'.$suffix)->assertNotFound();
        }
        $this->postJson(self::BASE.'/'.$selection['adoption']->id.'/reviews/'.$review->id.'/resolutions', $this->resolution())->assertNotFound();
        $this->postJson(self::BASE.'/'.$selection['adoption']->id.'/versions', ['expected_revision' => 1, 'version_id' => $g['version']->id, 'justification' => 'FICTICIO'])->assertNotFound();
        $this->assertDatabaseCount('clinical_reason_impact_resolutions', 0);
    }

    public function test_permissions_context_and_membership_are_rechecked_in_services(): void
    {
        [$operator] = $this->operator();
        $g = $this->globalReason($operator);
        [$lab,$owner] = $this->tenant();
        $selection = $this->selectReason($lab, $owner, $g);
        $this->getJson(self::BASE)->assertUnauthorized();
        $this->actingAs($owner)->getJson(self::BASE)->assertStatus(400);
        [$other,$outsider] = $this->tenant('administrator');
        $this->actingAs($outsider)->withHeader('X-Laboratory-ID', (string) $lab->id)->getJson(self::BASE)->assertForbidden();
        $this->setPermissionTeam($lab);
        DB::table('laboratory_user')->where('laboratory_id', $lab->id)->where('user_id', $owner->id)->update(['is_active' => false]);
        try {
            app(Manager::class)->index($lab, $owner);
            $this->fail('Fresh membership required.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertDatabaseCount('clinical_reason_adoption_versions', 1);
    }

    public function test_non_owner_with_adopt_permission_cannot_adopt_and_owner_does_not_inherit_review(): void
    {
        [$operator] = $this->operator();
        $g = $this->globalReason($operator);
        [$lab,$admin] = $this->tenant('administrator');
        $this->assignDirectLaboratoryPermission($admin, $lab, Tenant::ADOPT);
        $this->actingAs($admin)->withHeader('X-Laboratory-ID', (string) $lab->id)->postJson(self::BASE, ['reason_id' => $g['reason']->id, 'version_id' => $g['version']->id, 'justification' => 'FICTICIO'])->assertForbidden();
        $this->assertFalse($admin->can(Tenant::REVIEW));
        $this->assertDatabaseCount('clinical_reason_adoptions', 0);
    }

    #[DataProvider('failures')]
    public function test_each_tenant_audit_failure_rolls_back_its_operation(string $operation): void
    {
        [$operator] = $this->operator();
        $g = $this->globalReason($operator);
        [$lab,$owner] = $this->tenant();
        $selection = $operation === 'adopt' ? null : $this->selectReason($lab, $owner, $g);
        $new = app(GlobalManager::class)->publish($operator, $g['reason']->id, ['expected_number' => 1, 'definition' => $this->definition()])['data'];
        if ($operation === 'resolve') {
            $this->declareImpact($operator, $g, 'restrictive');
            $reviewer = $this->reviewer($lab);
        }
        $before = AuditLog::count();
        AuditLog::created(fn () => throw new \RuntimeException('FICTICIO tenant audit failure'));
        try {
            $this->setPermissionTeam($lab);
            match ($operation) {
                'adopt' => $this->selectReason($lab, $owner, $g),
                'update' => app(Manager::class)->update($lab, $owner, $selection['adoption']->id, ['expected_revision' => 1, 'version_id' => $new['version']->id, 'justification' => 'FICTICIO']),
                'resolve' => app(Manager::class)->resolve($lab, $reviewer, $selection['adoption']->id, ClinicalReasonImpactReview::sole()->id, $this->resolution()),
            };
            $this->fail('Must roll back.');
        } catch (\RuntimeException $e) {
            $this->assertSame('FICTICIO tenant audit failure', $e->getMessage());
        } finally {
            AuditLog::flushEventListeners();
            AuditLog::clearBootedModels();
        }
        $this->assertSame($before, AuditLog::count());
        $this->assertDatabaseCount('clinical_reason_adoption_versions', $operation === 'adopt' ? 0 : 1);
        $this->assertDatabaseCount('clinical_reason_impact_resolutions', 0);
    }

    public static function failures(): array
    {
        return [['adopt'], ['update'], ['resolve']];
    }

    public function test_fanout_audit_failure_rolls_back_both_global_impact_and_tenant_obligations(): void
    {
        [$operator] = $this->operator();
        $g = $this->globalReason($operator);
        [$lab,$owner] = $this->tenant();
        $this->selectReason($lab, $owner, $g);
        $before = SaasAuditLog::count();
        AuditLog::created(fn () => throw new \RuntimeException('FICTICIO obligation audit failure'));
        try {
            $this->declareImpact($operator, $g, 'restrictive');
            $this->fail('Must roll back global write.');
        } catch (\RuntimeException $e) {
            $this->assertSame('FICTICIO obligation audit failure', $e->getMessage());
        } finally {
            AuditLog::flushEventListeners();
            AuditLog::clearBootedModels();
        }
        $this->assertDatabaseCount('global_clinical_reason_impacts', 0);
        $this->assertDatabaseCount('clinical_reason_impact_reviews', 0);
        $this->assertSame($before, SaasAuditLog::count());
    }

    public function test_conflicts_validation_and_sql_guards_preserve_history(): void
    {
        [$operator] = $this->operator();
        $g = $this->globalReason($operator);
        [$lab,$owner] = $this->tenant();
        $s = $this->selectReason($lab, $owner, $g);
        $id = $s['adoption']->id;
        $this->actingAs($owner)->withHeader('X-Laboratory-ID', (string) $lab->id)->postJson(self::BASE, ['reason_id' => $g['reason']->id, 'version_id' => $g['version']->id, 'justification' => 'FICTICIO'])->assertConflict();
        $this->postJson(self::BASE.'/'.$id.'/versions', ['expected_revision' => 2, 'version_id' => $g['version']->id, 'justification' => 'FICTICIO'])->assertConflict();
        $this->postJson(self::BASE.'/'.$id.'/versions', ['expected_revision' => '1', 'version_id' => $g['version']->id, 'justification' => 'FICTICIO'])->assertUnprocessable();
        $this->postJson(self::BASE, ['reason_id' => $g['reason']->id, 'version_id' => $g['version']->id, 'justification' => 'FICTICIO', 'laboratory_id' => 999])->assertUnprocessable();
        $this->postJson(self::BASE, ['reason_id' => 999999, 'version_id' => $g['version']->id, 'justification' => 'FICTICIO'])->assertNotFound();
        try {
            DB::transaction(fn () => DB::table('clinical_reason_adoption_versions')->where('adoption_id', $id)->update(['version_id' => 999999]));
            $this->fail('Must be immutable.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $this->assertDatabaseCount('clinical_reason_adoption_versions', 1);
    }

    public function test_clinical_review_permission_is_explicit_and_isolated_across_a_b_a(): void
    {
        [$operator] = $this->operator();
        $global = $this->globalReason($operator);
        [$a, $owner] = $this->tenant();
        $sa = $this->selectReason($a, $owner, $global);
        [$b] = $this->tenant('owner', $owner);
        $sb = $this->selectReason($b, $owner, $global);
        $this->declareImpact($operator, $global, 'restrictive');
        $ra = ClinicalReasonImpactReview::where('laboratory_id', $a->id)->sole();
        $rb = ClinicalReasonImpactReview::where('laboratory_id', $b->id)->sole();
        $this->assignDirectLaboratoryPermission($owner, $a, Tenant::REVIEW);
        $path = fn ($adoption, $review) => self::BASE.'/'.$adoption.'/reviews/'.$review.'/resolutions';
        $this->actingAs($owner)->withHeader('X-Laboratory-ID', (string) $b->id)->postJson($path($sb['adoption']->id, $rb->id), $this->resolution())->assertForbidden();
        $this->withHeader('X-Laboratory-ID', (string) $a->id)->postJson($path($sa['adoption']->id, $ra->id), $this->resolution())->assertCreated();
        $this->withHeader('X-Laboratory-ID', (string) $b->id)->postJson($path($sb['adoption']->id, $rb->id), $this->resolution())->assertForbidden();
        $this->assertSame('pending', $rb->fresh()->status);
    }

    public function test_openapi_and_routes_require_exact_authority_for_all_fifteen_new_operations(): void
    {
        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $controllers = [ClinicalReasonAdoptionController::class, GlobalClinicalReasonImpactController::class];
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => in_array($route->getControllerClass(), $controllers, true));
        $this->assertCount(15, $routes);
        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            $operation = $document['paths']['/'.$route->uri()][strtolower($route->methods()[0])];
            $this->assertSame([['sanctumCookie' => []]], $operation['security']);
            if (isset($operation['x-saas-capability'])) {
                $this->assertContains('saas.capability:'.$operation['x-saas-capability'], $middleware);
                $this->assertNotContains('saas', $middleware);
            } else {
                $this->assertContains('saas', $middleware);
                $this->assertContains('can:'.$operation['x-tenant-permission'], $middleware);
                $header = collect($operation['parameters'])->firstWhere('name', 'X-Laboratory-ID');
                $this->assertTrue($header['required']);
            }
            foreach ([401, 403, 404, 409, 422] as $status) {
                $this->assertArrayHasKey($status, $operation['responses']);
            }
        }
    }

    public function test_service_rejects_revoked_clinical_permission_even_with_cached_user_relations(): void
    {
        [$operator] = $this->operator();
        $global = $this->globalReason($operator);
        [$lab, $owner] = $this->tenant();
        $selection = $this->selectReason($lab, $owner, $global);
        $this->declareImpact($operator, $global, 'restrictive');
        $reviewer = $this->reviewer($lab);
        $this->assertTrue($reviewer->can(Tenant::REVIEW));
        $permissionId = DB::table('permissions')->where('name', Tenant::REVIEW)->value('id');
        DB::table('model_has_permissions')->where('laboratory_id', $lab->id)->where('model_id', $reviewer->id)->where('permission_id', $permissionId)->delete();
        try {
            app(Manager::class)->resolve($lab, $reviewer, $selection['adoption']->id, ClinicalReasonImpactReview::sole()->id, $this->resolution());
            $this->fail('A stale user must not retain clinical authority.');
        } catch (AuthorizationException $e) {
            $this->assertSame('This action is unauthorized.', $e->getMessage());
        }
        $this->assertDatabaseCount('clinical_reason_impact_resolutions', 0);
    }
}

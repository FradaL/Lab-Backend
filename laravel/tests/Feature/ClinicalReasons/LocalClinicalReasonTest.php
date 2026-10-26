<?php

namespace Tests\Feature\ClinicalReasons;

use App\Actions\LaboratoryOrders\AddExamToLaboratoryOrder;
use App\Authorization\ClinicalReasonTenantAuthority as Authority;
use App\ClinicalExams\ClinicalReasonVersionStore;
use App\ClinicalExams\LocalClinicalReasonManager;
use App\ClinicalFinalization\ClinicalResolutionService;
use App\Models\AuditLog;
use App\Models\ClinicalExceptionLocalReasonReference;
use App\Models\LaboratoryClinicalReasonVersion;
use App\Models\LocalClinicalReasonReview;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\ClinicalResults\ClinicalResultScenario;
use Tests\TestCase;

class LocalClinicalReasonTest extends TestCase
{
    use ClinicalResultScenario, DatabaseMigrations;

    private const BASE = '/api/v1/laboratory/clinical-reasons/local';

    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh');
        $this->beforeApplicationDestroyed(fn () => RefreshDatabaseState::$migrated = false);
    }

    private function definition(array $changes = []): array
    {
        return array_replace(['name' => 'FICTICIO: motivo local', 'description' => 'FICTICIO: definición del laboratorio', 'classification' => 'terminal_candidate',
            'evidence_requirements' => ['schema_version' => 1, 'requirements' => []], 'is_active' => true, 'change_reason' => 'FICTICIO: inicial'], $changes);
    }

    private function prepared(string $code = 'ficticio'): array
    {
        [$lab, $owner, $order, $line] = $this->scenario(false);
        $this->assignLaboratoryRole($owner, $lab, 'owner');
        $data = app(LocalClinicalReasonManager::class)->create($lab, $owner, ['code' => $code, 'definition' => $this->definition()])['data'];
        $reviewer = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermissions($reviewer, $lab, [Authority::REVIEW, Authority::LOCAL_VIEW, Authority::LOCAL_HISTORY, 'orders.clinical_exceptions.approve', 'orders.clinical_exceptions.view']);

        return [$lab, $owner, $order, $line, $data, $reviewer];
    }

    private function reference(array $data): array
    {
        return ['source' => 'local', 'reason_id' => $data['reason']->id, 'version_id' => $data['version']->id];
    }

    private function body($order, array $data): array
    {
        return ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 0, 'reason_reference' => $this->reference($data), 'justification' => 'FICTICIO: justificación clínica'];
    }

    private function clinicalUrl($order, $line): string
    {
        return "/api/v1/laboratory-orders/$order->id/exams/$line->id/exceptions";
    }

    private function publish($lab, $actor, int $id, int $number, array $changes): array
    {
        setPermissionsTeamId($lab->id);

        return app(LocalClinicalReasonManager::class)->publish($lab, $actor, $id, ['expected_number' => $number, 'definition' => $this->definition($changes)])['data'];
    }

    public function test_owner_creates_versions_activation_history_and_events_without_any_global_catalog(): void
    {
        [$lab, $owner] = $this->scenario(false);
        $this->assignLaboratoryRole($owner, $lab, 'owner');
        $created = $this->postJson(self::BASE, ['code' => 'ficticio', 'definition' => $this->definition()])->assertCreated();
        $id = $created->json('data.reason.id');
        $before = LaboratoryClinicalReasonVersion::sole()->getRawOriginal();
        $this->postJson(self::BASE.'/'.$id.'/versions', ['expected_number' => 1, 'definition' => $this->definition(['name' => 'FICTICIO: sucesora'])])->assertCreated()->assertJsonPath('data.version.number', 2);
        $this->postJson(self::BASE.'/'.$id.'/activation', ['expected_number' => 2, 'is_active' => false, 'change_reason' => 'FICTICIO: desactivación'])->assertCreated()->assertJsonPath('data.version.number', 3);
        $this->postJson(self::BASE.'/'.$id.'/activation', ['expected_number' => 3, 'is_active' => true, 'change_reason' => 'FICTICIO: reactivación'])->assertCreated();
        $this->getJson(self::BASE)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson(self::BASE.'/'.$id)->assertOk()->assertJsonPath('data.latest_version.number', 4);
        $this->getJson(self::BASE.'/'.$id.'/versions')->assertOk()->assertJsonPath('meta.total', 4);
        $this->getJson(self::BASE.'/'.$id.'/events')->assertOk()->assertJsonPath('meta.total', 4);
        $this->assertSame($before, LaboratoryClinicalReasonVersion::orderBy('id')->first()->getRawOriginal());
        foreach (['global_clinical_reasons', 'clinical_reason_adoptions', 'global_clinical_reason_impacts', 'saas_capability_assignments'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_new_local_http_request_and_approval_use_own_immutable_version_and_separate_justification(): void
    {
        [$lab, $owner, $order, $line, $data, $reviewer] = $this->prepared();
        $url = $this->clinicalUrl($order, $line);
        $response = $this->postJson($url, $this->body($order, $data))->assertCreated()->assertJsonPath('data.reason_reference.source', 'local')->assertJsonPath('data.exception.reason', 'FICTICIO: motivo local');
        $id = $response->json('data.exception.id');
        $this->assertDatabaseHas('clinical_exception_local_reason_references', ['exception_request_id' => $id, 'laboratory_id' => $lab->id, 'version_id' => $data['version']->id]);
        $this->postJson($url.'/'.$id.'/approve', ['clinical_revision' => $order->fresh()->clinical_revision, 'request_revision' => 1])->assertForbidden();
        $this->actingAs($reviewer)->postJson($url.'/'.$id.'/approve', ['clinical_revision' => $order->fresh()->clinical_revision, 'request_revision' => 1])->assertOk()->assertJsonPath('data.reason_reference.version_id', $data['version']->id);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.reason_reference.definition.description', $data['version']->description);
        $this->assertDatabaseCount('clinical_line_resolutions', 1);
        $this->assertDatabaseCount('laboratory_order_results', 0);
        $this->assertDatabaseCount('clinical_exception_reason_references', 0);
        $this->assertDatabaseCount('global_clinical_reasons', 0);
        $this->assertNull($line->fresh()->processing_started_at);
    }

    public function test_reclassification_then_reversal_retains_every_obligation_until_explicit_favorable_assessments(): void
    {
        [$lab, $owner, $order, $line, $data, $reviewer] = $this->prepared();
        $url = $this->clinicalUrl($order, $line);
        $created = $this->postJson($url, $this->body($order, $data))->assertCreated();
        $id = $created->json('data.exception.id');
        $original = ClinicalExceptionLocalReasonReference::sole()->getRawOriginal();
        $this->publish($lab, $owner, $data['reason']->id, 1, ['classification' => 'incidence_only', 'change_reason' => 'FICTICIO: reclasificación']);
        $this->publish($lab, $owner, $data['reason']->id, 2, ['change_reason' => 'FICTICIO: nueva clasificación']);
        $this->assertDatabaseCount('local_clinical_reason_reviews', 2);
        $this->actingAs($reviewer)->postJson($url.'/'.$id.'/approve', ['clinical_revision' => $order->fresh()->clinical_revision, 'request_revision' => 1])->assertConflict()->assertJsonPath('code', 'clinical_reason_use_blocked');
        foreach (LocalClinicalReasonReview::orderBy('id')->get() as $review) {
            $resolve = self::BASE.'/'.$data['reason']->id.'/reviews/'.$review->id.'/resolutions';
            $this->postJson($resolve, ['expected_revision' => 0, 'outcome' => 'favorable', 'justification' => 'FICTICIO'])->assertUnprocessable();
            $this->postJson($resolve, ['expected_revision' => 0, 'outcome' => 'non_favorable', 'assessment' => 'FICTICIO: insuficiente', 'justification' => 'FICTICIO'])->assertCreated()->assertJsonPath('review_status', 'pending');
            $this->postJson($url.'/'.$id.'/approve', ['clinical_revision' => $order->fresh()->clinical_revision, 'request_revision' => 1])->assertConflict();
            $this->postJson($resolve, ['expected_revision' => 1, 'outcome' => 'favorable', 'assessment' => 'FICTICIO: revisión favorable', 'justification' => 'FICTICIO'])->assertCreated()->assertJsonPath('review_status', 'resolved');
            $this->getJson($resolve)->assertOk()->assertJsonPath('meta.total', 2);
        }
        $this->assertDatabaseCount('clinical_line_resolutions', 0);
        $this->getJson(self::BASE.'/'.$data['reason']->id.'/reviews')->assertOk()->assertJsonPath('meta.total', 2);
        $this->postJson($url.'/'.$id.'/approve', ['clinical_revision' => $order->fresh()->clinical_revision, 'request_revision' => 1])->assertOk();
        $this->assertSame($original, ClinicalExceptionLocalReasonReference::sole()->getRawOriginal());
    }

    public function test_deactivation_requires_review_for_pending_approval_but_favorable_does_not_allow_new_inactive_requests(): void
    {
        [$lab, $owner, $order, $line, $data, $reviewer] = $this->prepared();
        $created = $this->postJson($this->clinicalUrl($order, $line), $this->body($order, $data))->assertCreated();
        $reasonId = $data['reason']->id;
        $inactive = $this->postJson(self::BASE.'/'.$reasonId.'/activation', ['expected_number' => 1, 'is_active' => false, 'change_reason' => 'FICTICIO: desactivar'])->assertCreated();
        $review = LocalClinicalReasonReview::sole();
        $url = $this->clinicalUrl($order, $line).'/'.$created->json('data.exception.id').'/approve';
        $this->actingAs($reviewer)->postJson($url, ['clinical_revision' => $order->fresh()->clinical_revision, 'request_revision' => 1])->assertConflict();
        $this->postJson(self::BASE.'/'.$reasonId.'/reviews/'.$review->id.'/resolutions', ['expected_revision' => 0, 'outcome' => 'favorable', 'assessment' => 'FICTICIO: considera causa', 'justification' => 'FICTICIO'])->assertCreated();
        $this->postJson($url, ['clinical_revision' => $order->fresh()->clinical_revision, 'request_revision' => 1])->assertOk();
        setPermissionsTeamId($lab->id);
        $second = app(AddExamToLaboratoryOrder::class)->execute($lab, $order->id, $line->laboratory_exam_id, $owner);
        $data['version'] = LaboratoryClinicalReasonVersion::findOrFail($inactive->json('data.version.id'));
        $this->actingAs($owner)->postJson($this->clinicalUrl($order, $second), $this->body($order, $data))->assertConflict()->assertJsonPath('code', 'clinical_reason_use_blocked');
        $this->assertDatabaseCount('clinical_exception_requests', 1);
    }

    #[DataProvider('invalidReferences')]
    public function test_local_reference_rejects_foreign_stale_or_manipulated_identity(string $case, int $status): void
    {
        [$lab, $owner, $order, $line, $data] = $this->prepared();
        $body = $this->body($order, $data);
        if ($case === 'foreign') {
            [$other, $otherOwner, $otherOrder, $otherLine, $otherData] = $this->prepared();
            $body['reason_reference'] = $this->reference($otherData);
            $this->actingAs($owner)->withHeader('X-Laboratory-ID', (string) $lab->id);
        } elseif ($case === 'stale') {
            $this->publish($lab, $owner, $data['reason']->id, 1, ['name' => 'FICTICIO: nombre nuevo']);
        } else {
            match ($case) {
                'reason' => $body['reason_reference']['reason_id'] = 999999,
                'version' => $body['reason_reference']['version_id'] = 999999,
                'string' => $body['reason_reference']['version_id'] = (string) $body['reason_reference']['version_id'],
                'adoption' => $body['reason_reference']['adoption_id'] = 1,
                'actor' => $body['reason_reference']['created_by'] = $owner->id,
            };
        }
        $this->postJson($this->clinicalUrl($order, $line), $body)->assertStatus($status);
        $this->assertDatabaseCount('clinical_exception_requests', 0);
    }

    public static function invalidReferences(): array
    {
        return [['foreign', 404], ['stale', 409], ['reason', 404], ['version', 404], ['string', 422], ['adoption', 422], ['actor', 422]];
    }

    public function test_other_roles_cannot_administer_even_with_direct_admin_permission_and_owner_does_not_inherit_clinical_authority(): void
    {
        [$lab, $owner, $order, $line, $data] = $this->prepared();
        $admin = $this->createLaboratoryMember($lab);
        $this->assignLaboratoryRole($admin, $lab, 'administrator');
        $this->assignDirectLaboratoryPermissions($admin, $lab, [Authority::LOCAL_CREATE, Authority::LOCAL_PUBLISH, Authority::LOCAL_MANAGE]);
        $this->actingAs($admin)->postJson(self::BASE, ['code' => 'denied', 'definition' => $this->definition()])->assertForbidden();
        $this->postJson(self::BASE.'/'.$data['reason']->id.'/versions', ['expected_number' => 1, 'definition' => $this->definition()])->assertForbidden();
        $this->seed(RolePermissionSeeder::class);
        $ordinaryOwner = $this->createLaboratoryMember($lab);
        $this->assignLaboratoryRole($ordinaryOwner, $lab, 'owner');
        $this->actingAs($ordinaryOwner)->postJson($this->clinicalUrl($order, $line), $this->body($order, $data))->assertForbidden();
        setPermissionsTeamId($lab->id);
        $this->assertFalse($ordinaryOwner->can(Authority::REVIEW));
        $this->assertFalse($ordinaryOwner->can('orders.clinical_exceptions.approve'));
        $this->assertDatabaseCount('laboratory_clinical_reason_versions', 1);
    }

    public function test_codes_are_unique_per_laboratory_and_cross_tenant_catalogs_are_not_visible(): void
    {
        [$lab, $owner, $order, $line, $data] = $this->prepared();
        [$other, $otherOwner, $otherOrder, $otherLine, $otherData] = $this->prepared();
        $this->assertDatabaseCount('laboratory_clinical_reasons', 2);
        $this->getJson(self::BASE.'/'.$data['reason']->id)->assertNotFound();
        $this->getJson(self::BASE)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.laboratory_id', $other->id);
        $this->postJson(self::BASE, ['code' => 'ficticio', 'definition' => $this->definition()])->assertConflict();
        $this->assertDatabaseCount('laboratory_clinical_reasons', 2);
    }

    public function test_incidence_only_cannot_request_terminal_exception_and_text_edits_do_not_infer_local_impact(): void
    {
        [$lab, $owner, $order, $line, $data] = $this->prepared();
        $data = $this->publish($lab, $owner, $data['reason']->id, 1, ['classification' => 'incidence_only']);
        $this->postJson($this->clinicalUrl($order, $line), $this->body($order, $data))->assertConflict()->assertJsonPath('code', 'clinical_reason_not_terminal');
        $this->assertDatabaseCount('global_clinical_reason_impacts', 0);
        $this->assertDatabaseCount('local_clinical_reason_reviews', 0);
    }

    #[DataProvider('immutableTargets')]
    public function test_sql_and_service_cannot_rewrite_history(string $table): void
    {
        [$lab, $owner, $order, $line, $data, $reviewer] = $this->prepared();
        app(ClinicalResolutionService::class)->requestException($lab, $owner, $order->id, $line->id, $this->body($order, $data));
        $this->publish($lab, $owner, $data['reason']->id, 1, ['classification' => 'incidence_only']);
        app(LocalClinicalReasonManager::class)->resolve($lab, $reviewer, $data['reason']->id, LocalClinicalReasonReview::sole()->id, ['expected_revision' => 0, 'outcome' => 'favorable', 'assessment' => 'FICTICIO', 'justification' => 'FICTICIO']);
        $before = DB::table($table)->get()->toJson();
        try {
            DB::transaction(fn () => DB::table($table)->delete());
            $this->fail('History is immutable.');
        } catch (\PDOException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $this->assertSame($before, DB::table($table)->get()->toJson());
    }

    public static function immutableTargets(): array
    {
        return [['laboratory_clinical_reasons'], ['laboratory_clinical_reason_versions'], ['clinical_exception_local_reason_references'], ['local_clinical_reason_reviews'], ['local_clinical_reason_resolutions']];
    }

    public function test_missing_review_cannot_be_bypassed_by_the_low_level_version_store(): void
    {
        [$lab, $owner, $order, $line, $data] = $this->prepared();
        app(ClinicalResolutionService::class)->requestException($lab, $owner, $order->id, $line->id, $this->body($order, $data));
        try {
            app(ClinicalReasonVersionStore::class)->appendLocal($lab, $data['reason']->id, $owner, 1, $this->definition(['classification' => 'incidence_only']));
            $this->fail('Required review must be confirmed atomically.');
        } catch (\PDOException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $this->assertDatabaseCount('laboratory_clinical_reason_versions', 1);
        $this->assertDatabaseCount('local_clinical_reason_reviews', 0);
    }

    public function test_local_publication_audit_failure_rolls_back_version_and_review_fanout(): void
    {
        [$lab, $owner, $order, $line, $data] = $this->prepared();
        app(ClinicalResolutionService::class)->requestException($lab, $owner, $order->id, $line->id, $this->body($order, $data));
        $before = AuditLog::count();
        AuditLog::creating(function ($event): void {
            if ($event->event === 'clinical_reason.local_published') {
                throw new \RuntimeException('FICTICIO: audit failure');
            }
        });
        try {
            $this->publish($lab, $owner, $data['reason']->id, 1, ['classification' => 'incidence_only']);
            $this->fail('Audit failure must roll back the whole operation.');
        } catch (\RuntimeException $e) {
            $this->assertSame('FICTICIO: audit failure', $e->getMessage());
        }
        $this->assertDatabaseCount('laboratory_clinical_reason_versions', 1);
        $this->assertDatabaseCount('local_clinical_reason_reviews', 0);
        $this->assertSame($before, AuditLog::count());
    }

    #[DataProvider('failedAuditOperations')]
    public function test_audit_failure_preserves_clinical_and_review_atomicity(string $operation, string $event): void
    {
        [$lab, $owner, $order, $line, $data, $reviewer] = $this->prepared();
        if ($operation === 'review') {
            app(ClinicalResolutionService::class)->requestException($lab, $owner, $order->id, $line->id, $this->body($order, $data));
            $this->publish($lab, $owner, $data['reason']->id, 1, ['classification' => 'incidence_only']);
        }
        $revision = $order->fresh()->clinical_revision;
        $audits = AuditLog::count();
        AuditLog::creating(function ($audit) use ($event): void {
            if ($audit->event === $event) {
                throw new \RuntimeException('FICTICIO: audit failure');
            }
        });
        try {
            if ($operation === 'request') {
                app(ClinicalResolutionService::class)->requestException($lab, $owner, $order->id, $line->id, $this->body($order, $data));
            } else {
                app(LocalClinicalReasonManager::class)->resolve($lab, $reviewer, $data['reason']->id, LocalClinicalReasonReview::sole()->id, ['expected_revision' => 0, 'outcome' => 'favorable', 'assessment' => 'FICTICIO', 'justification' => 'FICTICIO']);
            }
            $this->fail('Audit and protected writes must commit together.');
        } catch (\RuntimeException $e) {
            $this->assertSame('FICTICIO: audit failure', $e->getMessage());
        }
        $this->assertSame($revision, $order->fresh()->clinical_revision);
        $this->assertSame($audits, AuditLog::count());
        $this->assertDatabaseCount('local_clinical_reason_resolutions', 0);
        $this->assertDatabaseCount('clinical_exception_local_reason_references', $operation === 'request' ? 0 : 1);
        $this->assertDatabaseCount('clinical_line_resolutions', 0);
    }

    public static function failedAuditOperations(): array
    {
        return [['request', 'order_clinical.exception_requested'], ['review', 'clinical_reason.local_review_evaluated']];
    }

    public function test_service_revalidates_owner_and_membership_even_with_cached_roles(): void
    {
        [$lab, $owner, $order, $line, $data] = $this->prepared();
        $owner->load('roles', 'permissions');
        $owner->removeRole('owner');
        try {
            $this->publish($lab, $owner, $data['reason']->id, 1, []);
            $this->fail('Direct permissions do not replace the required owner role.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assignLaboratoryRole($owner, $lab, 'owner');
        DB::table('laboratory_user')->where('laboratory_id', $lab->id)->where('user_id', $owner->id)->update(['is_active' => false]);
        try {
            $this->publish($lab, $owner, $data['reason']->id, 1, []);
            $this->fail('An inactive membership cannot publish.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertDatabaseCount('laboratory_clinical_reason_versions', 1);
    }

    public function test_openapi_and_routes_document_both_origins_and_exact_local_authorities(): void
    {
        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $doc = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/laboratory/clinical-reasons/local'));
        $this->assertCount(10, $routes);
        foreach ($routes as $route) {
            $permission = collect($route->gatherMiddleware())->first(fn ($m) => str_starts_with($m, 'can:'));
            $operation = $doc['paths']['/'.$route->uri()][strtolower($route->methods()[0])];
            $this->assertSame(substr($permission, 4), $operation['x-tenant-permission']);
        }
        $this->assertCount(2, $doc['components']['schemas']['ClinicalExceptionReasonInput']['oneOf']);
        $this->assertSame(['local'], $doc['components']['schemas']['ClinicalExceptionLocalReasonInput']['properties']['source']['enum']);
    }
}

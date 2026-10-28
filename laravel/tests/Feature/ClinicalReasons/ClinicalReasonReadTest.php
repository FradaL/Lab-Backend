<?php

namespace Tests\Feature\ClinicalReasons;

use App\Authorization\ClinicalReasonTenantAuthority as Authority;
use App\ClinicalExams\ClinicalReasonAdoptionManager;
use App\ClinicalExams\ClinicalReasonVersionStore;
use App\ClinicalExams\GlobalClinicalReasonImpactManager;
use App\ClinicalExams\GlobalClinicalReasonManager;
use App\ClinicalExams\LocalClinicalReasonManager;
use App\Models\AuditLog;
use App\Models\ClinicalExceptionLocalReasonReference;
use App\Models\ClinicalReasonImpactReview;
use App\Models\GlobalClinicalReasonVersion;
use App\Models\Laboratory;
use App\Models\LocalClinicalReasonReview;
use App\Models\SaasAuditLog;
use App\Models\SaasCapabilityAssignment;
use App\Models\User;
use App\UserAdministration\SaasAccessManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\ClinicalResults\ClinicalResultScenario;
use Tests\Support\StructuredClinicalReasonFixture;
use Tests\TestCase;

class ClinicalReasonReadTest extends TestCase
{
    use ClinicalResultScenario, RefreshDatabase;

    private const URL = '/api/v1/laboratory/clinical-reasons';

    private function definition(array $changes = []): array
    {
        return array_replace(['name' => 'FICTICIO local 100%_', 'description' => 'FICTICIO: local', 'classification' => 'terminal_candidate', 'evidence_requirements' => ['schema_version' => 1, 'requirements' => ['synthetic_evaluation']], 'is_active' => true, 'change_reason' => 'FICTICIO'], $changes);
    }

    private function prepared(): array
    {
        [$lab, $actor, $order, $line] = $this->scenario(false);
        $this->assignLaboratoryRole($actor, $lab, 'owner');
        $local = app(LocalClinicalReasonManager::class)->create($lab, $actor, ['code' => 'ficticio', 'definition' => $this->definition()])['data'];
        $global = $this->exceptionReference($lab, $actor);

        return [$lab, $actor, $order, $line, $local, $global];
    }

    private function url($order, $line): string
    {
        return "/api/v1/laboratory-orders/$order->id/exams/$line->id/exceptions";
    }

    private function body($order, array $reference): array
    {
        return ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 0, 'reason_reference' => $reference, 'justification' => 'FICTICIO: clínica'];
    }

    public function test_unified_contract_distinguishes_both_origins_and_never_exposes_saas_administration(): void
    {
        [$lab, $actor, $order, $line, $local, $global] = $this->prepared();
        StructuredClinicalReasonFixture::global('FICTICIO: no adoptado');
        $before = AuditLog::count();
        $response = $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.visible_sources', ['global', 'local']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $items = collect($response->json('data'))->keyBy('source');
        $this->assertSame($global, $items['global']['reason_reference']);
        $this->assertFalse($items['global']['actions']['can_update_adoption']);
        $this->assertSame(['source' => 'local', 'reason_id' => $local['reason']->id, 'version_id' => $local['version']->id], $items['local']['reason_reference']);
        $this->assertSame(['synthetic_evaluation'], $items['local']['version']['evidence_requirements']['requirements']);
        $this->assertTrue($items['local']['actions']['can_select_for_request']);
        foreach ($items as $item) {
            $this->assertSame('published', $item['publication_status']);
            $this->assertTrue($item['eligibility']['informational']);
            $this->assertArrayNotHasKey('created_by', $item['version']);
            $this->assertArrayNotHasKey('saas_capabilities', $item);
            $this->assertArrayNotHasKey('declared_by', $item);
        }
        $this->assertSame($before, AuditLog::count());
    }

    #[DataProvider('origins')]
    public function test_reference_from_read_is_consumed_unchanged_by_real_be08_request(string $source): void
    {
        [$lab, $actor, $order, $line] = $this->prepared();
        $item = $this->getJson(self::URL.'?source='.$source.'&eligible=true')->assertOk()->json('data.0');
        $this->postJson($this->url($order, $line), $this->body($order, $item['reason_reference']))->assertCreated()
            ->assertJsonPath('data.reason_reference.source', $source)->assertJsonPath('data.reason_reference.version_id', $item['version']['id']);
        $this->assertDatabaseCount('clinical_exception_requests', 1);
        $this->assertDatabaseCount('clinical_line_resolutions', 0);
    }

    public static function origins(): array
    {
        return [['global'], ['local']];
    }

    public function test_name_search_is_literal_and_filters_are_applied_before_count_and_pagination(): void
    {
        [$lab, $actor] = $this->prepared();
        $this->getJson(self::URL.'?search=')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson(self::URL.'?search='.urlencode('100%_'))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.source', 'local');
        $this->getJson(self::URL.'?search='.urlencode("%' OR 1=1 --"))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson(self::URL.'?classification=incidence_only')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson(self::URL.'?active=false')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson(self::URL.'?eligible=false')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson(self::URL.'?source=local&page=2')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data', []);
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_and_scope_injection_filters_return_422(string $query): void
    {
        $this->prepared();
        $this->getJson(self::URL.'?'.$query)->assertUnprocessable();
    }

    public static function invalidFilters(): array
    {
        return [['source=saas'], ['eligible=1'], ['active=0'], ['classification=restrictive'], ['page=0'], ['search%5B0%5D=x'], ['laboratory_id=999'], ['sort=reason_id%3BDROP%20TABLE%20users']];
    }

    public function test_origins_are_filtered_by_effective_read_permissions_without_granting_clinical_authority(): void
    {
        [$lab, $actor] = $this->prepared();
        $reader = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermissions($reader, $lab, [Authority::LOCAL_VIEW]);
        $this->actingAs($reader)->getJson(self::URL)->assertOk()->assertJsonPath('meta.visible_sources', ['local'])->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.actions.can_select_for_request', false);
        $this->getJson(self::URL.'?source=global')->assertForbidden();
        $this->assertDatabaseCount('model_has_permissions', 96);
        $this->assertDatabaseCount('clinical_exception_requests', 0);
    }

    public function test_no_authentication_permission_or_other_tenant_membership_cannot_read_the_catalog(): void
    {
        [$lab, $actor] = $this->prepared();
        $member = $this->createLaboratoryMember($lab);
        $this->actingAs($member)->getJson(self::URL)->assertForbidden();
        $this->assignDirectLaboratoryPermissions($member, $lab, ['orders.clinical_exceptions.request']);
        $this->getJson(self::URL)->assertForbidden();
        $foreign = Laboratory::factory()->create();
        $this->actingAs($actor)->withHeader('X-Laboratory-ID', (string) $foreign->id)->getJson(self::URL)->assertForbidden();
        Auth::forgetGuards();
        $this->app['auth']->forgetGuards();
        $this->app['session']->flush();
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_cross_tenant_adoptions_and_local_versions_never_enter_the_unified_result(): void
    {
        [$lab, $actor, $order, $line, $local] = $this->prepared();
        [$other, $otherActor] = $this->prepared();
        $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.laboratory_id', $other->id)->assertJsonPath('meta.total', 2);
        $this->getJson(self::URL.'?search='.urlencode('FICTICIO'))->assertOk()->assertJsonMissing(['reason_reference' => ['source' => 'local', 'reason_id' => $local['reason']->id, 'version_id' => $local['version']->id]]);
    }

    #[DataProvider('origins')]
    public function test_eligibility_read_cannot_authorize_a_request_after_deactivation(string $source): void
    {
        [$lab, $actor, $order, $line, $local, $global] = $this->prepared();
        $selected = $this->getJson(self::URL.'?source='.$source)->assertOk()->json('data.0');
        $this->assertTrue($selected['actions']['can_select_for_request']);
        setPermissionsTeamId($lab->id);
        if ($source === 'local') {
            app(LocalClinicalReasonManager::class)->activation($lab, $actor, $local['reason']->id, ['expected_number' => 1, 'is_active' => false, 'change_reason' => 'FICTICIO']);
        } else {
            $version = GlobalClinicalReasonVersion::findOrFail($global['version_id']);
            $operator = User::findOrFail($version->created_by);
            $admin = User::findOrFail(SaasAuditLog::where('source', 'bootstrap')->value('affected_user_id'));
            app(SaasAccessManager::class)->grant($admin, $operator->id, ['capability' => GlobalClinicalReasonManager::MANAGE, 'reason' => 'FICTICIO']);
            app(GlobalClinicalReasonManager::class)->activation($operator, $global['reason_id'], ['expected_number' => 1, 'is_active' => false, 'change_reason' => 'FICTICIO']);
        }
        $this->postJson($this->url($order, $line), $this->body($order, $selected['reason_reference']))->assertConflict();
        $this->assertDatabaseCount('clinical_exception_requests', 0);
        $this->getJson(self::URL.'?source='.$source.'&eligible=true')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_pending_local_original_and_non_favorable_reviews_are_separate_from_new_request_eligibility(): void
    {
        [$lab, $actor, $order, $line, $local] = $this->prepared();
        $item = $this->getJson(self::URL.'?source=local')->json('data.0');
        $created = $this->postJson($this->url($order, $line), $this->body($order, $item['reason_reference']))->assertCreated();
        $requestId = $created->json('data.exception.id');
        $original = ClinicalExceptionLocalReasonReference::sole()->getRawOriginal();
        setPermissionsTeamId($lab->id);
        app(LocalClinicalReasonManager::class)->publish($lab, $actor, $local['reason']->id, ['expected_number' => 1, 'definition' => $this->definition(['name' => 'FICTICIO: nueva', 'classification' => 'incidence_only'])]);
        app(LocalClinicalReasonManager::class)->publish($lab, $actor, $local['reason']->id, ['expected_number' => 2, 'definition' => $this->definition()]);
        $review = LocalClinicalReasonReview::orderBy('id')->first();
        app(LocalClinicalReasonManager::class)->resolve($lab, $actor, $local['reason']->id, $review->id, ['expected_revision' => 0, 'outcome' => 'non_favorable', 'assessment' => 'FICTICIO', 'justification' => 'FICTICIO']);
        $this->getJson($this->url($order, $line))->assertOk()->assertJsonPath('data.0.reason_reference.version_id', $local['version']->id)
            ->assertJsonPath('data.0.reason_eligibility.can_approve_from_catalog', false)->assertJsonPath('data.0.reason_eligibility.pending_review_count', 2)
            ->assertJsonPath('data.0.reason_eligibility.approval_blockers', ['clinical_review_pending', 'clinical_review_non_favorable']);
        $this->getJson(self::URL.'?source=local&eligible=true')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.version.number', 3);
        $this->getJson(self::URL.'/local/'.$local['reason']->id.'/reviews?exception_request_id='.$requestId)->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson(self::URL.'/local/'.$local['reason']->id.'/reviews?exception_request_id=999999')->assertNotFound();
        $this->assertSame($original, ClinicalExceptionLocalReasonReference::sole()->getRawOriginal());
    }

    public function test_terminal_classification_is_projected_without_changing_legacy_adoption_status_fields(): void
    {
        [$lab, $actor, $order, $line, $local, $global] = $this->prepared();
        $created = $this->postJson($this->url($order, $line), $this->body($order, $global))->assertCreated();
        setPermissionsTeamId($lab->id);
        $version = GlobalClinicalReasonVersion::findOrFail($global['version_id']);
        $operator = User::findOrFail($version->created_by);
        $next = app(ClinicalReasonVersionStore::class)->appendGlobal($global['reason_id'], $operator, 1, $this->definition(['classification' => 'incidence_only']));
        app(GlobalClinicalReasonImpactManager::class)->declare($operator, $global['reason_id'], $next->id, ['expected_revision' => 0, 'classification' => 'non_restrictive', 'justification' => 'FICTICIO']);
        app(ClinicalReasonAdoptionManager::class)->update($lab, $actor, $global['adoption_id'], ['expected_revision' => 1, 'version_id' => $next->id, 'justification' => 'FICTICIO']);
        $this->getJson(self::URL.'?source=global')->assertOk()->assertJsonPath('data.0.eligibility.request_blockers', ['clinical_reason_not_terminal'])->assertJsonPath('data.0.actions.can_select_for_request', false);
        $this->getJson('/api/v1/laboratory/clinical-reason-adoptions/'.$global['adoption_id'].'/eligibility')->assertOk()->assertJsonPath('data.can_request', true)->assertJsonPath('data.selection.eligibility.can_select_for_request', false);
        $this->getJson($this->url($order, $line))->assertOk()->assertJsonPath('data.0.reason_reference.version_id', $global['version_id'])->assertJsonPath('data.0.reason_eligibility.can_approve_from_catalog', true);
    }

    public function test_policy_reports_unclassified_impacts_without_exposing_saas_declarations(): void
    {
        [$lab, $actor, $order, $line, $local, $global] = $this->prepared();
        $version = GlobalClinicalReasonVersion::findOrFail($global['version_id']);
        app(ClinicalReasonVersionStore::class)->appendGlobal($global['reason_id'], User::findOrFail($version->created_by), 1, $this->definition());
        $this->getJson(self::URL.'?source=global')->assertOk()->assertJsonPath('data.0.eligibility.request_blockers', ['impact_unclassified'])->assertJsonPath('data.0.actions.can_update_adoption', true);
    }

    public function test_global_review_outcomes_are_projected_by_the_write_policy_without_auto_approval(): void
    {
        [$lab, $actor, $order, $line, $local, $global] = $this->prepared();
        $version = GlobalClinicalReasonVersion::findOrFail($global['version_id']);
        $operator = User::findOrFail($version->created_by);
        app(GlobalClinicalReasonImpactManager::class)->declare($operator, $global['reason_id'], $version->id, ['expected_revision' => 1, 'classification' => 'restrictive', 'justification' => 'FICTICIO']);
        $review = ClinicalReasonImpactReview::sole();
        $this->getJson(self::URL.'?source=global')->assertOk()->assertJsonPath('data.0.eligibility.request_blockers', ['clinical_review_pending']);
        setPermissionsTeamId($lab->id);
        app(ClinicalReasonAdoptionManager::class)->resolve($lab, $actor, $global['adoption_id'], $review->id, ['expected_revision' => 0, 'outcome' => 'non_favorable', 'assessment' => 'FICTICIO', 'justification' => 'FICTICIO']);
        $this->getJson(self::URL.'?source=global')->assertOk()->assertJsonPath('data.0.eligibility.request_blockers', ['clinical_review_pending', 'clinical_review_non_favorable']);
        setPermissionsTeamId($lab->id);
        app(ClinicalReasonAdoptionManager::class)->resolve($lab, $actor, $global['adoption_id'], $review->id, ['expected_revision' => 1, 'outcome' => 'favorable', 'assessment' => 'FICTICIO', 'justification' => 'FICTICIO']);
        $this->getJson(self::URL.'?source=global&eligible=true')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.eligibility.pending_review_count', 0);
        $this->assertDatabaseCount('clinical_exception_requests', 0);
        $this->assertDatabaseCount('clinical_line_resolutions', 0);
    }

    public function test_actor_can_read_own_saas_capability_codes_without_access_management_or_tenant_context(): void
    {
        [$lab, $actor, $order, $line, $local, $global] = $this->prepared();
        $operator = User::findOrFail(GlobalClinicalReasonVersion::findOrFail($global['version_id'])->created_by);
        $this->actingAs($operator)->getJson('/api/v1/auth/me?user_id='.$actor->id)->assertOk()->assertJsonPath('data.saas_capabilities', ['saas.clinical_reasons.classify_impact'])->assertJsonMissingPath('data.assignments');
        $this->getJson('/api/v1/platform/access/capabilities')->assertForbidden();
        $this->actingAs($actor)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.saas_capabilities', []);
    }

    public function test_self_saas_capabilities_reflect_revocation_and_never_include_another_users_grants(): void
    {
        [$lab, $actor, $order, $line, $local, $global] = $this->prepared();
        $operator = User::findOrFail(GlobalClinicalReasonVersion::findOrFail($global['version_id'])->created_by);
        $admin = User::findOrFail(SaasAuditLog::where('source', 'bootstrap')->value('affected_user_id'));
        $grant = SaasCapabilityAssignment::where('user_id', $operator->id)->sole();
        $this->actingAs($operator)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.saas_capabilities', ['saas.clinical_reasons.classify_impact']);
        app(SaasAccessManager::class)->revoke($admin, $operator->id, $grant->id, ['revision' => 1, 'reason' => 'FICTICIO']);
        $this->getJson('/api/v1/auth/me?user_id='.$admin->id)->assertOk()->assertJsonPath('data.saas_capabilities', []);
        $this->getJson('/api/v1/platform/clinical-reasons')->assertForbidden();
    }

    public function test_catalog_query_count_and_page_size_are_bounded_as_the_catalog_grows(): void
    {
        [$lab, $actor] = $this->prepared();
        for ($i = 0; $i < 53; $i++) {
            app(LocalClinicalReasonManager::class)->create($lab, $actor, ['code' => 'ficticio_'.$i, 'definition' => $this->definition()]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $page = $this->getJson(self::URL.'?eligible=true')->assertOk()->assertJsonPath('meta.total', 55)->assertJsonCount(50, 'data');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThan(35, count($queries), 'Paginated selection must not query versions/reviews/permissions per item.');
        $second = $this->getJson(self::URL.'?eligible=true&page=2')->assertOk()->assertJsonCount(5, 'data');
        $this->assertSame([], array_intersect(array_column($page->json('data'), 'key'), array_column($second->json('data'), 'key')));
    }

    public function test_openapi_documents_selection_and_additive_contracts_and_route_permission_union(): void
    {
        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $operation = $document['paths'][self::URL]['get'];
        $this->assertSame([Authority::VIEW, Authority::LOCAL_VIEW], $operation['x-tenant-permissions-any']);
        $this->assertSame('#/components/schemas/ClinicalReasonSelectionPage', $operation['responses']['200']['content']['application/json']['schema']['$ref']);
        $this->assertArrayHasKey('reason_eligibility', $document['components']['schemas']['ClinicalExceptionRequest']['properties']);
        $this->assertArrayHasKey('saas_capabilities', $document['components']['schemas']['CurrentUserResponse']['properties']['data']['properties']);
    }
}

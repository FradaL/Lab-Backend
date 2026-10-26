<?php

namespace Tests\Feature\ClinicalReasons;

use App\ClinicalExams\GlobalClinicalReasonManager as Manager;
use App\Models\GlobalClinicalReason;
use App\Models\GlobalClinicalReasonVersion;
use App\Models\Laboratory;
use App\Models\SaasAuditLog;
use App\Models\User;
use App\Tenancy\CurrentLaboratory;
use App\UserAdministration\SaasAccessManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class GlobalClinicalReasonAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/platform/clinical-reasons';

    private function definition(array $changes = []): array
    {
        return array_replace(['name' => 'FICTICIO: motivo', 'description' => 'FICTICIO: definición administrativa.', 'classification' => 'incidence_only', 'evidence_requirements' => ['schema_version' => 1, 'requirements' => ['problem_description']], 'is_active' => true, 'change_reason' => 'FICTICIO: justificación administrativa.'], $changes);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        config(['saas.bootstrap.enabled' => true, 'saas.bootstrap.user_id' => $user->id]);
        app(SaasAccessManager::class)->bootstrap();

        return $user;
    }

    private function operator(User $admin, array $capabilities): User
    {
        $user = User::factory()->create();
        foreach ($capabilities as $capability) {
            app(SaasAccessManager::class)->grant($admin, $user->id, ['capability' => $capability, 'reason' => 'FICTICIO: concesión explícita.']);
        }

        return $user;
    }

    private function reason(User $creator): array
    {
        return app(Manager::class)->create($creator, ['code' => 'ficticio', 'definition' => $this->definition()])['data'];
    }

    public function test_all_catalog_routes_require_authentication_and_have_no_side_effects(): void
    {
        foreach (['', '/1', '/1/versions', '/1/events'] as $path) {
            $this->getJson(self::BASE.$path)->assertUnauthorized();
        }
        foreach (['', '/1/versions', '/1/activation'] as $path) {
            $this->postJson(self::BASE.$path, [])->assertUnauthorized();
        }
        $this->assertDatabaseCount('global_clinical_reasons', 0);
        $this->assertDatabaseCount('saas_audit_logs', 0);
    }

    #[DataProvider('roles')]
    public function test_tenant_roles_do_not_authorize_catalog_operations(string $role): void
    {
        $lab = Laboratory::factory()->create();
        $user = $this->createLaboratoryMember($lab);
        setPermissionsTeamId($lab->id);
        $user->assignRole(Role::findOrCreate($role, 'web'));
        $this->actingAs($user)->withHeader('X-Laboratory-ID', (string) $lab->id)->getJson(self::BASE)->assertForbidden();
        $this->postJson(self::BASE, ['code' => 'ficticio', 'definition' => $this->definition()])->assertForbidden();
        $this->assertDatabaseCount('global_clinical_reasons', 0);
        $this->assertDatabaseCount('saas_audit_logs', 0);
        $this->assertNull(getPermissionsTeamId());
    }

    public static function roles(): array
    {
        return [['owner'], ['administrator']];
    }

    #[DataProvider('faculties')]
    public function test_faculties_are_independent_in_routes_and_sensitive_services(string $capability): void
    {
        $admin = $this->admin();
        $creator = $this->operator($admin, [Manager::CREATE]);
        $reason = $this->reason($creator)['reason'];
        $operator = $capability === 'saas.access.manage' ? $admin : $this->operator($admin, [$capability]);
        $before = SaasAuditLog::count();
        $this->actingAs($operator);
        foreach (['' => fn () => app(Manager::class)->index($operator), '/'.$reason->id => fn () => app(Manager::class)->show($operator, $reason->id), '/'.$reason->id.'/versions' => fn () => app(Manager::class)->history($operator, $reason->id), '/'.$reason->id.'/events' => fn () => app(Manager::class)->events($operator, $reason->id)] as $path => $operation) {
            $this->getJson(self::BASE.$path)->assertStatus($capability === Manager::VIEW ? 200 : 403);
            if ($capability !== Manager::VIEW) {
                $this->forbidden($operation);
            }
        }
        if ($capability !== Manager::CREATE) {
            $body = ['code' => 'otro', 'definition' => $this->definition()];
            $this->postJson(self::BASE, $body)->assertForbidden();
            $this->forbidden(fn () => app(Manager::class)->create($operator, $body));
        }
        if ($capability !== Manager::PUBLISH) {
            $body = ['expected_number' => 1, 'definition' => $this->definition()];
            $this->postJson(self::BASE.'/'.$reason->id.'/versions', $body)->assertForbidden();
            $this->forbidden(fn () => app(Manager::class)->publish($operator, $reason->id, $body));
        }
        if ($capability !== Manager::MANAGE) {
            $body = ['expected_number' => 1, 'is_active' => false, 'change_reason' => 'FICTICIO'];
            $this->postJson(self::BASE.'/'.$reason->id.'/activation', $body)->assertForbidden();
            $this->forbidden(fn () => app(Manager::class)->activation($operator, $reason->id, $body));
        }
        if ($capability !== 'saas.access.manage') {
            $this->getJson('/api/v1/platform/access/users')->assertForbidden();
        }
        $this->assertSame($before, SaasAuditLog::count());
        $this->assertDatabaseCount('global_clinical_reason_versions', 1);
    }

    public static function faculties(): array
    {
        return array_map(fn ($capability) => [$capability], ['saas.access.manage', Manager::VIEW, Manager::CREATE, Manager::PUBLISH, Manager::MANAGE]);
    }

    public function test_creation_publication_activation_and_reads_preserve_history_without_a_tenant(): void
    {
        $admin = $this->admin();
        $creator = $this->operator($admin, [Manager::CREATE]);
        $publisher = $this->operator($admin, [Manager::PUBLISH]);
        $governor = $this->operator($admin, [Manager::MANAGE]);
        $viewer = $this->operator($admin, [Manager::VIEW]);
        $this->actingAs($creator)->withHeader('X-Laboratory-ID', '999999');
        $created = $this->postJson(self::BASE, ['code' => 'ficticio', 'definition' => $this->definition()])->assertCreated()->assertJsonPath('data.version.number', 1);
        $id = $created->json('data.reason.id');
        $first = GlobalClinicalReasonVersion::findOrFail($created->json('data.version.id'))->getRawOriginal();
        $this->actingAs($publisher)->postJson(self::BASE.'/'.$id.'/versions', ['expected_number' => 1, 'definition' => $this->definition(['classification' => 'terminal_candidate', 'name' => 'FICTICIO: revisión'])])->assertCreated()->assertJsonPath('data.version.number', 2);
        $this->actingAs($governor)->postJson(self::BASE.'/'.$id.'/activation', ['expected_number' => 2, 'is_active' => false, 'change_reason' => 'FICTICIO: desactivación'])->assertCreated()->assertJsonPath('data.version.number', 3)->assertJsonPath('data.version.is_active', false)->assertJsonPath('data.version.classification', 'terminal_candidate');
        $this->postJson(self::BASE.'/'.$id.'/activation', ['expected_number' => 3, 'is_active' => true, 'change_reason' => 'FICTICIO: reactivación'])->assertCreated()->assertJsonPath('data.version.number', 4);
        $this->assertSame($first, GlobalClinicalReasonVersion::findOrFail($first['id'])->getRawOriginal());
        $this->assertDatabaseCount('laboratory_clinical_reasons', 0);
        $this->assertDatabaseCount('laboratory_clinical_reason_versions', 0);
        $this->assertDatabaseCount('laboratory_user', 0);
        $this->assertFalse(app(CurrentLaboratory::class)->has());
        $this->actingAs($viewer)->getJson(self::BASE)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.latest_version.number', 4);
        $this->getJson(self::BASE.'/'.$id)->assertOk()->assertJsonPath('data.code', 'ficticio');
        $this->getJson(self::BASE.'/'.$id.'/versions')->assertOk()->assertJsonPath('meta.total', 4)->assertJsonPath('data.3.number', 1);
        $events = $this->getJson(self::BASE.'/'.$id.'/events')->assertOk()->assertJsonPath('meta.total', 4);
        $this->assertSame(['saas.clinical_reasons.activation_changed', 'saas.clinical_reasons.activation_changed', 'saas.clinical_reasons.published', 'saas.clinical_reasons.created'], array_column($events->json('data'), 'action'));
        $this->assertDatabaseHas('saas_audit_logs', ['id' => $created->json('data.audit_id'), 'actor_id' => $creator->id, 'global_reason_id' => $id, 'global_reason_version_id' => $first['id'], 'result' => 'succeeded', 'affected_user_id' => null]);
        $this->actingAs($admin)->getJson('/api/v1/platform/access/history')->assertOk()->assertJsonPath('meta.total', 5);
        $this->getJson(self::BASE.'/'.$id.'/events')->assertForbidden();
    }

    public function test_revocation_and_deactivation_take_effect_even_with_a_stale_user_instance(): void
    {
        $admin = $this->admin();
        $operator = $this->operator($admin, [Manager::CREATE, Manager::VIEW]);
        $grant = $operator->id;
        $assignment = DB::table('saas_capability_assignments')->where('user_id', $grant)->where('capability_code', Manager::CREATE)->first();
        app(SaasAccessManager::class)->revoke($admin, $operator->id, $assignment->id, ['revision' => 1, 'reason' => 'FICTICIO']);
        $this->actingAs($operator)->postJson(self::BASE, ['code' => 'ficticio', 'definition' => $this->definition()])->assertForbidden();
        $this->forbidden(fn () => app(Manager::class)->create($operator, []));
        $this->getJson(self::BASE)->assertOk();
        DB::table('users')->where('id', $operator->id)->update(['is_active' => false]);
        $this->forbidden(fn () => app(Manager::class)->index($operator));
        $this->getJson(self::BASE)->assertForbidden();
        $this->assertDatabaseCount('global_clinical_reasons', 0);
    }

    public function test_duplicate_codes_stale_versions_missing_resources_and_unchanged_activation_are_conflicts(): void
    {
        $operator = $this->operator($this->admin(), [Manager::VIEW, Manager::CREATE, Manager::PUBLISH, Manager::MANAGE]);
        $reason = $this->reason($operator)['reason'];
        $this->actingAs($operator)->postJson(self::BASE, ['code' => 'ficticio', 'definition' => $this->definition()])->assertConflict()->assertJsonPath('code', 'global_clinical_reason_code_conflict');
        $this->postJson(self::BASE.'/'.$reason->id.'/versions', ['expected_number' => 2, 'definition' => $this->definition()])->assertConflict()->assertJsonPath('code', 'clinical_reason_version_conflict');
        $this->postJson(self::BASE.'/'.$reason->id.'/activation', ['expected_number' => 1, 'is_active' => true, 'change_reason' => 'FICTICIO'])->assertConflict();
        foreach (['', '/versions', '/events'] as $suffix) {
            $this->getJson(self::BASE.'/999999'.$suffix)->assertNotFound();
        }
        $this->postJson(self::BASE.'/999999/versions', ['expected_number' => 1, 'definition' => $this->definition()])->assertNotFound();
        $this->postJson(self::BASE.'/999999/activation', ['expected_number' => 1, 'is_active' => false, 'change_reason' => 'FICTICIO'])->assertNotFound();
        $this->getJson(self::BASE.'?page=0')->assertUnprocessable();
        $this->assertDatabaseCount('global_clinical_reason_versions', 1);
    }

    #[DataProvider('invalidCreations')]
    public function test_invalid_administrative_input_returns_422_without_partial_identity(array $body, string $error): void
    {
        $operator = $this->operator($this->admin(), [Manager::CREATE]);
        $input = ['code' => 'ficticio', 'definition' => $this->definition()];
        if (isset($body['definition'])) {
            $body['definition'] = array_replace($input['definition'], $body['definition']);
        }
        $before = SaasAuditLog::count();
        $this->actingAs($operator)->postJson(self::BASE, array_replace($input, $body))->assertUnprocessable()->assertJsonValidationErrors($error);
        $this->assertDatabaseCount('global_clinical_reasons', 0);
        $this->assertSame($before, SaasAuditLog::count());
    }

    public static function invalidCreations(): array
    {
        return [
            [['code' => 'Invalid code'], 'code'],
            [['actor_id' => 999], 'input'],
            [['definition' => ['name' => ' ']], 'name'],
            [['definition' => ['description' => ' ']], 'description'],
            [['definition' => ['classification' => 'approved']], 'classification'],
            [['definition' => ['change_reason' => ' ']], 'change_reason'],
            [['definition' => ['is_active' => 'true']], 'is_active'],
            [['definition' => ['created_by' => 999]], 'definition'],
            [['definition' => ['evidence_requirements' => ['schema_version' => 1, 'requirements' => ['bad requirement']]]], 'evidence_requirements.requirements.0'],
            [['definition' => ['evidence_requirements' => ['schema_version' => '1', 'requirements' => []]]], 'definition'],
        ];
    }

    public function test_publication_cannot_change_activation_and_manage_cannot_change_definition(): void
    {
        $operator = $this->operator($this->admin(), [Manager::CREATE, Manager::PUBLISH, Manager::MANAGE]);
        $reason = $this->reason($operator)['reason'];
        $this->actingAs($operator)->postJson(self::BASE.'/'.$reason->id.'/versions', ['expected_number' => 1, 'definition' => $this->definition(['is_active' => false])])->assertUnprocessable()->assertJsonValidationErrors('definition.is_active');
        $this->postJson(self::BASE.'/'.$reason->id.'/activation', ['expected_number' => 1, 'is_active' => false, 'change_reason' => 'FICTICIO', 'classification' => 'terminal_candidate'])->assertUnprocessable()->assertJsonValidationErrors('input');
        $this->postJson(self::BASE.'/'.$reason->id.'/activation', ['expected_number' => '1', 'is_active' => false, 'change_reason' => 'FICTICIO'])->assertUnprocessable()->assertJsonValidationErrors('expected_number');
        $this->postJson(self::BASE.'/'.$reason->id.'/activation', ['expected_number' => 1, 'is_active' => 0, 'change_reason' => 'FICTICIO'])->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $this->assertDatabaseCount('global_clinical_reason_versions', 1);
    }

    #[DataProvider('writes')]
    public function test_audit_failure_rolls_back_each_catalog_write(string $operation): void
    {
        $operator = $this->operator($this->admin(), [Manager::CREATE, Manager::PUBLISH, Manager::MANAGE]);
        $reason = $operation === 'create' ? null : $this->reason($operator)['reason'];
        $before = SaasAuditLog::count();
        SaasAuditLog::created(fn () => throw new \RuntimeException('Injected catalog audit failure.'));
        try {
            match ($operation) {
                'create' => app(Manager::class)->create($operator, ['code' => 'ficticio', 'definition' => $this->definition()]),
                'publish' => app(Manager::class)->publish($operator, $reason->id, ['expected_number' => 1, 'definition' => $this->definition()]),
                'activation' => app(Manager::class)->activation($operator, $reason->id, ['expected_number' => 1, 'is_active' => false, 'change_reason' => 'FICTICIO']),
            };
            $this->fail('Audit failure must abort the operation.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected catalog audit failure.', $e->getMessage());
        } finally {
            SaasAuditLog::flushEventListeners();
            SaasAuditLog::clearBootedModels();
        }
        $this->assertSame($before, SaasAuditLog::count());
        $this->assertDatabaseCount('global_clinical_reasons', $reason === null ? 0 : 1);
        $this->assertDatabaseCount('global_clinical_reason_versions', $reason === null ? 0 : 1);
    }

    public static function writes(): array
    {
        return [['create'], ['publish'], ['activation']];
    }

    public function test_postgresql_protects_version_and_audit_references(): void
    {
        $operator = $this->operator($this->admin(), [Manager::CREATE]);
        $result = $this->reason($operator);
        $this->sqlRejected(fn () => DB::table('global_clinical_reason_versions')->where('id', $result['version']->id)->update(['name' => 'Alterado']));
        $this->sqlRejected(fn () => DB::table('global_clinical_reasons')->where('id', $result['reason']->id)->delete());
        $this->sqlRejected(fn () => DB::table('saas_audit_logs')->where('id', $result['audit_id'])->update(['global_reason_version_id' => null]));
        $this->sqlRejected(fn () => DB::table('saas_audit_logs')->where('id', $result['audit_id'])->delete());
        $event = SaasAuditLog::findOrFail($result['audit_id'])->getRawOriginal();
        unset($event['id']);
        $event['operation_id'] = (string) Str::uuid();
        $event['global_reason_version_id'] = 999999;
        $this->sqlRejected(fn () => DB::table('saas_audit_logs')->insert($event));
        $this->assertDatabaseCount('global_clinical_reason_versions', 1);
    }

    public function test_unused_governance_migration_round_trip_preserves_existing_access_audit(): void
    {
        $admin = $this->admin();
        $before = SaasAuditLog::sole()->getRawOriginal();
        $migration = require database_path('migrations/2026_10_25_073106_extend_saas_audit_for_global_clinical_reasons.php');
        $adoptions = require database_path('migrations/2026_10_25_081429_create_laboratory_clinical_reason_adoptions.php');
        $impacts = require database_path('migrations/2026_10_25_081115_create_global_clinical_reason_impacts.php');
        $integration = require database_path('migrations/2026_10_25_200359_create_clinical_exception_reason_references.php');
        $localIntegration = require database_path('migrations/2026_10_25_213438_integrate_local_clinical_reason_references_and_reviews.php');
        $localIntegration->down();
        $integration->down();
        $adoptions->down();
        $impacts->down();
        $migration->down();
        $this->assertDatabaseCount('saas_capabilities', 1);
        $migration->up();
        $impacts->up();
        $adoptions->up();
        $integration->up();
        $localIntegration->up();

        $this->assertSame($before, SaasAuditLog::sole()->getRawOriginal());
        $this->assertDatabaseCount('saas_capabilities', 6);
        $this->assertSame(['saas.access.manage'], DB::table('saas_capability_assignments')->where('user_id', $admin->id)->pluck('capability_code')->all());
    }

    public function test_governance_rollback_refuses_existing_clinical_faculty_history(): void
    {
        $this->operator($this->admin(), [Manager::VIEW]);
        $migration = require database_path('migrations/2026_10_25_073106_extend_saas_audit_for_global_clinical_reasons.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Global catalog governance history exists');
        $migration->down();
    }

    public function test_missing_definition_is_422_and_publish_cannot_initialize_a_bare_identity(): void
    {
        $operator = $this->operator($this->admin(), [Manager::CREATE, Manager::PUBLISH]);
        $this->actingAs($operator)->postJson(self::BASE, [])->assertUnprocessable()->assertJsonValidationErrors(['code', 'definition']);
        $reason = GlobalClinicalReason::create(['code' => 'ficticio', 'created_by' => $operator->id]);
        $this->postJson(self::BASE.'/'.$reason->id.'/versions', ['expected_number' => 1, 'definition' => $this->definition()])->assertConflict()->assertJsonPath('code', 'clinical_reason_version_conflict');
        $this->assertDatabaseCount('global_clinical_reason_versions', 0);
        $this->assertSame(0, SaasAuditLog::whereNotNull('global_reason_id')->count());
    }

    public function test_openapi_matches_all_seven_global_routes_and_their_exact_faculties(): void
    {
        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $doc = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/platform/clinical-reasons'));
        $this->assertCount(10, $routes);
        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth:sanctum', $middleware);
            $this->assertContains('user.active', $middleware);
            $this->assertNotContains('saas', $middleware);
            $op = $doc['paths']['/'.$route->uri()][strtolower($route->methods()[0])];
            $this->assertContains('saas.capability:'.$op['x-saas-capability'], $middleware);
            $this->assertSame([['sanctumCookie' => []]], $op['security']);
            $this->assertEmpty(collect($op['parameters'] ?? [])->where('name', 'X-Laboratory-ID'));
            foreach ([401, 403, 404, 409, 422] as $status) {
                $this->assertArrayHasKey($status, $op['responses']);
            }
        }
    }

    private function forbidden(callable $operation): void
    {
        try {
            $operation();
            $this->fail('The service must independently authorize.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    private function sqlRejected(callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('PostgreSQL must preserve history integrity.');
        } catch (QueryException $e) {
            $this->assertContains($e->errorInfo[0], ['23514', '23503']);
        }
    }
}

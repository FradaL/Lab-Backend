<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Authorization\RbacCatalog;
use App\Models\Branch;
use App\Models\Laboratory;
use App\Models\Patient;
use App\Models\PriceList;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CurrentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->clearPermissionTeam();
        parent::tearDown();
    }

    public function test_member_without_roles_or_permissions_receives_minimal_context_and_empty_arrays(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->request($user, $laboratory)
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'laboratory' => [
                        'id' => $laboratory->id,
                        'name' => $laboratory->name,
                    ],
                    'roles' => [],
                    'permissions' => [],
                ],
            ]);
    }

    public function test_direct_permission_only_is_reported_for_current_laboratory(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $this->assignDirectLaboratoryPermission($user, $laboratory, 'patients.view');

        $this->request($user, $laboratory)
            ->assertOk()
            ->assertJsonPath('data.roles', [])
            ->assertJsonPath('data.permissions', ['patients.view']);
    }

    #[DataProvider('roleProvider')]
    public function test_each_seeded_role_reports_its_exact_effective_catalog_matrix(string $role): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $this->seed(RolePermissionSeeder::class);
        $this->assignLaboratoryRole($user, $laboratory, $role);

        $response = $this->request($user, $laboratory)->assertOk();
        $expectedPermissions = RbacCatalog::rolePermissions()[$role];
        sort($expectedPermissions);

        $response
            ->assertJsonPath('data.roles', [$role])
            ->assertJsonPath('data.permissions', $expectedPermissions);
        $this->assertSame([], array_diff($response->json('data.permissions'), RbacCatalog::PERMISSIONS));
    }

    /** @return array<string, array{string}> */
    public static function roleProvider(): array
    {
        return [
            'owner' => ['owner'],
            'administrator' => ['administrator'],
            'receptionist' => ['receptionist'],
            'cashier' => ['cashier'],
            'laboratory technician' => ['laboratory_technician'],
            'viewer' => ['viewer'],
        ];
    }

    public function test_multiple_roles_and_direct_permissions_are_sorted_deduplicated_and_deterministic(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $this->seed(RolePermissionSeeder::class);
        $this->assignLaboratoryRole($user, $laboratory, 'viewer');
        $this->assignLaboratoryRole($user, $laboratory, 'cashier');
        $this->assignDirectLaboratoryPermission($user, $laboratory, 'patients.view');
        $this->assignDirectLaboratoryPermission($user, $laboratory, 'patients.create');

        $first = $this->request($user, $laboratory)->assertOk()->json();
        $second = $this->request($user, $laboratory)->assertOk()->json();

        $expected = array_values(array_unique(array_merge(
            RbacCatalog::rolePermissions()['viewer'],
            RbacCatalog::rolePermissions()['cashier'],
            ['patients.view', 'patients.create'],
        )));
        sort($expected);

        $this->assertSame($first, $second);
        $this->assertSame(['cashier', 'viewer'], $first['data']['roles']);
        $this->assertSame($expected, $first['data']['permissions']);
        $this->assertSame(count($expected), count(array_unique($first['data']['permissions'])));
    }

    public function test_role_and_direct_permission_are_isolated_across_a_b_a_requests(): void
    {
        [$user, $laboratoryA] = $this->activeTenant();
        [, $laboratoryB] = $this->activeTenant($user);
        $this->seed(RolePermissionSeeder::class);
        $this->assignLaboratoryRole($user, $laboratoryA, 'administrator');
        $this->assignLaboratoryRole($user, $laboratoryB, 'viewer');
        $this->assignDirectLaboratoryPermission($user, $laboratoryA, 'patients.create');

        $this->setPermissionTeam($laboratoryA);
        $user->load('roles', 'permissions', 'roles.permissions');

        $responseA = $this->request($user, $laboratoryA)->assertOk();
        $responseB = $this->request($user, $laboratoryB)->assertOk();
        $responseAAgain = $this->request($user, $laboratoryA)->assertOk();

        $expectedAdmin = RbacCatalog::PERMISSIONS;
        $expectedViewer = RbacCatalog::rolePermissions()['viewer'];
        sort($expectedAdmin);
        sort($expectedViewer);

        $responseA->assertJsonPath('data.roles', ['administrator'])
            ->assertJsonPath('data.permissions', $expectedAdmin);
        $responseB->assertJsonPath('data.roles', ['viewer'])
            ->assertJsonPath('data.permissions', $expectedViewer);
        $responseAAgain->assertJsonPath('data.roles', ['administrator'])
            ->assertJsonPath('data.permissions', $expectedAdmin);
        $this->assertContains('patients.create', $responseA->json('data.permissions'));
        $this->assertNotContains('patients.create', $responseB->json('data.permissions'));
    }

    public function test_middleware_cleans_team_and_loaded_relations_after_response(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $this->seed(RolePermissionSeeder::class);
        $this->assignLaboratoryRole($user, $laboratory, 'viewer');

        $this->request($user, $laboratory)->assertOk();

        $this->assertNull(getPermissionsTeamId());
        $this->assertFalse($user->relationLoaded('roles'));
        $this->assertFalse($user->relationLoaded('permissions'));
    }

    public function test_guest_is_unauthorized(): void
    {
        $this->getJson('/api/v1/auth/authorization')->assertUnauthorized();
    }

    public function test_missing_invalid_unknown_and_inactive_laboratory_keep_context_contracts(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')->getJson('/api/v1/auth/authorization')
            ->assertBadRequest()->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', 'invalid')
            ->getJson('/api/v1/auth/authorization')
            ->assertBadRequest()->assertJsonPath('code', 'INVALID_LABORATORY_CONTEXT');
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', '999999')
            ->getJson('/api/v1/auth/authorization')
            ->assertNotFound()->assertJsonPath('code', 'LABORATORY_NOT_FOUND');

        $inactive = Laboratory::factory()->create(['is_active' => false]);
        $user->laboratories()->attach($inactive, ['is_active' => true]);
        $this->request($user, $inactive)
            ->assertForbidden()->assertJsonPath('code', 'LABORATORY_INACTIVE');
    }

    public function test_inactive_membership_and_missing_subscription_keep_saas_contracts(): void
    {
        $laboratory = Laboratory::factory()->create();
        $user = $this->createLaboratoryMember($laboratory, false);
        $this->createSubscription($laboratory);

        $this->request($user, $laboratory)
            ->assertForbidden()->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $user->laboratories()->updateExistingPivot($laboratory, ['is_active' => true]);
        $laboratory->subscriptions()->delete();

        $this->request($user, $laboratory)
            ->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_request_is_read_only_and_creates_no_audit_log(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $this->seed(RolePermissionSeeder::class);
        $this->assignLaboratoryRole($user, $laboratory, 'receptionist');
        $tables = [
            'laboratories', 'laboratory_user', 'roles', 'permissions',
            'model_has_roles', 'model_has_permissions', 'audit_logs',
        ];
        $before = collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()]);

        $this->request($user, $laboratory)->assertOk();

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "{$table} changed during authorization read");
        }
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_reported_patient_permission_matches_real_gate_enforcement(): void
    {
        [$denied, $laboratory] = $this->activeTenant();
        $allowed = $this->createLaboratoryMember($laboratory);
        $this->assignDirectLaboratoryPermission($allowed, $laboratory, 'patients.create');

        $this->assertNotContains('patients.create', $this->request($denied, $laboratory)->json('data.permissions'));
        $this->patientCreateRequest($denied, $laboratory)->assertForbidden();

        $this->assertContains('patients.create', $this->request($allowed, $laboratory)->json('data.permissions'));
        $this->patientCreateRequest($allowed, $laboratory)->assertCreated();
    }

    public function test_receptionist_report_matches_representative_order_enforcement(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $this->seed(RolePermissionSeeder::class);
        $this->assignLaboratoryRole($user, $laboratory, 'receptionist');
        $branch = Branch::factory()->for($laboratory)->create();
        $patient = Patient::factory()->for($laboratory)->create();
        $priceList = PriceList::factory()->for($laboratory)->create(['currency' => 'GTQ']);
        $permissions = $this->request($user, $laboratory)->json('data.permissions');

        $this->assertContains('orders.create', $permissions);
        $this->assertContains('orders.add_exam', $permissions);
        $this->assertContains('orders.remove_exam', $permissions);
        $this->assertNotContains('orders.manage_discount', $permissions);
        $this->assertNotContains('orders.change_status', $permissions);

        $orderId = $this->requestJson($user, $laboratory, 'POST', '/api/v1/laboratory-orders', [
            'branch_id' => $branch->id,
            'patient_id' => $patient->id,
            'doctor_id' => null,
            'commercial_client_id' => null,
            'price_list_id' => $priceList->id,
            'ordered_at' => '2026-10-03 14:30:00',
            'notes' => null,
        ])->assertCreated()->json('data.id');
        $this->requestJson($user, $laboratory, 'PUT', "/api/v1/laboratory-orders/{$orderId}/discount")
            ->assertForbidden();
        $this->requestJson($user, $laboratory, 'PATCH', "/api/v1/laboratory-orders/{$orderId}/status")
            ->assertForbidden();
    }

    public function test_reported_pricing_permission_matches_real_gate_enforcement(): void
    {
        [$cashier, $laboratory] = $this->activeTenant();
        $technician = $this->createLaboratoryMember($laboratory);
        $this->seed(RolePermissionSeeder::class);
        $this->assignLaboratoryRole($cashier, $laboratory, 'cashier');
        $this->assignLaboratoryRole($technician, $laboratory, 'laboratory_technician');

        $this->assertContains('pricing.resolve', $this->request($cashier, $laboratory)->json('data.permissions'));
        $this->requestJson($cashier, $laboratory, 'POST', '/api/v1/pricing/resolve-price-list')
            ->assertUnprocessable();

        $this->assertNotContains('pricing.resolve', $this->request($technician, $laboratory)->json('data.permissions'));
        $this->requestJson($technician, $laboratory, 'POST', '/api/v1/pricing/resolve-price-list')
            ->assertForbidden();
    }

    public function test_demo_users_receive_their_effective_authorization(): void
    {
        $laboratory = Laboratory::factory()->create(['nit' => '000000000001']);
        $admin = User::factory()->create(['email' => 'admin@donqerlab.test']);
        $receptionist = User::factory()->create(['email' => 'reception@donqerlab.test']);
        foreach ([$admin, $receptionist] as $user) {
            $user->laboratories()->attach($laboratory, ['is_active' => true]);
        }
        $this->createSubscription($laboratory);
        $this->seed(RolePermissionSeeder::class);
        $allPermissions = RbacCatalog::PERMISSIONS;
        $receptionPermissions = array_values(array_unique(array_merge(
            RbacCatalog::rolePermissions()['receptionist'],
            RbacCatalog::rolePermissions()['cashier'],
        )));
        sort($allPermissions);
        sort($receptionPermissions);

        $this->request($admin, $laboratory)->assertOk()
            ->assertJsonPath('data.roles', ['administrator'])
            ->assertJsonPath('data.permissions', $allPermissions);
        $this->request($receptionist, $laboratory)->assertOk()
            ->assertJsonPath('data.roles', ['cashier', 'receptionist'])
            ->assertJsonPath('data.permissions', $receptionPermissions);
    }

    public function test_query_count_is_fixed_independent_of_permission_count(): void
    {
        [$viewer, $laboratory] = $this->activeTenant();
        $owner = $this->createLaboratoryMember($laboratory);
        $this->seed(RolePermissionSeeder::class);
        $this->assignLaboratoryRole($viewer, $laboratory, 'viewer');
        $this->assignLaboratoryRole($owner, $laboratory, 'owner');
        $queries = [];
        $capture = false;
        DB::listen(function (QueryExecuted $query) use (&$queries, &$capture): void {
            if ($capture) {
                $queries[] = $query->sql;
            }
        });

        $capture = true;
        $this->request($viewer, $laboratory)->assertOk();
        $capture = false;
        $viewerCount = count($queries);
        $queries = [];
        $capture = true;
        $this->request($owner, $laboratory)->assertOk();
        $capture = false;
        $ownerCount = count($queries);

        $this->assertSame($viewerCount, $ownerCount);
        $this->assertLessThanOrEqual(8, $ownerCount);
    }

    public function test_role_assignment_change_is_fresh_for_report_and_gate(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $this->seed(RolePermissionSeeder::class);
        $this->assignLaboratoryRole($user, $laboratory, 'viewer');

        $this->assertNotContains('patients.create', $this->request($user, $laboratory)->json('data.permissions'));
        $this->patientCreateRequest($user, $laboratory)->assertForbidden();

        $this->setPermissionTeam($laboratory);
        $user->syncRoles(Role::findByName('receptionist', RbacCatalog::GUARD));

        $this->request($user, $laboratory)
            ->assertJsonPath('data.roles', ['receptionist']);
        $this->assertContains('patients.create', $this->request($user, $laboratory)->json('data.permissions'));
        $this->patientCreateRequest($user, $laboratory)->assertCreated();
    }

    public function test_role_removal_is_fresh_on_the_next_request(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $this->seed(RolePermissionSeeder::class);
        $role = $this->assignLaboratoryRole($user, $laboratory, 'viewer');

        $this->request($user, $laboratory)
            ->assertJsonPath('data.roles', ['viewer'])
            ->assertJsonPath('data.permissions.0', 'branches.view');

        $this->setPermissionTeam($laboratory);
        $user->removeRole($role);

        $this->request($user, $laboratory)
            ->assertJsonPath('data.roles', [])
            ->assertJsonPath('data.permissions', []);
    }

    public function test_direct_permission_removal_is_fresh_for_report_and_gate(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $permission = $this->assignDirectLaboratoryPermission($user, $laboratory, 'patients.create');

        $this->assertContains('patients.create', $this->request($user, $laboratory)->json('data.permissions'));
        $this->patientCreateRequest($user, $laboratory)->assertCreated();

        $this->setPermissionTeam($laboratory);
        $user->revokePermissionTo($permission);

        $this->assertNotContains('patients.create', $this->request($user, $laboratory)->json('data.permissions'));
        $this->patientCreateRequest($user, $laboratory)->assertForbidden();
    }

    public function test_route_is_tenant_aware_without_can_and_business_inventory_remains_61(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn (IlluminateRoute $route): bool => $route->uri() === 'api/v1/auth/authorization');

        $this->assertNotNull($route);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertContains('saas', $route->gatherMiddleware());
        $this->assertFalse(collect($route->gatherMiddleware())
            ->contains(fn (string $middleware): bool => str_starts_with($middleware, 'can:')));
        $this->assertSame(62, collect(Route::getRoutes()->getRoutes())
            ->filter(fn (IlluminateRoute $route): bool => str_starts_with($route->uri(), 'api/v1/'))
            ->filter(fn (IlluminateRoute $route): bool => collect($route->gatherMiddleware())
                ->contains(fn (string $middleware): bool => str_starts_with($middleware, 'can:')))
            ->count());
    }

    public function test_openapi_documents_authorization_without_changing_identity_or_discovery_contracts(): void
    {
        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(
            file_get_contents(storage_path('api-docs/api-docs.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $operation = $document['paths']['/api/v1/auth/authorization']['get'];
        $authorization = $document['components']['schemas']['CurrentAuthorization'];
        $laboratory = $document['components']['schemas']['CurrentAuthorizationLaboratory'];
        $currentUser = $document['components']['schemas']['CurrentUserResponse'];
        $availableLaboratory = $document['components']['schemas']['AvailableLaboratory'];

        $this->assertSame([['sanctumCookie' => []]], $operation['security']);
        $this->assertSame('#/components/parameters/LaboratoryContextHeader', $operation['parameters'][0]['$ref']);
        $this->assertSame([200, 400, 401, 403, 404], array_keys($operation['responses']));
        $this->assertSame(
            '#/components/schemas/CurrentAuthorizationResponse',
            $operation['responses']['200']['content']['application/json']['schema']['$ref'],
        );
        $this->assertSame(['laboratory', 'roles', 'permissions'], $authorization['required']);
        $this->assertSame(['id', 'name'], $laboratory['required']);
        $this->assertSame('array', $authorization['properties']['roles']['type']);
        $this->assertSame('string', $authorization['properties']['roles']['items']['type']);
        $this->assertSame('array', $authorization['properties']['permissions']['type']);
        $this->assertSame('string', $authorization['properties']['permissions']['items']['type']);
        $this->assertSame(['user'], $currentUser['properties']['data']['required']);
        $this->assertArrayNotHasKey('roles', $currentUser['properties']['data']['properties']);
        $this->assertArrayNotHasKey('permissions', $availableLaboratory['properties']);
        $this->assertArrayNotHasKey('roles', $availableLaboratory['properties']);
    }

    /** @return array{User, Laboratory} */
    private function activeTenant(?User $user = null): array
    {
        $laboratory = Laboratory::factory()->create();
        $user ??= $this->createLaboratoryMember($laboratory);
        if (! $user->laboratories()->whereKey($laboratory->id)->exists()) {
            $user->laboratories()->attach($laboratory, ['is_active' => true]);
        }
        $this->createSubscription($laboratory);

        return [$user, $laboratory];
    }

    private function createSubscription(Laboratory $laboratory): void
    {
        Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ]);
    }

    private function request(User $user, Laboratory $laboratory): TestResponse
    {
        return $this->requestJson($user, $laboratory, 'GET', '/api/v1/auth/authorization');
    }

    /** @param array<string, mixed> $payload */
    private function requestJson(
        User $user,
        Laboratory $laboratory,
        string $method,
        string $uri,
        array $payload = [],
    ): TestResponse {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->json($method, $uri, $payload);
    }

    private function patientCreateRequest(User $user, Laboratory $laboratory): TestResponse
    {
        return $this->requestJson($user, $laboratory, 'POST', '/api/v1/patients', [
            'first_names' => 'Authorization',
            'last_names' => 'Consistency',
        ]);
    }
}

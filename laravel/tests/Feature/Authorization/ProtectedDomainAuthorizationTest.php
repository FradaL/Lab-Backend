<?php

namespace Tests\Feature\Authorization;

use App\Authorization\RbacCatalog;
use App\Models\AuditLog;
use App\Models\CommercialClient;
use App\Models\Laboratory;
use App\Models\LaboratoryExam;
use App\Models\Patient;
use App\Models\PriceList;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ProtectedDomainAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->clearPermissionTeam();
        parent::tearDown();
    }

    public function test_protected_route_inventory_has_each_exact_catalog_permission_once(): void
    {
        $actual = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (IlluminateRoute $route): bool => str_starts_with($route->uri(), 'api/v1/'))
            ->flatMap(function (IlluminateRoute $route): array {
                return collect($route->gatherMiddleware())
                    ->filter(fn (string $middleware): bool => str_starts_with($middleware, 'can:'))
                    ->mapWithKeys(fn (string $middleware): array => [
                        $route->methods()[0].' '.$route->uri() => substr($middleware, 4),
                    ])->all();
            })
            ->sortKeys()
            ->all();
        $expected = $this->protectedRoutes();
        ksort($expected);

        $this->assertSame($expected, $actual);
        $this->assertCount(61, $actual);
        $this->assertEmpty(array_diff(array_values($actual), RbacCatalog::PERMISSIONS));
        $this->assertSame(9, collect(Route::getRoutes()->getRoutes())
            ->filter(fn (IlluminateRoute $route): bool => str_starts_with($route->uri(), 'api/v1/laboratory-orders'))
            ->filter(fn (IlluminateRoute $route): bool => collect($route->gatherMiddleware())
                ->contains(fn (string $middleware): bool => str_starts_with($middleware, 'can:')))
            ->count());
    }

    public function test_member_without_permission_is_forbidden_before_validation_mutation_and_audit(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson('/api/v1/patients', [])
            ->assertForbidden();

        $this->assertDatabaseCount('patients', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_direct_permission_is_enforced_over_http_without_stale_cross_tenant_state(): void
    {
        [$user, $laboratoryA] = $this->activeTenant();
        [, $laboratoryB] = $this->activeTenant($user);
        $this->assignDirectLaboratoryPermission($user, $laboratoryA, 'patients.create');

        $this->patientCreateRequest($user, $laboratoryA, 'Tenant', 'A1')->assertCreated();
        $this->patientCreateRequest($user, $laboratoryB, 'Tenant', 'B')->assertForbidden();
        $this->patientCreateRequest($user, $laboratoryA, 'Tenant', 'A2')->assertCreated();

        $this->assertDatabaseCount('patients', 2);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertSame([$laboratoryA->id], Patient::query()->pluck('laboratory_id')->unique()->all());
        $this->assertSame([$laboratoryA->id], AuditLog::query()->pluck('laboratory_id')->unique()->all());
    }

    public function test_direct_view_permission_is_isolated_between_laboratories_over_http(): void
    {
        [$user, $laboratoryA] = $this->activeTenant();
        [, $laboratoryB] = $this->activeTenant($user);
        $this->assignDirectLaboratoryPermission($user, $laboratoryA, 'patients.view');

        $this->roleMatrixRequest($user, $laboratoryA, 'GET', '/api/v1/patients')->assertOk();
        $this->roleMatrixRequest($user, $laboratoryB, 'GET', '/api/v1/patients')->assertForbidden();
        $this->roleMatrixRequest($user, $laboratoryA, 'GET', '/api/v1/patients')->assertOk();
    }

    public function test_unauthorized_audited_domains_do_not_mutate_or_write_audit_logs(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create();
        $commercialClient = CommercialClient::factory()->for($laboratory)->create();
        $laboratoryExam = LaboratoryExam::factory()->for($laboratory)->create();
        $requests = [
            ['POST', '/api/v1/patients'],
            ['POST', '/api/v1/laboratory-exams'],
            ['POST', '/api/v1/commercial-clients'],
            ['POST', '/api/v1/price-lists'],
            ['PUT', "/api/v1/price-lists/{$priceList->id}/exams/{$laboratoryExam->id}"],
            ['POST', "/api/v1/commercial-clients/{$commercialClient->id}/price-list-assignments"],
        ];
        $tables = [
            'patients', 'laboratory_exams', 'commercial_clients', 'price_lists',
            'price_list_exams', 'commercial_client_price_lists', 'audit_logs',
        ];
        $before = collect($tables)->mapWithKeys(fn (string $table): array => [
            $table => DB::table($table)->count(),
        ]);

        foreach ($requests as [$method, $uri]) {
            $this->roleMatrixRequest($user, $laboratory, $method, $uri)->assertForbidden();
        }

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "{$table} changed after denied requests");
        }
    }

    #[DataProvider('rolePatientCreateProvider')]
    public function test_seeded_role_matrix_is_enforced_by_real_requests(string $role, bool $allowed): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $this->seed(RolePermissionSeeder::class);
        $this->assignLaboratoryRole($user, $laboratory, $role);

        $response = $this->patientCreateRequest($user, $laboratory, 'Role', $role);

        if ($allowed) {
            $response->assertCreated();
            $this->assertDatabaseCount('patients', 1);
            $this->assertDatabaseCount('audit_logs', 1);
        } else {
            $response->assertForbidden();
            $this->assertDatabaseCount('patients', 0);
            $this->assertDatabaseCount('audit_logs', 0);
        }
    }

    #[DataProvider('roleDomainAccessProvider')]
    public function test_seeded_roles_enforce_the_domain_matrix_over_http(
        string $role,
        array $allowedReads,
        array $allowedMutations,
    ): void {
        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create();
        $commercialClient = CommercialClient::factory()->for($laboratory)->create();
        $this->seed(RolePermissionSeeder::class);
        $this->assignLaboratoryRole($user, $laboratory, $role);

        $reads = [
            'branches' => ['GET', '/api/v1/branches/active'],
            'patients' => ['GET', '/api/v1/patients'],
            'doctors' => ['GET', '/api/v1/doctors'],
            'areas' => ['GET', '/api/v1/laboratory-areas'],
            'sample_types' => ['GET', '/api/v1/sample-types'],
            'exams' => ['GET', '/api/v1/laboratory-exams'],
            'commercial_clients' => ['GET', '/api/v1/commercial-clients'],
            'price_lists' => ['GET', '/api/v1/price-lists'],
            'exam_prices' => ['GET', "/api/v1/price-lists/{$priceList->id}/exams"],
            'commercial_assignments' => ['GET', "/api/v1/commercial-clients/{$commercialClient->id}/price-list-assignments"],
            'pricing' => ['POST', '/api/v1/pricing/resolve-price-list'],
        ];
        $mutations = [
            'patients' => ['POST', '/api/v1/patients'],
            'doctors' => ['POST', '/api/v1/doctors'],
            'areas' => ['POST', '/api/v1/laboratory-areas'],
            'sample_types' => ['POST', '/api/v1/sample-types'],
            'exams' => ['POST', '/api/v1/laboratory-exams'],
            'commercial_clients' => ['POST', '/api/v1/commercial-clients'],
            'price_lists' => ['POST', '/api/v1/price-lists'],
            'exam_prices' => ['PUT', "/api/v1/price-lists/{$priceList->id}/exams/bulk"],
            'commercial_assignments' => ['POST', "/api/v1/commercial-clients/{$commercialClient->id}/price-list-assignments"],
        ];

        foreach ($reads as $capability => [$method, $uri]) {
            $response = $this->roleMatrixRequest($user, $laboratory, $method, $uri);
            in_array($capability, $allowedReads, true)
                ? $this->assertNotSame(403, $response->status(), "{$role} unexpectedly denied {$capability} read")
                : $response->assertForbidden();
        }

        foreach ($mutations as $capability => [$method, $uri]) {
            $response = $this->roleMatrixRequest($user, $laboratory, $method, $uri);
            in_array($capability, $allowedMutations, true)
                ? $this->assertNotSame(403, $response->status(), "{$role} unexpectedly denied {$capability} mutation")
                : $response->assertForbidden();
        }
    }

    /** @return array<string, array{string, bool}> */
    public static function rolePatientCreateProvider(): array
    {
        return [
            'owner' => ['owner', true],
            'administrator' => ['administrator', true],
            'receptionist' => ['receptionist', true],
            'cashier' => ['cashier', false],
            'laboratory technician' => ['laboratory_technician', false],
            'viewer' => ['viewer', false],
        ];
    }

    /** @return array<string, array{string, array<int, string>, array<int, string>}> */
    public static function roleDomainAccessProvider(): array
    {
        $allReads = [
            'branches', 'patients', 'doctors', 'areas', 'sample_types', 'exams',
            'commercial_clients', 'price_lists', 'exam_prices', 'commercial_assignments', 'pricing',
        ];
        $allMutations = [
            'patients', 'doctors', 'areas', 'sample_types', 'exams', 'commercial_clients',
            'price_lists', 'exam_prices', 'commercial_assignments',
        ];

        return [
            'owner domain access' => ['owner', $allReads, $allMutations],
            'administrator domain access' => ['administrator', $allReads, $allMutations],
            'receptionist domain access' => ['receptionist', $allReads, ['patients', 'doctors']],
            'cashier domain access' => ['cashier', $allReads, []],
            'technician domain access' => ['laboratory_technician', [
                'branches', 'patients', 'doctors', 'areas', 'sample_types', 'exams',
            ], []],
            'viewer domain access' => ['viewer', $allReads, []],
        ];
    }

    public function test_membership_and_subscription_failures_keep_priority_over_permission(): void
    {
        $laboratory = Laboratory::factory()->create();
        $user = $this->createLaboratoryMember($laboratory, false);
        $this->assignDirectLaboratoryPermission($user, $laboratory, 'patients.view');
        $this->createSubscription($laboratory);

        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson('/api/v1/patients')
            ->assertForbidden()->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $user->laboratories()->updateExistingPivot($laboratory, ['is_active' => true]);
        $laboratory->subscriptions()->delete();

        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson('/api/v1/patients')
            ->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_demo_users_receive_seeded_roles_and_are_authorized_by_real_requests(): void
    {
        $laboratory = Laboratory::factory()->create(['nit' => '000000000001']);
        $admin = User::factory()->create(['email' => 'admin@donqerlab.test']);
        $receptionist = User::factory()->create(['email' => 'reception@donqerlab.test']);
        $admin->laboratories()->attach($laboratory, ['is_active' => true]);
        $receptionist->laboratories()->attach($laboratory, ['is_active' => true]);
        $this->createSubscription($laboratory);
        $this->seed(RolePermissionSeeder::class);

        $this->patientCreateRequest($admin, $laboratory, 'Demo', 'Admin')->assertCreated();
        $this->patientCreateRequest($receptionist, $laboratory, 'Demo', 'Reception')->assertCreated();
        $this->assertSame([$admin->id, $receptionist->id], AuditLog::query()->orderBy('id')->pluck('user_id')->all());
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

    private function patientCreateRequest(
        User $user,
        Laboratory $laboratory,
        string $firstNames,
        string $lastNames,
    ): TestResponse {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson('/api/v1/patients', [
                'first_names' => $firstNames,
                'last_names' => $lastNames,
            ]);
    }

    private function roleMatrixRequest(
        User $user,
        Laboratory $laboratory,
        string $method,
        string $uri,
    ): TestResponse {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->json($method, $uri);
    }

    /** @return array<string, string> */
    private function protectedRoutes(): array
    {
        return [
            'GET api/v1/branches/active' => 'branches.view',
            'GET api/v1/patients' => 'patients.view',
            'POST api/v1/patients' => 'patients.create',
            'GET api/v1/patients/{patient}' => 'patients.view',
            'PATCH api/v1/patients/{patient}' => 'patients.update',
            'PATCH api/v1/patients/{patient}/status' => 'patients.change_status',
            'GET api/v1/doctors' => 'doctors.view',
            'POST api/v1/doctors' => 'doctors.create',
            'GET api/v1/doctors/{doctor}' => 'doctors.view',
            'PATCH api/v1/doctors/{doctor}' => 'doctors.update',
            'PATCH api/v1/doctors/{doctor}/status' => 'doctors.change_status',
            'GET api/v1/laboratory-areas' => 'laboratory_areas.view',
            'POST api/v1/laboratory-areas' => 'laboratory_areas.create',
            'GET api/v1/laboratory-areas/active' => 'laboratory_areas.view',
            'GET api/v1/laboratory-areas/{area}' => 'laboratory_areas.view',
            'PATCH api/v1/laboratory-areas/{area}' => 'laboratory_areas.update',
            'PATCH api/v1/laboratory-areas/{area}/status' => 'laboratory_areas.change_status',
            'GET api/v1/sample-types' => 'sample_types.view',
            'POST api/v1/sample-types' => 'sample_types.create',
            'GET api/v1/sample-types/active' => 'sample_types.view',
            'GET api/v1/sample-types/{sampleType}' => 'sample_types.view',
            'PATCH api/v1/sample-types/{sampleType}' => 'sample_types.update',
            'PATCH api/v1/sample-types/{sampleType}/status' => 'sample_types.change_status',
            'GET api/v1/laboratory-exams' => 'laboratory_exams.view',
            'POST api/v1/laboratory-exams' => 'laboratory_exams.create',
            'GET api/v1/laboratory-exams/active' => 'laboratory_exams.view',
            'GET api/v1/laboratory-exams/{laboratoryExam}' => 'laboratory_exams.view',
            'PATCH api/v1/laboratory-exams/{laboratoryExam}' => 'laboratory_exams.update',
            'PATCH api/v1/laboratory-exams/{laboratoryExam}/status' => 'laboratory_exams.change_status',
            'GET api/v1/commercial-clients' => 'commercial_clients.view',
            'POST api/v1/commercial-clients' => 'commercial_clients.create',
            'GET api/v1/commercial-clients/active' => 'commercial_clients.view',
            'GET api/v1/commercial-clients/{commercialClient}' => 'commercial_clients.view',
            'PATCH api/v1/commercial-clients/{commercialClient}' => 'commercial_clients.update',
            'PATCH api/v1/commercial-clients/{commercialClient}/status' => 'commercial_clients.change_status',
            'GET api/v1/price-lists' => 'price_lists.view',
            'POST api/v1/price-lists' => 'price_lists.create',
            'GET api/v1/price-lists/active' => 'price_lists.view',
            'GET api/v1/price-lists/{priceList}' => 'price_lists.view',
            'PATCH api/v1/price-lists/{priceList}' => 'price_lists.update',
            'PATCH api/v1/price-lists/{priceList}/status' => 'price_lists.change_status',
            'PATCH api/v1/price-lists/{priceList}/default' => 'price_lists.set_default',
            'GET api/v1/price-lists/{priceList}/available-exams' => 'exam_prices.view',
            'GET api/v1/price-lists/{priceList}/exams' => 'exam_prices.view',
            'PUT api/v1/price-lists/{priceList}/exams/bulk' => 'exam_prices.manage',
            'PUT api/v1/price-lists/{priceList}/exams/{laboratoryExam}' => 'exam_prices.manage',
            'PATCH api/v1/price-lists/{priceList}/exams/{laboratoryExam}/status' => 'exam_prices.manage',
            'GET api/v1/commercial-clients/{commercialClient}/price-list-assignments' => 'commercial_price_assignments.view',
            'POST api/v1/commercial-clients/{commercialClient}/price-list-assignments' => 'commercial_price_assignments.manage',
            'PATCH api/v1/commercial-clients/{commercialClient}/price-list-assignments/{assignment}' => 'commercial_price_assignments.manage',
            'PATCH api/v1/commercial-clients/{commercialClient}/price-list-assignments/{assignment}/status' => 'commercial_price_assignments.manage',
            'POST api/v1/pricing/resolve-price-list' => 'pricing.resolve',
            'GET api/v1/laboratory-orders' => 'orders.view',
            'POST api/v1/laboratory-orders' => 'orders.create',
            'GET api/v1/laboratory-orders/{laboratoryOrder}' => 'orders.view',
            'GET api/v1/laboratory-orders/{laboratoryOrder}/exams' => 'orders.view',
            'POST api/v1/laboratory-orders/{laboratoryOrder}/exams' => 'orders.add_exam',
            'DELETE api/v1/laboratory-orders/{laboratoryOrder}/exams/{laboratoryOrderExam}' => 'orders.remove_exam',
            'PUT api/v1/laboratory-orders/{laboratoryOrder}/discount' => 'orders.manage_discount',
            'DELETE api/v1/laboratory-orders/{laboratoryOrder}/discount' => 'orders.manage_discount',
            'PATCH api/v1/laboratory-orders/{laboratoryOrder}/status' => 'orders.change_status',
        ];
    }
}

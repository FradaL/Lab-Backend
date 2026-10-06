<?php

namespace Tests\Feature\Api\V1\Branches;

use App\Http\Controllers\Api\V1\BranchController;
use App\Models\Branch;
use App\Models\Laboratory;
use App\Models\Patient;
use App\Models\PriceList;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class BranchActiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC'));
    }

    public function test_catalog_returns_only_active_current_tenant_branches_in_exact_shape(): void
    {
        $user = User::factory()->create();
        [, $laboratoryA] = $this->activeTenant($user);
        [, $laboratoryB] = $this->activeTenant($user);
        $activeA = $this->branch($laboratoryA, [
            'code' => 'A1',
            'name' => 'Sucursal A1',
            'is_main' => true,
        ]);
        $this->branch($laboratoryA, [
            'code' => 'A2',
            'name' => 'Sucursal A2',
            'status' => 'inactive',
        ]);
        $this->branch($laboratoryB, [
            'code' => 'B1',
            'name' => 'Sucursal B1',
        ]);

        $response = $this->activeRequest($user, $laboratoryA)
            ->assertOk()
            ->assertExactJson([
                'data' => [[
                    'id' => $activeA->id,
                    'code' => 'A1',
                    'name' => 'Sucursal A1',
                    'is_main' => true,
                ]],
            ])
            ->assertJsonMissingPath('links')
            ->assertJsonMissingPath('meta');

        $item = $response->json('data.0');
        $this->assertSame(['id', 'code', 'name', 'is_main'], array_keys($item));
        $this->assertIsInt($item['id']);
        $this->assertIsString($item['code']);
        $this->assertIsString($item['name']);
        $this->assertIsBool($item['is_main']);
        foreach (['laboratory_id', 'status', 'phone', 'email', 'address', 'created_at', 'updated_at', 'is_active', 'is_default'] as $field) {
            $this->assertArrayNotHasKey($field, $item);
        }
    }

    #[DataProvider('emptyCatalogProvider')]
    public function test_empty_or_only_inactive_catalog_returns_data_array(bool $createInactive): void
    {
        [$user, $laboratory] = $this->activeTenant();

        if ($createInactive) {
            $this->branch($laboratory, ['status' => 'inactive']);
        }

        $this->activeRequest($user, $laboratory)
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    /** @return array<string, array{bool}> */
    public static function emptyCatalogProvider(): array
    {
        return [
            'empty' => [false],
            'only inactive' => [true],
        ];
    }

    public function test_ordering_is_main_then_name_then_id(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $regularZulu = $this->branch($laboratory, ['code' => 'RZ', 'name' => 'Zulu']);
        $regularAlphaFirst = $this->branch($laboratory, ['code' => 'RA1', 'name' => 'Alpha']);
        $mainZulu = $this->branch($laboratory, ['code' => 'MZ', 'name' => 'Zulu', 'is_main' => true]);
        $mainAlpha = $this->branch($laboratory, ['code' => 'MA', 'name' => 'Alpha', 'is_main' => true]);
        $regularAlphaSecond = $this->branch($laboratory, ['code' => 'RA2', 'name' => 'Alpha']);

        $response = $this->activeRequest($user, $laboratory)->assertOk();

        $this->assertSame([
            $mainAlpha->id,
            $mainZulu->id,
            $regularAlphaFirst->id,
            $regularAlphaSecond->id,
            $regularZulu->id,
        ], $response->json('data.*.id'));
    }

    public function test_all_active_branches_are_returned_without_pagination(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        foreach (range(1, 105) as $number) {
            $this->branch($laboratory, [
                'code' => sprintf('B%03d', $number),
                'name' => sprintf('Sucursal %03d', $number),
            ]);
        }

        $this->activeRequest($user, $laboratory)
            ->assertOk()
            ->assertJsonCount(105, 'data')
            ->assertJsonMissingPath('links')
            ->assertJsonMissingPath('meta');
    }

    #[DataProvider('queryParameterProvider')]
    public function test_every_query_parameter_is_rejected(string $parameter, string $value): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $this->branch($laboratory);

        $this->activeRequest($user, $laboratory, [$parameter => $value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$parameter]);
    }

    /** @return array<string, array{string, string}> */
    public static function queryParameterProvider(): array
    {
        return [
            'search' => ['search', 'central'],
            'status' => ['status', 'inactive'],
            'sort' => ['sort', 'name'],
            'direction' => ['direction', 'desc'],
            'page' => ['page', '1'],
            'per page' => ['per_page', '10'],
            'tenant injection' => ['laboratory_id', '999'],
            'unknown' => ['foo', 'bar'],
        ];
    }

    public function test_catalog_uses_one_tenant_status_scoped_query_without_relations(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $this->branch($laboratory);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains(strtolower($query->sql), 'branches')) {
                $queries[] = $query;
            }
        });

        $this->activeRequest($user, $laboratory)->assertOk();

        $selects = array_values(array_filter(
            $queries,
            fn (QueryExecuted $query): bool => str_starts_with(strtolower(ltrim($query->sql)), 'select'),
        ));
        $this->assertCount(1, $selects);
        $sql = strtolower($selects[0]->sql);
        $this->assertStringContainsString('select "id", "code", "name", "is_main"', $sql);
        $this->assertStringContainsString('"laboratory_id" = ?', $sql);
        $this->assertStringContainsString('"status" = ?', $sql);
        $this->assertStringContainsString('order by "is_main" desc, "name" asc, "id" asc', $sql);
        $this->assertStringNotContainsString(' join ', $sql);
        $this->assertStringNotContainsString('count(', $sql);
        $this->assertSame([$laboratory->id, 'active'], $selects[0]->bindings);
    }

    public function test_saas_pipeline_precedes_catalog_validation(): void
    {
        $this->getJson('/api/v1/branches/active?foo=bar')->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user, 'web')->getJson('/api/v1/branches/active?foo=bar')
            ->assertBadRequest()->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', 'invalid')
            ->getJson('/api/v1/branches/active?foo=bar')
            ->assertBadRequest()->assertJsonPath('code', 'INVALID_LABORATORY_CONTEXT');
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', '999999999')
            ->getJson('/api/v1/branches/active?foo=bar')
            ->assertNotFound()->assertJsonPath('code', 'LABORATORY_NOT_FOUND');

        $withoutMembership = Laboratory::factory()->create();
        $this->createCurrentSubscription($withoutMembership);
        $this->activeRequest($user, $withoutMembership, ['foo' => 'bar'])
            ->assertForbidden()->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $withoutSubscription = Laboratory::factory()->create();
        $user->laboratories()->attach($withoutSubscription, ['is_active' => true]);
        $this->activeRequest($user, $withoutSubscription, ['foo' => 'bar'])
            ->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_catalog_id_creates_order_while_inactive_and_cross_tenant_ids_remain_rejected(): void
    {
        $user = User::factory()->create();
        [, $laboratoryA] = $this->activeTenant($user);
        [, $laboratoryB] = $this->activeTenant($user);
        $active = $this->branch($laboratoryA, ['code' => 'ACTIVE']);
        $inactive = $this->branch($laboratoryA, ['code' => 'INACTIVE', 'status' => 'inactive']);
        $foreign = $this->branch($laboratoryB, ['code' => 'FOREIGN']);
        $patient = Patient::factory()->for($laboratoryA)->create();
        $priceList = PriceList::factory()->for($laboratoryA)->create();

        $catalogBranchId = $this->activeRequest($user, $laboratoryA)
            ->assertOk()
            ->assertJsonMissing(['id' => $inactive->id])
            ->assertJsonMissing(['id' => $foreign->id])
            ->json('data.0.id');
        $this->assertSame($active->id, $catalogBranchId);

        $payload = [
            'branch_id' => $catalogBranchId,
            'patient_id' => $patient->id,
            'doctor_id' => null,
            'commercial_client_id' => null,
            'price_list_id' => $priceList->id,
            'ordered_at' => '2026-10-05 08:30:00',
            'notes' => null,
        ];

        $this->orderRequest($user, $laboratoryA, $payload)
            ->assertCreated()
            ->assertJsonPath('data.branch.id', $active->id);
        $this->orderRequest($user, $laboratoryA, array_replace($payload, ['branch_id' => $inactive->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_id']);
        $this->orderRequest($user, $laboratoryA, array_replace($payload, ['branch_id' => $foreign->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_id']);
    }

    public function test_route_is_the_only_branch_endpoint_and_uses_saas_middleware(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->getActionName(), BranchController::class.'@'))
            ->values();

        $this->assertCount(1, $routes);
        $this->assertSame('api/v1/branches/active', $routes->first()->uri());
        $this->assertSame(['GET', 'HEAD'], $routes->first()->methods());
        $this->assertContains('saas', $routes->first()->gatherMiddleware());
    }

    /** @return array{User, Laboratory} */
    private function activeTenant(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $this->createCurrentSubscription($laboratory);

        return [$user, $laboratory];
    }

    private function createCurrentSubscription(Laboratory $laboratory): Subscription
    {
        return Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function branch(Laboratory $laboratory, array $attributes = []): Branch
    {
        return Branch::factory()->for($laboratory)->create($attributes);
    }

    /** @param array<string, string> $query */
    private function activeRequest(User $user, Laboratory $laboratory, array $query = []): TestResponse
    {
        $uri = '/api/v1/branches/active';
        if ($query !== []) {
            $uri .= '?'.http_build_query($query);
        }

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson($uri);
    }

    /** @param array<string, mixed> $payload */
    private function orderRequest(User $user, Laboratory $laboratory, array $payload): TestResponse
    {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson('/api/v1/laboratory-orders', $payload);
    }
}

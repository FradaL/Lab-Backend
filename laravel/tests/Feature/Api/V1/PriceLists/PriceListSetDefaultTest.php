<?php

namespace Tests\Feature\Api\V1\PriceLists;

use App\Http\Controllers\Api\V1\PriceListController;
use App\Models\Laboratory;
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
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class PriceListSetDefaultTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'));
    }

    public function test_zero_default_sets_only_active_target_and_returns_exact_resource(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $a = PriceList::factory()->for($laboratory)->create(['name' => 'A']);
        $target = PriceList::factory()->for($laboratory)->create([
            'name' => 'B',
            'description' => 'Target metadata',
            'currency' => 'USD',
        ]);
        $c = PriceList::factory()->for($laboratory)->create(['name' => 'C']);
        $aBefore = $this->priceListRow($a);
        $cBefore = $this->priceListRow($c);
        $this->travel(5)->minutes();

        $this->defaultRequest($user, $laboratory, $target->id)
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $target->id,
                'name' => 'B',
                'description' => 'Target metadata',
                'currency' => 'USD',
                'is_default' => true,
                'status' => PriceList::STATUS_ACTIVE,
                'created_at' => '2026-10-01T12:00:00.000000Z',
                'updated_at' => '2026-10-01T12:05:00.000000Z',
            ]])
            ->assertJsonMissingPath('data.laboratory_id')
            ->assertJsonMissingPath('data.laboratory')
            ->assertJsonMissingPath('data.exams')
            ->assertJsonMissingPath('data.exam_prices');

        $this->assertEquals($aBefore, $this->priceListRow($a));
        $this->assertEquals($cBefore, $this->priceListRow($c));
        $this->assertSame([$target->id], PriceList::forLaboratory($laboratory)->where('is_default', true)->pluck('id')->all());
    }

    public function test_replacement_updates_previous_then_target_and_preserves_all_metadata(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $previous = PriceList::factory()->for($laboratory)->asDefault()->create([
            'name' => 'Previous',
            'description' => 'Previous metadata',
            'currency' => 'GTQ',
        ]);
        $target = PriceList::factory()->for($laboratory)->create([
            'name' => 'Target',
            'description' => 'Target metadata',
            'currency' => 'EUR',
        ]);
        $other = PriceList::factory()->for($laboratory)->inactive()->create(['name' => 'Other']);
        $previousBefore = $this->priceListRow($previous);
        $targetBefore = $this->priceListRow($target);
        $otherBefore = $this->priceListRow($other);
        $updates = [];
        DB::listen(function (QueryExecuted $query) use (&$updates): void {
            if (str_contains($query->sql, 'price_lists') && str_starts_with(strtolower($query->sql), 'update ')) {
                $updates[] = $query->bindings;
            }
        });
        $this->travel(5)->minutes();

        $this->defaultRequest($user, $laboratory, $target->id)
            ->assertOk()->assertJsonPath('data.is_default', true);

        $previousAfter = $this->priceListRow($previous);
        $targetAfter = $this->priceListRow($target);
        $this->assertFalse((bool) $previousAfter->is_default);
        $this->assertTrue((bool) $targetAfter->is_default);
        $this->assertSame('2026-10-01 12:05:00', $previousAfter->updated_at);
        $this->assertSame('2026-10-01 12:05:00', $targetAfter->updated_at);
        foreach (['laboratory_id', 'name', 'description', 'currency', 'status', 'created_at'] as $field) {
            $this->assertEquals($previousBefore->{$field}, $previousAfter->{$field});
            $this->assertEquals($targetBefore->{$field}, $targetAfter->{$field});
        }
        $this->assertEquals($otherBefore, $this->priceListRow($other));
        $this->assertCount(2, $updates);
        $this->assertFalse((bool) $updates[0][0]);
        $this->assertTrue((bool) $updates[1][0]);
    }

    public function test_legacy_inactive_default_is_replaced_without_status_changes(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $previous = PriceList::factory()->for($laboratory)->inactive()->asDefault()->create();
        $target = PriceList::factory()->for($laboratory)->create();

        $this->defaultRequest($user, $laboratory, $target->id)->assertOk();

        $this->assertDatabaseHas('price_lists', [
            'id' => $previous->id,
            'status' => PriceList::STATUS_INACTIVE,
            'is_default' => false,
        ]);
        $this->assertDatabaseHas('price_lists', [
            'id' => $target->id,
            'status' => PriceList::STATUS_ACTIVE,
            'is_default' => true,
        ]);
    }

    public function test_already_default_is_idempotent_under_laboratory_lock(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $target = PriceList::factory()->for($laboratory)->asDefault()->create();
        $before = $this->priceListRow($target);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'price_lists') || str_contains($query->sql, 'laboratories')) {
                $queries[] = strtolower($query->sql);
            }
        });
        $this->travel(5)->minutes();

        $this->defaultRequest($user, $laboratory, $target->id)
            ->assertOk()->assertJsonPath('data.is_default', true);

        $this->assertCount(0, array_filter($queries, fn (string $sql): bool => str_starts_with($sql, 'update ')));
        $this->assertCount(2, array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "price_lists"')));
        $laboratoryQueries = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "laboratories"'));
        $this->assertNotEmpty($laboratoryQueries);
        if (DB::getDriverName() === 'pgsql') {
            $this->assertTrue(collect($laboratoryQueries)->contains(fn (string $sql): bool => str_contains($sql, 'for update')));
        }
        $this->assertEquals($before, $this->priceListRow($target));
    }

    #[DataProvider('inactiveTargetProvider')]
    public function test_inactive_target_is_rejected_atomically(bool $isDefault): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $target = PriceList::factory()->for($laboratory)->create([
            'status' => PriceList::STATUS_INACTIVE,
            'is_default' => $isDefault,
        ]);
        $before = $this->priceListRow($target);
        $updates = 0;
        DB::listen(function (QueryExecuted $query) use (&$updates): void {
            if (str_contains($query->sql, 'price_lists') && str_starts_with(strtolower($query->sql), 'update ')) {
                $updates++;
            }
        });

        $this->defaultRequest($user, $laboratory, $target->id)
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'The inactive price list cannot be set as default.');

        $this->assertEquals($before, $this->priceListRow($target));
        $this->assertSame(0, $updates);
    }

    public static function inactiveTargetProvider(): array
    {
        return [
            'non-default' => [false],
            'legacy default' => [true],
        ];
    }

    public function test_no_body_and_empty_object_are_both_valid(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $first = PriceList::factory()->for($laboratory)->create();
        $second = PriceList::factory()->for($laboratory)->create();

        $this->rawDefaultRequest($user, $laboratory, $first->id, '')->assertOk();
        $this->rawDefaultRequest($user, $laboratory, $second->id, '{}')->assertOk();

        $this->assertSame([$second->id], PriceList::forLaboratory($laboratory)->where('is_default', true)->pluck('id')->all());
    }

    #[DataProvider('invalidBodyProvider')]
    public function test_any_body_property_is_rejected_without_writes(string $field, mixed $value): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $target = PriceList::factory()->for($laboratory)->create();
        $before = $this->priceListRow($target);

        $this->defaultRequest($user, $laboratory, $target->id, [$field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors([$field]);

        $this->assertEquals($before, $this->priceListRow($target));
    }

    public static function invalidBodyProvider(): array
    {
        return [
            'set default true' => ['is_default', true],
            'unset default false' => ['is_default', false],
            'status' => ['status', 'active'],
            'name' => ['name', 'X'],
            'currency' => ['currency', 'USD'],
            'description' => ['description', 'X'],
            'laboratory' => ['laboratory_id', 1],
            'id' => ['id', 1],
            'force' => ['force', true],
            'arbitrary' => ['future_field', 'value'],
        ];
    }

    #[DataProvider('invalidQueryProvider')]
    public function test_any_query_parameter_is_rejected_without_writes(string $query, string $field): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $target = PriceList::factory()->for($laboratory)->create();
        $before = $this->priceListRow($target);

        $this->defaultRequest($user, $laboratory, $target->id, [], $query)
            ->assertUnprocessable()->assertJsonValidationErrors([$field]);

        $this->assertEquals($before, $this->priceListRow($target));
    }

    public static function invalidQueryProvider(): array
    {
        return [
            'force' => ['force=true', 'force'],
            'status' => ['status=active', 'status'],
            'default' => ['is_default=true', 'is_default'],
            'arbitrary' => ['foo=bar', 'foo'],
        ];
    }

    public function test_invalid_contract_precedes_inactive_business_rule(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $target = PriceList::factory()->for($laboratory)->inactive()->create();

        $response = $this->defaultRequest($user, $laboratory, $target->id, ['foo' => 'bar'])
            ->assertUnprocessable()->assertJsonValidationErrors(['foo']);

        $this->assertArrayNotHasKey('status', $response->json('errors'));
        $this->assertFalse($target->fresh()->is_default);
    }

    public function test_lookup_precedes_invalid_body_and_query_with_404_parity(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $foreign = PriceList::factory()->for($labB)->create();

        foreach ([
            [['foo' => 'bar'], ''],
            [[], 'force=true'],
        ] as [$payload, $query]) {
            $cross = $this->defaultRequest($user, $labA, $foreign->id, $payload, $query)
                ->assertNotFound()->assertExactJson(['message' => 'Resource not found.']);
            $missing = $this->defaultRequest($user, $labA, 999999999, $payload, $query)
                ->assertNotFound()->assertExactJson(['message' => 'Resource not found.']);
            $this->assertSame($cross->getContent(), $missing->getContent());
        }

        $this->assertFalse($foreign->fresh()->is_default);
    }

    public function test_replacement_rolls_back_when_target_update_fails(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $previous = PriceList::factory()->for($laboratory)->asDefault()->create();
        $target = PriceList::factory()->for($laboratory)->create();
        $previousBefore = $this->priceListRow($previous);
        $targetBefore = $this->priceListRow($target);

        PriceList::updating(function (PriceList $updating) use ($target): void {
            if ($updating->is($target)) {
                throw new RuntimeException('Controlled target failure.');
            }
        });
        $this->withoutExceptionHandling();

        try {
            $this->defaultRequest($user, $laboratory, $target->id);
            $this->fail('The controlled failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Controlled target failure.', $exception->getMessage());
        } finally {
            PriceList::flushEventListeners();
        }

        $this->assertEquals($previousBefore, $this->priceListRow($previous));
        $this->assertEquals($targetBefore, $this->priceListRow($target));
    }

    public function test_target_is_revalidated_inside_transaction_against_stale_state(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $target = PriceList::factory()->for($laboratory)->create(['status' => PriceList::STATUS_ACTIVE]);
        $changed = false;

        DB::listen(function (QueryExecuted $query) use (&$changed, $target): void {
            if (
                ! $changed
                && str_contains(strtolower($query->sql), 'from "laboratories"')
                && str_contains(strtolower($query->sql), 'where "laboratories"."id"')
            ) {
                $changed = true;
                DB::table('price_lists')->where('id', $target->id)->update([
                    'status' => PriceList::STATUS_INACTIVE,
                ]);
            }
        });

        $this->defaultRequest($user, $laboratory, $target->id)
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'The inactive price list cannot be set as default.');

        $this->assertTrue($changed);
        $this->assertDatabaseHas('price_lists', [
            'id' => $target->id,
            'status' => PriceList::STATUS_INACTIVE,
            'is_default' => false,
        ]);
    }

    public function test_tenant_isolation_and_cross_tenant_defaults_are_symmetric(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $a1 = PriceList::factory()->for($labA)->asDefault()->create(['name' => 'A1']);
        $a2 = PriceList::factory()->for($labA)->create(['name' => 'A2']);
        $b1 = PriceList::factory()->for($labB)->asDefault()->create(['name' => 'B1']);
        $b2 = PriceList::factory()->for($labB)->create(['name' => 'B2']);

        $this->defaultRequest($user, $labA, $a2->id)->assertOk();
        $this->defaultRequest($user, $labB, $b2->id)->assertOk();
        $this->defaultRequest($user, $labA, $b1->id)->assertNotFound();
        $this->defaultRequest($user, $labB, $a1->id)->assertNotFound();

        $this->assertSame([$a2->id], PriceList::forLaboratory($labA)->where('is_default', true)->pluck('id')->all());
        $this->assertSame([$b2->id], PriceList::forLaboratory($labB)->where('is_default', true)->pluck('id')->all());
    }

    public function test_context_switching_has_no_residual_tenant_state(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $a1 = PriceList::factory()->for($labA)->create();
        $a2 = PriceList::factory()->for($labA)->create();
        $b1 = PriceList::factory()->for($labB)->create();
        $b2 = PriceList::factory()->for($labB)->create();

        $this->defaultRequest($user, $labA, $a1->id)->assertOk();
        $this->defaultRequest($user, $labB, $b1->id)->assertOk();
        $this->defaultRequest($user, $labA, $a2->id)->assertOk();
        $this->defaultRequest($user, $labB, $b2->id)->assertOk();

        $this->assertSame([$a2->id], PriceList::forLaboratory($labA)->where('is_default', true)->pluck('id')->all());
        $this->assertSame([$b2->id], PriceList::forLaboratory($labB)->where('is_default', true)->pluck('id')->all());
    }

    public function test_pipeline_errors_precede_lookup_and_invalid_body(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $target = PriceList::factory()->for($laboratory)->create();
        $invalid = ['foo' => 'bar'];

        $this->patchJson("/api/v1/price-lists/{$target->id}/default", $invalid)->assertUnauthorized();
        $this->actingAs($user, 'web')->patchJson("/api/v1/price-lists/{$target->id}/default", $invalid)
            ->assertBadRequest()->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', 'invalid')
            ->patchJson("/api/v1/price-lists/{$target->id}/default", $invalid)
            ->assertBadRequest()->assertJsonPath('code', 'INVALID_LABORATORY_CONTEXT');
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', '999999999')
            ->patchJson("/api/v1/price-lists/{$target->id}/default", $invalid)
            ->assertNotFound()->assertJsonPath('code', 'LABORATORY_NOT_FOUND');

        $this->createCurrentSubscription($laboratory);
        $this->defaultRequest($user, $laboratory, $target->id, $invalid)
            ->assertForbidden()->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        DB::table('subscriptions')->delete();
        $this->defaultRequest($user, $laboratory, $target->id, $invalid)
            ->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');

        $inactive = Laboratory::factory()->create(['is_active' => false]);
        $user->laboratories()->attach($inactive, ['is_active' => true]);
        $this->defaultRequest($user, $inactive, $target->id, $invalid)
            ->assertForbidden()->assertJsonPath('code', 'LABORATORY_INACTIVE');
        $this->assertFalse($target->fresh()->is_default);
    }

    public function test_debug_false_representative_responses_do_not_leak_internals(): void
    {
        config(['app.debug' => false]);
        $guest = $this->patchJson('/api/v1/price-lists/1/default', [])->assertUnauthorized();
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $first = PriceList::factory()->for($labA)->create();
        $second = PriceList::factory()->for($labA)->create();
        $inactive = PriceList::factory()->for($labA)->inactive()->create();
        $foreign = PriceList::factory()->for($labB)->create();
        $withoutSubscription = Laboratory::factory()->create();
        $user->laboratories()->attach($withoutSubscription, ['is_active' => true]);
        $missingContext = $this->actingAs($user, 'web')->patchJson('/api/v1/price-lists/1/default', [])->assertBadRequest();
        $subscriptionFailure = $this->defaultRequest($user, $withoutSubscription, 1, ['foo' => 'bar'])->assertForbidden();

        $responses = [
            $guest,
            $missingContext,
            $subscriptionFailure,
            $this->defaultRequest($user, $labA, $first->id)->assertOk(),
            $this->defaultRequest($user, $labA, $second->id)->assertOk(),
            $this->defaultRequest($user, $labA, $second->id)->assertOk(),
            $this->defaultRequest($user, $labA, $inactive->id)->assertUnprocessable(),
            $this->defaultRequest($user, $labA, $first->id, ['foo' => 'bar'])->assertUnprocessable(),
            $this->defaultRequest($user, $labA, $first->id, [], 'force=true')->assertUnprocessable(),
            $this->defaultRequest($user, $labA, 999999999)->assertNotFound(),
            $this->defaultRequest($user, $labA, $foreign->id)->assertNotFound(),
        ];

        foreach ($responses as $response) {
            foreach (['SQLSTATE', 'bindings', '/var/www', 'App\\Models', 'Illuminate\\', 'stack trace', 'constraint'] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
            }
        }
    }

    public function test_runtime_controller_and_openapi_contract_are_exact(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/price-lists') && ! str_starts_with($route->uri(), 'api/v1/price-lists/{priceList}/exams') && ! str_starts_with($route->uri(), 'api/v1/price-lists/{priceList}/available-exams'))
            ->values();
        $defaultRoute = $routes->first(fn ($route): bool => str_ends_with($route->getActionName(), '@setDefault'));

        $this->assertCount(7, $routes);
        $this->assertSame([
            ['GET', 'HEAD'], ['POST'], ['GET', 'HEAD'], ['GET', 'HEAD'], ['PATCH'], ['PATCH'], ['PATCH'],
        ], $routes->map(fn ($route): array => $route->methods())->all());
        $this->assertSame('api/v1/price-lists/{priceList}/default', $defaultRoute->uri());
        $this->assertSame('[0-9]+', $defaultRoute->wheres['priceList']);
        $this->assertContains('saas', $defaultRoute->middleware());

        $methods = collect((new ReflectionClass(PriceListController::class))->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === PriceListController::class)
            ->pluck('name')->sort()->values()->all();
        $this->assertSame(['active', 'index', 'setDefault', 'show', 'store', 'update', 'updateStatus'], $methods);

        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $operation = $document['paths']['/api/v1/price-lists/{priceList}/default']['patch'];
        $pathParameter = collect($operation['parameters'])->firstWhere('in', 'path');

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertArrayNotHasKey('requestBody', $operation);
        $this->assertCount(0, collect($operation['parameters'])->where('in', 'query'));
        $this->assertSame('priceList', $pathParameter['name']);
        $this->assertTrue($pathParameter['required']);
        $this->assertSame('integer', $pathParameter['schema']['type']);
        $this->assertSame('int64', $pathParameter['schema']['format']);
        $this->assertSame(1, $pathParameter['schema']['minimum']);
        $this->assertSame('#/components/schemas/PriceListResponse', $operation['responses']['200']['content']['application/json']['schema']['$ref']);
        $this->assertSame([200, 400, 401, 403, 404, 422], array_keys($operation['responses']));

        $priceListOperations = collect($document['paths'])
            ->filter(fn (array $path, string $name): bool => str_starts_with($name, '/api/v1/price-lists'))
            ->sum(fn (array $path): int => count(array_intersect_key($path, array_flip(['get', 'post', 'patch', 'delete']))));
        $examOperations = collect($document['paths'])
            ->filter(fn (array $path, string $name): bool => str_starts_with($name, '/api/v1/laboratory-exams'))
            ->sum(fn (array $path): int => count(array_intersect_key($path, array_flip(['get', 'post', 'patch', 'delete']))));
        $this->assertSame(10, $priceListOperations);
        $this->assertSame(6, $examOperations);
        $this->assertArrayHasKey('/api/v1/price-lists/active', $document['paths']);
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

    private function defaultRequest(
        User $user,
        Laboratory $laboratory,
        int|string $priceList,
        array $payload = [],
        string $query = '',
    ): TestResponse {
        $suffix = $query === '' ? '' : '?'.$query;

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson("/api/v1/price-lists/{$priceList}/default{$suffix}", $payload);
    }

    private function rawDefaultRequest(
        User $user,
        Laboratory $laboratory,
        int $priceList,
        string $content,
    ): TestResponse {
        $this->actingAs($user, 'web');

        return $this->call(
            'PATCH',
            "/api/v1/price-lists/{$priceList}/default",
            server: [
                'HTTP_X_LABORATORY_ID' => (string) $laboratory->id,
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
            ],
            content: $content,
        );
    }

    private function priceListRow(PriceList $priceList): object
    {
        return DB::table('price_lists')->where('id', $priceList->id)->firstOrFail();
    }
}

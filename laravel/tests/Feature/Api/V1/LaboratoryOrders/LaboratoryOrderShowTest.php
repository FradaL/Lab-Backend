<?php

namespace Tests\Feature\Api\V1\LaboratoryOrders;

use App\Http\Controllers\Api\V1\LaboratoryOrderController;
use App\Models\Branch;
use App\Models\CommercialClient;
use App\Models\CommercialClientPriceList;
use App\Models\Doctor;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\Patient;
use App\Models\PriceList;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class LaboratoryOrderShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_commercial_order_returns_exact_resource_contract(): void
    {
        [$user, $laboratory, $order] = $this->orderContext();

        $response = $this->request($user, $laboratory, $order)->assertOk();

        $this->assertSame([
            'id', 'code', 'ordered_at', 'status', 'notes', 'patient', 'doctor',
            'commercial_client', 'price_list', 'branch', 'subtotal', 'discount_type',
            'discount_value', 'discount', 'taxes', 'total', 'currency', 'created_by',
            'created_at', 'updated_at',
        ], array_keys($response->json('data')));
        $this->assertSame(['id', 'first_names', 'last_names'], array_keys($response->json('data.patient')));
        $this->assertSame(['id', 'first_names', 'last_names'], array_keys($response->json('data.doctor')));
        $this->assertSame(['id', 'name', 'type'], array_keys($response->json('data.commercial_client')));
        $this->assertSame(['id', 'name', 'currency'], array_keys($response->json('data.price_list')));
        $this->assertSame(['id', 'name'], array_keys($response->json('data.branch')));
        $this->assertSame(['id', 'name'], array_keys($response->json('data.created_by')));
        $response
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.code', $order->code)
            ->assertJsonPath('data.ordered_at', $order->ordered_at->format('Y-m-d H:i:s'))
            ->assertJsonPath('data.status', $order->status)
            ->assertJsonPath('data.notes', $order->notes)
            ->assertJsonPath('data.patient.id', $order->patient_id)
            ->assertJsonPath('data.doctor.id', $order->doctor_id)
            ->assertJsonPath('data.commercial_client.id', $order->commercial_client_id)
            ->assertJsonPath('data.price_list.id', $order->price_list_id)
            ->assertJsonPath('data.branch.id', $order->branch_id)
            ->assertJsonPath('data.subtotal', $order->subtotal)
            ->assertJsonPath('data.discount_type', $order->discount_type)
            ->assertJsonPath('data.discount_value', $order->discount_value)
            ->assertJsonPath('data.discount', $order->discount)
            ->assertJsonPath('data.taxes', $order->taxes)
            ->assertJsonPath('data.total', $order->total)
            ->assertJsonPath('data.currency', $order->currency)
            ->assertJsonPath('data.created_by.id', $order->created_by)
            ->assertJsonMissingPath('data.laboratory_id')
            ->assertJsonMissingPath('data.branch_id')
            ->assertJsonMissingPath('data.patient_id')
            ->assertJsonMissingPath('data.doctor_id')
            ->assertJsonMissingPath('data.commercial_client_id')
            ->assertJsonMissingPath('data.price_list_id')
            ->assertJsonMissingPath('data.exams');
    }

    public function test_particular_order_without_doctor_preserves_both_null_relations(): void
    {
        [$user, $laboratory, $order] = $this->orderContext(orderAttributes: [
            'doctor_id' => null,
            'commercial_client_id' => null,
        ]);

        $this->request($user, $laboratory, $order)
            ->assertOk()
            ->assertJsonPath('data.doctor', null)
            ->assertJsonPath('data.commercial_client', null);
    }

    public function test_cross_tenant_and_nonexistent_orders_return_identical_neutral_404(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        [, $laboratory] = $this->activeTenant($user);
        [, , $foreignOrder] = $this->orderContext(user: $user);

        $missing = $this->requestById($user, $laboratory, 999999)->assertNotFound();
        $foreign = $this->request($user, $laboratory, $foreignOrder)->assertNotFound();

        $this->assertSame($missing->getContent(), $foreign->getContent());
        $this->assertDatabaseCount('laboratory_orders', 1);
    }

    public function test_multi_lab_user_is_controlled_exclusively_by_explicit_header(): void
    {
        $user = User::factory()->create();
        [, $laboratoryA] = $this->activeTenant($user);
        [, $laboratoryB, $orderB] = $this->orderContext(user: $user);

        $this->request($user, $laboratoryA, $orderB)->assertNotFound();
        $this->request($user, $laboratoryB, $orderB)->assertOk()->assertJsonPath('data.id', $orderB->id);
    }

    public function test_non_numeric_route_identifier_never_reaches_show(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson('/api/v1/laboratory-orders/not-an-id')
            ->assertNotFound();

        $this->assertDatabaseCount('laboratory_orders', 0);
    }

    #[DataProvider('unsupportedQueryParameterProvider')]
    public function test_unsupported_query_parameters_are_rejected(string $query, string $field): void
    {
        [$user, $laboratory, $order] = $this->orderContext();

        $this->request($user, $laboratory, $order, $query)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    }

    /** @return array<string, array{string, string}> */
    public static function unsupportedQueryParameterProvider(): array
    {
        return [
            'unknown' => ['foo=bar', 'foo'],
            'include' => ['include=exams', 'include'],
            'laboratory ownership' => ['laboratory_id=123', 'laboratory_id'],
            'dynamic relation' => ['with=patient', 'with'],
        ];
    }

    public function test_created_by_is_the_historical_creator_not_authenticated_reader(): void
    {
        $creator = User::factory()->create(['name' => 'Creador Histórico']);
        [, $laboratory, $order] = $this->orderContext(user: $creator);
        $reader = User::factory()->create(['name' => 'Lector Actual']);
        $reader->laboratories()->attach($laboratory, ['is_active' => true]);
        $this->assignDirectLaboratoryPermission($reader, $laboratory, 'orders.view');

        $this->request($reader, $laboratory, $order)
            ->assertOk()
            ->assertJsonPath('data.created_by.id', $creator->id)
            ->assertJsonPath('data.created_by.name', 'Creador Histórico');
    }

    public function test_order_and_nested_price_list_currency_are_the_same_historical_snapshot(): void
    {
        [$user, $laboratory, $order] = $this->orderContext();
        $order->priceList()->update(['currency' => 'USD']);

        $this->request($user, $laboratory, $order)
            ->assertOk()
            ->assertJsonPath('data.currency', 'GTQ')
            ->assertJsonPath('data.price_list.currency', 'GTQ');
    }

    public function test_persisted_price_list_wins_over_current_commercial_assignment(): void
    {
        [$user, $laboratory, $order] = $this->orderContext();
        $otherPriceList = PriceList::factory()->for($laboratory)->create(['currency' => 'USD']);
        CommercialClientPriceList::factory()->for($laboratory)->create([
            'commercial_client_id' => $order->commercial_client_id,
            'price_list_id' => $otherPriceList->id,
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => null,
        ]);

        $this->request($user, $laboratory, $order)
            ->assertOk()
            ->assertJsonPath('data.price_list.id', $order->price_list_id)
            ->assertJsonPath('data.currency', $order->currency);
    }

    #[DataProvider('inactiveHistoricalReferenceProvider')]
    public function test_inactive_historical_references_remain_visible(string $relation, string $table): void
    {
        [$user, $laboratory, $order] = $this->orderContext();
        $reference = $order->{$relation}()->firstOrFail();
        DB::table($table)->where('id', $reference->id)->update(['status' => 'inactive']);

        $this->request($user, $laboratory, $order)
            ->assertOk()
            ->assertJsonPath("data.{$this->resourceRelationName($relation)}.id", $reference->id);
    }

    /** @return array<string, array{string, string}> */
    public static function inactiveHistoricalReferenceProvider(): array
    {
        return [
            'patient' => ['patient', 'patients'],
            'doctor' => ['doctor', 'doctors'],
            'commercial client' => ['commercialClient', 'commercial_clients'],
            'price list' => ['priceList', 'price_lists'],
            'branch' => ['branch', 'branches'],
        ];
    }

    #[DataProvider('persistedDiscountIntentProvider')]
    public function test_persisted_economic_values_are_returned_without_calculation(
        ?string $type,
        ?string $value,
        string $discount,
    ): void {
        [$user, $laboratory, $order] = $this->orderContext(orderAttributes: [
            'subtotal' => '150.00',
            'discount_type' => $type,
            'discount_value' => $value,
            'discount' => $discount,
            'taxes' => '5.00',
            'total' => '145.00',
        ]);

        $this->request($user, $laboratory, $order)
            ->assertOk()
            ->assertJsonPath('data.subtotal', '150.00')
            ->assertJsonPath('data.discount_type', $type)
            ->assertJsonPath('data.discount_value', $value)
            ->assertJsonPath('data.discount', $discount)
            ->assertJsonPath('data.taxes', '5.00')
            ->assertJsonPath('data.total', '145.00');
    }

    /** @return array<string, array{?string, ?string, string}> */
    public static function persistedDiscountIntentProvider(): array
    {
        return [
            'legacy amount with unknown intent' => [null, null, '10.00'],
            'percentage intent' => [LaboratoryOrder::DISCOUNT_TYPE_PERCENTAGE, '10.00', '10.00'],
            'amount intent' => [LaboratoryOrder::DISCOUNT_TYPE_AMOUNT, '25.00', '25.00'],
        ];
    }

    public function test_show_is_read_only_and_does_not_touch_any_timestamp(): void
    {
        [$user, $laboratory, $order] = $this->orderContext();
        $tables = [
            'laboratory_orders', 'patients', 'doctors', 'commercial_clients',
            'price_lists', 'branches', 'users', 'commercial_client_price_lists',
            'price_list_exams',
        ];
        $before = collect($tables)->mapWithKeys(fn (string $table): array => [
            $table => DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
        ]);

        $this->request($user, $laboratory, $order)->assertOk();

        foreach ($tables as $table) {
            $this->assertSame(
                $before[$table],
                DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
            );
        }
    }

    public function test_eager_loading_has_constant_explicit_read_queries_and_no_forbidden_domains(): void
    {
        [$user, $laboratory, $order] = $this->orderContext();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->request($user, $laboratory, $order)->assertOk();

        foreach (['laboratory_orders', 'patients', 'doctors', 'branches', 'users'] as $table) {
            $this->assertCount(1, collect($queries)->filter(
                fn (string $sql): bool => str_contains($sql, "from \"{$table}\""),
            ), "Expected exactly one read from {$table}.");
        }
        foreach (['commercial_clients', 'price_lists', 'commercial_client_price_lists', 'price_list_exams', 'laboratory_order_exams'] as $table) {
            $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, $table)));
        }
        $this->assertFalse(collect($queries)->contains(
            fn (string $sql): bool => preg_match('/\A(insert|update|delete)\b/', $sql) === 1,
        ));
    }

    public function test_auth_laboratory_context_and_subscription_pipeline_are_preserved(): void
    {
        $this->getJson('/api/v1/laboratory-orders/1')->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->getJson('/api/v1/laboratory-orders/1')
            ->assertBadRequest()->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', 'invalid')
            ->getJson('/api/v1/laboratory-orders/1')
            ->assertBadRequest()->assertJsonPath('code', 'INVALID_LABORATORY_CONTEXT');

        $laboratory = Laboratory::factory()->create();
        $this->requestById($user, $laboratory, 1)->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $this->requestById($user, $laboratory, 1)->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_route_controller_and_openapi_expose_the_laboratory_order_contract(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/laboratory-orders'))
            ->values();

        $this->assertCount(9, $routes);
        $this->assertSame([['GET', 'HEAD'], ['POST'], ['GET', 'HEAD'], ['POST'], ['DELETE'], ['PUT'], ['DELETE'], ['PATCH'], ['GET', 'HEAD']], $routes->map(fn ($route): array => $route->methods())->all());
        foreach ($routes as $route) {
            $this->assertContains('saas', $route->middleware());
        }
        $showRoute = $routes->first(fn ($route): bool => str_contains($route->uri(), '{laboratoryOrder}'));
        $this->assertSame('[0-9]+', $showRoute->wheres['laboratoryOrder']);
        $this->assertSame(['addExam', 'index', 'listExams', 'removeDiscount', 'removeExam', 'show', 'store', 'updateDiscount', 'updateStatus'], collect((new ReflectionClass(LaboratoryOrderController::class))
            ->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === LaboratoryOrderController::class)
            ->pluck('name')->sort()->values()->all());

        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $path = $document['paths']['/api/v1/laboratory-orders/{laboratoryOrder}'];
        $operation = $path['get'];
        $operationCount = collect($document['paths'])->sum(fn (array $item): int => count(array_intersect_key(
            $item,
            array_flip(['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace']),
        )));

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertSame(68, $operationCount);
        $this->assertCount(76, Route::getRoutes()->getRoutes());
        $this->assertSame(['get'], array_keys($path));
        $parameter = collect($operation['parameters'])->firstWhere('name', 'laboratoryOrder');
        $this->assertSame('path', $parameter['in']);
        $this->assertTrue($parameter['required']);
        $this->assertSame('integer', $parameter['schema']['type']);
        $this->assertSame('#/components/schemas/LaboratoryOrderResponse', $operation['responses']['200']['content']['application/json']['schema']['$ref']);
        $this->assertSame([200, 400, 401, 403, 404, 422], array_keys($operation['responses']));
    }

    /**
     * @param  array<string, mixed>  $orderAttributes
     * @return array{User, Laboratory, LaboratoryOrder}
     */
    private function orderContext(
        ?Doctor $doctor = null,
        ?CommercialClient $commercialClient = null,
        ?User $user = null,
        array $orderAttributes = [],
    ): array {
        [$user, $laboratory] = $this->activeTenant($user);
        $branch = Branch::factory()->for($laboratory)->create();
        $patient = Patient::factory()->for($laboratory)->create();
        $doctor ??= Doctor::factory()->for($laboratory)->create();
        $commercialClient ??= CommercialClient::factory()->for($laboratory)->create();
        $priceList = PriceList::factory()->for($laboratory)->create(['currency' => 'GTQ']);
        $order = LaboratoryOrder::factory()->for($laboratory)->create(array_replace([
            'branch_id' => $branch->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'commercial_client_id' => $commercialClient->id,
            'price_list_id' => $priceList->id,
            'currency' => 'GTQ',
            'created_by' => $user->id,
        ], $orderAttributes));

        return [$user, $laboratory, $order];
    }

    /** @return array{User, Laboratory} */
    private function activeTenant(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ]);

        $this->assignAllOrderPermissions($user, $laboratory);

        return [$user, $laboratory];
    }

    private function resourceRelationName(string $relation): string
    {
        return match ($relation) {
            'commercialClient' => 'commercial_client',
            'priceList' => 'price_list',
            default => $relation,
        };
    }

    private function request(
        User $user,
        Laboratory $laboratory,
        LaboratoryOrder $order,
        string $query = '',
    ): TestResponse {
        $suffix = $query === '' ? '' : "?{$query}";

        return $this->requestById($user, $laboratory, $order->id, $suffix);
    }

    private function requestById(
        User $user,
        Laboratory $laboratory,
        int $order,
        string $suffix = '',
    ): TestResponse {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson("/api/v1/laboratory-orders/{$order}{$suffix}");
    }
}

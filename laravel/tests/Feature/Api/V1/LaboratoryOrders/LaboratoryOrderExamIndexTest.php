<?php

namespace Tests\Feature\Api\V1\LaboratoryOrders;

use App\Http\Controllers\Api\V1\LaboratoryOrderController;
use App\Models\Laboratory;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Models\PriceList;
use App\Models\PriceListExam;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class LaboratoryOrderExamIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_order_returns_exact_unpaginated_collection(): void
    {
        [$user, $laboratory, $order] = $this->activeContext();

        $response = $this->request($user, $laboratory, $order)->assertOk();

        $this->assertSame(['data' => []], $response->json());
        $response
            ->assertJsonMissingPath('links')
            ->assertJsonMissingPath('meta')
            ->assertJsonMissingPath('total');
    }

    #[DataProvider('statusProvider')]
    public function test_every_order_status_is_readable(string $status): void
    {
        [$user, $laboratory, $order, $exam, $priceList] = $this->activeContext(['status' => $status]);
        $line = $this->line($laboratory, $order, $exam, $priceList);

        $this->request($user, $laboratory, $order)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $line->id);

        $this->assertSame($status, $order->fresh()->status);
    }

    /** @return array<string, array{string}> */
    public static function statusProvider(): array
    {
        return [
            'pending' => [LaboratoryOrder::STATUS_PENDING],
            'in process' => [LaboratoryOrder::STATUS_IN_PROCESS],
            'completed' => [LaboratoryOrder::STATUS_COMPLETED],
            'cancelled' => [LaboratoryOrder::STATUS_CANCELLED],
        ];
    }

    public function test_repeated_lines_are_ordered_by_id_and_serialize_only_independent_snapshots(): void
    {
        [$user, $laboratory, $order, $glucose, $priceList, $price] = $this->activeContext();
        $hemogram = LaboratoryExam::factory()->for($laboratory)->create(['code' => 'HEM', 'name' => 'Hemograma']);
        $first = $this->line($laboratory, $order, $glucose, $priceList, [
            'unit_price' => '35.00',
            'exam_code' => 'GLU',
            'exam_name' => 'Glucosa',
            'price_list_name' => 'Particular',
        ]);
        $second = $this->line($laboratory, $order, $hemogram, $priceList, [
            'unit_price' => '75.00',
            'exam_code' => 'HEM',
            'exam_name' => 'Hemograma',
            'price_list_name' => 'Particular',
        ]);
        $third = $this->line($laboratory, $order, $glucose, $priceList, [
            'unit_price' => '40.00',
            'exam_code' => 'GLU-OLD',
            'exam_name' => 'Glucosa histórica',
            'price_list_name' => 'Particular anterior',
        ]);
        $glucose->update([
            'code' => 'GLU-S',
            'name' => 'Glucosa sérica',
            'status' => LaboratoryExam::STATUS_INACTIVE,
        ]);
        $priceList->update(['name' => 'Particular 2027', 'status' => PriceList::STATUS_INACTIVE]);
        $price->update(['price' => '99.00', 'status' => PriceListExam::STATUS_INACTIVE]);

        Model::preventLazyLoading();
        try {
            $response = $this->request($user, $laboratory, $order)->assertOk();
        } finally {
            Model::preventLazyLoading(false);
        }

        $this->assertSame([$first->id, $second->id, $third->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertSame(['35.00', '75.00', '40.00'], collect($response->json('data'))->pluck('unit_price')->all());
        $response
            ->assertJsonPath('data.0.exam.code', 'GLU')
            ->assertJsonPath('data.0.exam.name', 'Glucosa')
            ->assertJsonPath('data.0.price_list.name', 'Particular')
            ->assertJsonPath('data.2.exam.code', 'GLU-OLD')
            ->assertJsonPath('data.2.exam.name', 'Glucosa histórica')
            ->assertJsonPath('data.2.price_list.name', 'Particular anterior');

        foreach ($response->json('data') as $item) {
            $this->assertSame(['id', 'exam', 'price_list', 'unit_price', 'created_at', 'updated_at'], array_keys($item));
            $this->assertSame(['id', 'code', 'name'], array_keys($item['exam']));
            $this->assertSame(['id', 'name'], array_keys($item['price_list']));
            foreach (['laboratory_id', 'laboratory_order_id', 'quantity', 'subtotal', 'discount', 'currency', 'status', 'current_price', 'price_list_exam_id'] as $field) {
                $this->assertArrayNotHasKey($field, $item);
            }
        }
    }

    public function test_three_identical_exam_requests_remain_three_distinct_items(): void
    {
        [$user, $laboratory, $order, $exam, $priceList] = $this->activeContext();
        $lines = collect(range(1, 3))->map(fn (): LaboratoryOrderExam => $this->line($laboratory, $order, $exam, $priceList));

        $data = $this->request($user, $laboratory, $order)->assertOk()->json('data');

        $this->assertCount(3, $data);
        $this->assertSame($lines->pluck('id')->all(), collect($data)->pluck('id')->all());
        $this->assertSame([$exam->id], collect($data)->pluck('exam.id')->unique()->values()->all());
    }

    public function test_query_is_tenant_and_order_scoped_without_catalog_queries_writes_or_n_plus_one(): void
    {
        [$user, $laboratory, $order, $exam, $priceList] = $this->activeContext([
            'subtotal' => '100.00',
            'discount' => '5.00',
            'taxes' => '10.00',
            'total' => '105.00',
        ]);
        $otherOrder = LaboratoryOrder::factory()->for($laboratory)->create([
            'price_list_id' => $priceList->id,
            'currency' => $priceList->currency,
            'created_by' => $user->id,
        ]);
        $expected = collect(range(1, 20))->map(fn (): LaboratoryOrderExam => $this->line($laboratory, $order, $exam, $priceList));
        $otherLine = $this->line($laboratory, $otherOrder, $exam, $priceList);
        $originalOrder = $order->fresh()->getRawOriginal();
        $originalLines = LaboratoryOrderExam::query()->whereIn('id', $expected->pluck('id'))->get()
            ->mapWithKeys(fn (LaboratoryOrderExam $line): array => [$line->id => $line->getRawOriginal()]);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $response = $this->request($user, $laboratory, $order)->assertOk()->assertJsonCount(20, 'data');

        $this->assertSame($expected->pluck('id')->all(), collect($response->json('data'))->pluck('id')->all());
        $response->assertJsonMissing(['id' => $otherLine->id]);
        $lineQueries = collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'from "laboratory_order_exams"'));
        $this->assertCount(1, $lineQueries);
        $lineQuery = $lineQueries->sole();
        $this->assertStringContainsString('"laboratory_id"', $lineQuery);
        $this->assertStringContainsString('"laboratory_order_id"', $lineQuery);
        $this->assertStringContainsString('order by "id" asc', $lineQuery);
        $this->assertStringNotContainsString('distinct', $lineQuery);
        $this->assertStringNotContainsString('group by', $lineQuery);
        $this->assertStringNotContainsString('for update', $lineQuery);
        foreach (['laboratory_exams', 'price_lists', 'price_list_exams', 'commercial_client_price_lists'] as $table) {
            $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'from "'.$table.'"')));
        }
        $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => preg_match('/\A(insert|update|delete)\b/', $sql) === 1));
        $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'sum(')));
        $this->assertSame($originalOrder, $order->fresh()->getRawOriginal());
        foreach ($expected as $line) {
            $this->assertSame($originalLines[$line->id], $line->fresh()->getRawOriginal());
        }
    }

    public function test_cross_tenant_and_missing_orders_return_same_404_before_query_validation(): void
    {
        config(['app.debug' => false]);
        [$user, $laboratory] = $this->activeTenant();
        [, , $foreignOrder] = $this->activeContext(user: $user);

        $missing = $this->requestById($user, $laboratory, 999999)->assertNotFound();
        $foreign = $this->requestById($user, $laboratory, $foreignOrder->id)->assertNotFound();
        $foreignInvalid = $this->requestById($user, $laboratory, $foreignOrder->id, ['search' => 'glucosa'])->assertNotFound();

        $this->assertSame($missing->getContent(), $foreign->getContent());
        $this->assertSame($missing->getContent(), $foreignInvalid->getContent());
    }

    #[DataProvider('queryParameterProvider')]
    public function test_every_query_parameter_is_rejected(string $parameter): void
    {
        [$user, $laboratory, $order] = $this->activeContext();

        $this->request($user, $laboratory, $order, [$parameter => 'value'])
            ->assertUnprocessable()->assertJsonValidationErrors([$parameter]);
    }

    /** @return array<string, array{string}> */
    public static function queryParameterProvider(): array
    {
        return [
            'search' => ['search'],
            'page' => ['page'],
            'sort' => ['sort'],
            'status' => ['status'],
            'foo' => ['foo'],
        ];
    }

    public function test_auth_context_access_and_subscription_pipeline_precede_index(): void
    {
        $url = '/api/v1/laboratory-orders/1/exams';
        $this->getJson($url)->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user, 'web')->getJson($url)->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');

        $laboratory = Laboratory::factory()->create();
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson($url)->assertForbidden()->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson($url)->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_read_after_add_repeated_add_delete_and_delete_last_reflects_persisted_lines(): void
    {
        [$user, $laboratory, $order, $exam] = $this->activeContext();

        $first = $this->add($user, $laboratory, $order, $exam)->assertCreated()->json('data');
        $second = $this->add($user, $laboratory, $order, $exam)->assertCreated()->json('data');

        $this->request($user, $laboratory, $order)
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0', $first)
            ->assertJsonPath('data.1', $second);
        $this->deleteLine($user, $laboratory, $order, $first['id'])->assertNoContent();
        $this->request($user, $laboratory, $order)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second['id']);
        $this->deleteLine($user, $laboratory, $order, $second['id'])->assertNoContent();
        $this->request($user, $laboratory, $order)->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_completed_historical_order_keeps_snapshots_after_catalog_changes(): void
    {
        [$user, $laboratory, $order, $exam, $priceList, $price] = $this->activeContext([
            'status' => LaboratoryOrder::STATUS_COMPLETED,
        ]);
        $line = $this->line($laboratory, $order, $exam, $priceList, [
            'exam_code' => 'OLD',
            'exam_name' => 'Nombre histórico',
            'price_list_name' => 'Lista histórica',
            'unit_price' => '12.34',
        ]);
        $exam->update(['code' => 'NEW', 'name' => 'Nombre actual']);
        $priceList->update(['name' => 'Lista actual']);
        $price->update(['price' => '99.99']);

        $this->request($user, $laboratory, $order)
            ->assertOk()
            ->assertJsonPath('data.0.id', $line->id)
            ->assertJsonPath('data.0.exam.code', 'OLD')
            ->assertJsonPath('data.0.exam.name', 'Nombre histórico')
            ->assertJsonPath('data.0.price_list.name', 'Lista histórica')
            ->assertJsonPath('data.0.unit_price', '12.34');
    }

    public function test_non_numeric_order_identifier_is_not_matched(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson('/api/v1/laboratory-orders/not-an-id/exams')
            ->assertNotFound();
    }

    public function test_route_controller_and_openapi_expose_exact_index_contract(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(
            fn ($route): bool => $route->uri() === 'api/v1/laboratory-orders/{laboratoryOrder}/exams'
                && in_array('GET', $route->methods(), true),
        );

        $this->assertNotNull($route);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertContains('saas', $route->middleware());
        $this->assertSame('[0-9]+', $route->wheres['laboratoryOrder']);
        $this->assertSame([
            'addExam', 'listExams', 'removeExam', 'show', 'store', 'updateStatus',
        ], collect((new ReflectionClass(LaboratoryOrderController::class))
            ->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === LaboratoryOrderController::class)
            ->pluck('name')->sort()->values()->all());

        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $path = $document['paths']['/api/v1/laboratory-orders/{laboratoryOrder}/exams'];
        $operation = $path['get'];
        $operationCount = collect($document['paths'])->sum(fn (array $item): int => count(array_intersect_key(
            $item,
            array_flip(['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace']),
        )));

        $this->assertSame(['get', 'post'], array_keys($path));
        $this->assertSame(62, $operationCount);
        $this->assertArrayNotHasKey('requestBody', $operation);
        $this->assertSame('#/components/schemas/LaboratoryOrderExamCollectionResponse', $operation['responses']['200']['content']['application/json']['schema']['$ref']);
        $this->assertSame([200, 400, 401, 403, 404, 422], array_keys($operation['responses']));
        foreach (['sin paginación', 'id ascendente', 'repetidas', 'snapshots', 'cualquier estado'] as $fragment) {
            $this->assertStringContainsString($fragment, strtolower($operation['description']));
        }
    }

    /**
     * @param  array<string, mixed>  $orderAttributes
     * @return array{User, Laboratory, LaboratoryOrder, LaboratoryExam, PriceList, PriceListExam}
     */
    private function activeContext(array $orderAttributes = [], ?User $user = null): array
    {
        [$user, $laboratory] = $this->activeTenant($user);
        $priceList = PriceList::factory()->for($laboratory)->create([
            'name' => 'Particular',
            'currency' => 'GTQ',
            'status' => PriceList::STATUS_ACTIVE,
        ]);
        $order = LaboratoryOrder::factory()->for($laboratory)->create(array_replace([
            'price_list_id' => $priceList->id,
            'currency' => 'GTQ',
            'status' => LaboratoryOrder::STATUS_PENDING,
            'created_by' => $user->id,
        ], $orderAttributes));
        $exam = LaboratoryExam::factory()->for($laboratory)->create([
            'code' => 'GLU',
            'name' => 'Glucosa',
            'status' => LaboratoryExam::STATUS_ACTIVE,
        ]);
        $price = PriceListExam::factory()->for($laboratory)->create([
            'price_list_id' => $priceList->id,
            'laboratory_exam_id' => $exam->id,
            'price' => '35.00',
            'status' => PriceListExam::STATUS_ACTIVE,
        ]);

        return [$user, $laboratory, $order, $exam, $priceList, $price];
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

        return [$user, $laboratory];
    }

    /** @param array<string, mixed> $attributes */
    private function line(
        Laboratory $laboratory,
        LaboratoryOrder $order,
        LaboratoryExam $exam,
        PriceList $priceList,
        array $attributes = [],
    ): LaboratoryOrderExam {
        return LaboratoryOrderExam::factory()->for($laboratory)->create(array_replace([
            'laboratory_order_id' => $order->id,
            'laboratory_exam_id' => $exam->id,
            'price_list_id' => $priceList->id,
            'unit_price' => '35.00',
            'exam_code' => $exam->code,
            'exam_name' => $exam->name,
            'price_list_name' => $priceList->name,
        ], $attributes));
    }

    /** @param array<string, mixed> $query */
    private function request(User $user, Laboratory $laboratory, LaboratoryOrder $order, array $query = []): TestResponse
    {
        return $this->requestById($user, $laboratory, $order->id, $query);
    }

    /** @param array<string, mixed> $query */
    private function requestById(User $user, Laboratory $laboratory, int $orderId, array $query = []): TestResponse
    {
        $url = "/api/v1/laboratory-orders/{$orderId}/exams";

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson($query === [] ? $url : $url.'?'.http_build_query($query));
    }

    private function add(User $user, Laboratory $laboratory, LaboratoryOrder $order, LaboratoryExam $exam): TestResponse
    {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson("/api/v1/laboratory-orders/{$order->id}/exams", ['laboratory_exam_id' => $exam->id]);
    }

    private function deleteLine(User $user, Laboratory $laboratory, LaboratoryOrder $order, int $lineId): TestResponse
    {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->deleteJson("/api/v1/laboratory-orders/{$order->id}/exams/{$lineId}");
    }
}

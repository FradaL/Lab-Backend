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
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class LaboratoryOrderExamStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_order_accepts_exam_with_exact_server_owned_snapshots_and_resource(): void
    {
        [$user, $laboratory, $order, $exam, $priceList] = $this->activeContext();
        $originalOrder = $order->fresh()->getRawOriginal();

        $response = $this->request($user, $laboratory, $order, ['laboratory_exam_id' => $exam->id])
            ->assertCreated();

        $line = LaboratoryOrderExam::query()->sole();
        $this->assertSame($laboratory->id, $line->laboratory_id);
        $this->assertSame($order->id, $line->laboratory_order_id);
        $this->assertSame($exam->id, $line->laboratory_exam_id);
        $this->assertSame($priceList->id, $line->price_list_id);
        $this->assertSame('35.00', $line->unit_price);
        $this->assertSame('GLU', $line->exam_code);
        $this->assertSame('Glucosa', $line->exam_name);
        $this->assertSame('Precio particular', $line->price_list_name);
        $this->assertSame($originalOrder, $order->fresh()->getRawOriginal());

        $this->assertSame(
            ['id', 'exam', 'price_list', 'unit_price', 'created_at', 'updated_at'],
            array_keys($response->json('data')),
        );
        $this->assertSame(['id', 'code', 'name'], array_keys($response->json('data.exam')));
        $this->assertSame(['id', 'name'], array_keys($response->json('data.price_list')));
        $response
            ->assertJsonPath('data.exam.id', $exam->id)
            ->assertJsonPath('data.exam.code', 'GLU')
            ->assertJsonPath('data.exam.name', 'Glucosa')
            ->assertJsonPath('data.price_list.id', $priceList->id)
            ->assertJsonPath('data.price_list.name', 'Precio particular')
            ->assertJsonPath('data.unit_price', '35.00')
            ->assertJsonMissingPath('data.laboratory_id')
            ->assertJsonMissingPath('data.laboratory_order_id')
            ->assertJsonMissingPath('data.quantity')
            ->assertJsonMissingPath('data.subtotal')
            ->assertJsonMissingPath('data.currency')
            ->assertJsonMissingPath('data.status');
    }

    public function test_same_exam_three_times_creates_distinct_lines_with_independent_snapshots(): void
    {
        [$user, $laboratory, $order, $exam, $priceList, $price] = $this->activeContext();

        $first = $this->request($user, $laboratory, $order, ['laboratory_exam_id' => $exam->id])
            ->assertCreated()->json('data.id');
        $exam->update(['code' => 'GLU-S', 'name' => 'Glucosa sérica']);
        $priceList->update(['name' => 'Precio actualizado']);
        $price->update(['price' => '40.00']);
        $second = $this->request($user, $laboratory, $order, ['laboratory_exam_id' => $exam->id])
            ->assertCreated()->json('data.id');
        $third = $this->request($user, $laboratory, $order, ['laboratory_exam_id' => $exam->id])
            ->assertCreated()->json('data.id');

        $this->assertCount(3, array_unique([$first, $second, $third]));
        $this->assertDatabaseCount('laboratory_order_exams', 3);
        $lines = LaboratoryOrderExam::query()->orderBy('id')->get();
        $this->assertSame(['35.00', '40.00', '40.00'], $lines->pluck('unit_price')->all());
        $this->assertSame(['Glucosa', 'Glucosa sérica', 'Glucosa sérica'], $lines->pluck('exam_name')->all());
        $this->assertSame(['GLU', 'GLU-S', 'GLU-S'], $lines->pluck('exam_code')->all());
        $this->assertSame(['Precio particular', 'Precio actualizado', 'Precio actualizado'], $lines->pluck('price_list_name')->all());
        $this->assertSame([$order->id], $lines->pluck('laboratory_order_id')->unique()->values()->all());
        $this->assertSame([$exam->id], $lines->pluck('laboratory_exam_id')->unique()->values()->all());
    }

    #[DataProvider('nonPendingStatusProvider')]
    public function test_non_pending_order_is_rejected_without_writes(string $status): void
    {
        [$user, $laboratory, $order, $exam] = $this->activeContext(['status' => $status]);

        $this->request($user, $laboratory, $order, ['laboratory_exam_id' => $exam->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseCount('laboratory_order_exams', 0);
        $this->assertSame($status, $order->fresh()->status);
    }

    /** @return array<string, array{string}> */
    public static function nonPendingStatusProvider(): array
    {
        return [
            'in process' => [LaboratoryOrder::STATUS_IN_PROCESS],
            'completed' => [LaboratoryOrder::STATUS_COMPLETED],
            'cancelled' => [LaboratoryOrder::STATUS_CANCELLED],
        ];
    }

    public function test_inactive_cross_tenant_and_missing_exams_are_neutral_validation_errors(): void
    {
        [$user, $laboratory, $order, $exam] = $this->activeContext();
        $exam->update(['status' => LaboratoryExam::STATUS_INACTIVE]);
        $foreignExam = LaboratoryExam::factory()->create();

        foreach ([$exam->id, $foreignExam->id, 999999] as $examId) {
            $this->request($user, $laboratory, $order, ['laboratory_exam_id' => $examId])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['laboratory_exam_id']);
        }

        $this->assertDatabaseCount('laboratory_order_exams', 0);
    }

    public function test_inactive_price_list_and_currency_mismatch_are_rejected_without_fallback(): void
    {
        [$user, $laboratory, $order, $exam, $priceList] = $this->activeContext();
        PriceList::factory()->for($laboratory)->create(['is_default' => true, 'currency' => 'GTQ']);
        $priceList->update(['status' => PriceList::STATUS_INACTIVE]);

        $this->request($user, $laboratory, $order, ['laboratory_exam_id' => $exam->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['price_list']);

        $priceList->update(['status' => PriceList::STATUS_ACTIVE, 'currency' => 'USD']);
        $this->request($user, $laboratory, $order, ['laboratory_exam_id' => $exam->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['price_list']);

        $this->assertDatabaseCount('laboratory_order_exams', 0);
        $this->assertSame($priceList->id, $order->fresh()->price_list_id);
        $this->assertSame('GTQ', $order->currency);
    }

    public function test_missing_and_inactive_price_configuration_are_rejected(): void
    {
        [$user, $laboratory, $order, $exam, , $price] = $this->activeContext();
        $price->delete();

        $this->request($user, $laboratory, $order, ['laboratory_exam_id' => $exam->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['laboratory_exam_id']);

        $price = PriceListExam::factory()->for($laboratory)->create([
            'price_list_id' => $order->price_list_id,
            'laboratory_exam_id' => $exam->id,
            'status' => PriceListExam::STATUS_INACTIVE,
        ]);
        $this->request($user, $laboratory, $order, ['laboratory_exam_id' => $exam->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['laboratory_exam_id']);

        $this->assertDatabaseCount('laboratory_order_exams', 0);
        $this->assertSame(PriceListExam::STATUS_INACTIVE, $price->fresh()->status);
    }

    public function test_zero_and_latest_prices_are_authoritative_at_add_time(): void
    {
        [$user, $laboratory, $order, $exam, , $price] = $this->activeContext();
        $price->update(['price' => '80.00']);
        $this->request($user, $laboratory, $order, ['laboratory_exam_id' => $exam->id])
            ->assertCreated()->assertJsonPath('data.unit_price', '80.00');

        $price->update(['price' => '0.00']);
        $this->request($user, $laboratory, $order, ['laboratory_exam_id' => $exam->id])
            ->assertCreated()->assertJsonPath('data.unit_price', '0.00');

        $this->assertSame(['80.00', '0.00'], LaboratoryOrderExam::query()->orderBy('id')->pluck('unit_price')->all());
    }

    #[DataProvider('invalidIdentifierProvider')]
    public function test_exam_id_requires_a_real_positive_json_integer(mixed $value): void
    {
        [$user, $laboratory, $order] = $this->activeContext();

        $this->request($user, $laboratory, $order, ['laboratory_exam_id' => $value])
            ->assertUnprocessable()->assertJsonValidationErrors(['laboratory_exam_id']);
        $this->assertDatabaseCount('laboratory_order_exams', 0);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidIdentifierProvider(): array
    {
        return [
            'numeric string' => ['1'],
            'zero' => [0],
            'negative' => [-1],
            'decimal' => [1.5],
            'null' => [null],
            'boolean' => [true],
            'array' => [[1]],
        ];
    }

    public function test_missing_exam_id_is_rejected(): void
    {
        [$user, $laboratory, $order] = $this->activeContext();

        $this->request($user, $laboratory, $order, [])
            ->assertUnprocessable()->assertJsonValidationErrors(['laboratory_exam_id']);
        $this->assertDatabaseCount('laboratory_order_exams', 0);
    }

    #[DataProvider('unknownFieldProvider')]
    public function test_every_server_owned_or_unknown_field_is_rejected(string $field): void
    {
        [$user, $laboratory, $order, $exam] = $this->activeContext();

        $this->request($user, $laboratory, $order, [
            'laboratory_exam_id' => $exam->id,
            $field => 'injected',
        ])->assertUnprocessable()->assertJsonValidationErrors([$field]);
        $this->assertDatabaseCount('laboratory_order_exams', 0);
    }

    /** @return array<string, array{string}> */
    public static function unknownFieldProvider(): array
    {
        return collect([
            'laboratory_id', 'laboratory_order_id', 'price_list_id', 'unit_price',
            'exam_code', 'exam_name', 'price_list_name', 'currency', 'quantity',
            'subtotal', 'discount', 'status', 'result', 'foo',
        ])->mapWithKeys(fn (string $field): array => [$field => [$field]])->all();
    }

    public function test_cross_tenant_and_missing_orders_return_same_404_before_body_validation(): void
    {
        config(['app.debug' => false]);
        [$user, $laboratory] = $this->activeTenant();
        [, , $foreignOrder] = $this->activeContext(user: $user);

        $missing = $this->requestById($user, $laboratory, 999999, ['laboratory_exam_id' => 1])->assertNotFound();
        $foreign = $this->requestById($user, $laboratory, $foreignOrder->id, ['laboratory_exam_id' => 1])->assertNotFound();
        $foreignWithInvalidBody = $this->requestById($user, $laboratory, $foreignOrder->id, [
            'laboratory_exam_id' => 'invalid',
            'unit_price' => '1.00',
        ])->assertNotFound();

        $this->assertSame($missing->getContent(), $foreign->getContent());
        $this->assertSame($missing->getContent(), $foreignWithInvalidBody->getContent());
        $this->assertDatabaseCount('laboratory_order_exams', 0);
    }

    public function test_auth_context_access_and_subscription_pipeline_precede_add(): void
    {
        $url = '/api/v1/laboratory-orders/1/exams';
        $this->postJson($url, [])->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user, 'web')->postJson($url, [])->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');

        $laboratory = Laboratory::factory()->create();
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson($url, [])->assertForbidden()->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson($url, [])->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_failed_insert_rolls_back_and_does_not_mutate_order(): void
    {
        [$user, $laboratory, $order, $exam] = $this->activeContext();
        $originalOrder = $order->fresh()->getRawOriginal();
        Event::listen('eloquent.creating: '.LaboratoryOrderExam::class, fn (): never => throw new RuntimeException('forced insert failure'));
        $this->withoutExceptionHandling();

        try {
            $this->request($user, $laboratory, $order, ['laboratory_exam_id' => $exam->id]);
            $this->fail('The forced insert failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced insert failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('laboratory_order_exams', 0);
        $this->assertSame($originalOrder, $order->fresh()->getRawOriginal());
    }

    public function test_add_queries_only_authoritative_models_and_performs_no_economic_or_order_write(): void
    {
        [$user, $laboratory, $order, $exam] = $this->activeContext([
            'subtotal' => '100.00',
            'discount' => '5.00',
            'taxes' => '10.00',
            'total' => '105.00',
        ]);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->request($user, $laboratory, $order, ['laboratory_exam_id' => $exam->id])->assertCreated();

        foreach (['commercial_client_price_lists', 'branches', 'patients', 'doctors', 'commercial_clients'] as $table) {
            $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, $table)));
        }
        $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'sum(')));
        $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => str_starts_with($sql, 'update "laboratory_orders"')));
        $order->refresh();
        $this->assertSame('100.00', $order->subtotal);
        $this->assertSame('5.00', $order->discount);
        $this->assertSame('10.00', $order->taxes);
        $this->assertSame('105.00', $order->total);
        $this->assertSame(LaboratoryOrder::STATUS_PENDING, $order->status);
    }

    public function test_route_controller_and_openapi_expose_only_the_post_add_contract(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(
            fn ($route): bool => $route->uri() === 'api/v1/laboratory-orders/{laboratoryOrder}/exams'
                && in_array('POST', $route->methods(), true),
        );

        $this->assertNotNull($route);
        $this->assertSame(['POST'], $route->methods());
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
        $operation = $path['post'];
        $schema = $document['components']['schemas']['AddLaboratoryOrderExamInput'];
        $operationCount = collect($document['paths'])->sum(fn (array $item): int => count(array_intersect_key(
            $item,
            array_flip(['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace']),
        )));

        $this->assertSame(['get', 'post'], array_keys($path));
        $this->assertSame(62, $operationCount);
        $this->assertSame(['laboratory_exam_id'], $schema['required']);
        $this->assertSame(['laboratory_exam_id'], array_keys($schema['properties']));
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame('#/components/schemas/LaboratoryOrderExamResponse', $operation['responses']['201']['content']['application/json']['schema']['$ref']);
        $this->assertSame([201, 400, 401, 403, 404, 422], array_keys($operation['responses']));
        $this->assertStringContainsString('repetir', strtolower($operation['description']));
    }

    /**
     * @param  array<string, mixed>  $orderAttributes
     * @return array{User, Laboratory, LaboratoryOrder, LaboratoryExam, PriceList, PriceListExam}
     */
    private function activeContext(array $orderAttributes = [], ?User $user = null): array
    {
        [$user, $laboratory] = $this->activeTenant($user);
        $priceList = PriceList::factory()->for($laboratory)->create([
            'name' => 'Precio particular',
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

    /** @param array<string, mixed> $payload */
    private function request(User $user, Laboratory $laboratory, LaboratoryOrder $order, array $payload): TestResponse
    {
        return $this->requestById($user, $laboratory, $order->id, $payload);
    }

    /** @param array<string, mixed> $payload */
    private function requestById(User $user, Laboratory $laboratory, int $orderId, array $payload): TestResponse
    {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson("/api/v1/laboratory-orders/{$orderId}/exams", $payload);
    }
}

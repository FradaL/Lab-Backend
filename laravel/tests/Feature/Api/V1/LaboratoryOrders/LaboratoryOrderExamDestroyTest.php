<?php

namespace Tests\Feature\Api\V1\LaboratoryOrders;

use App\Actions\LaboratoryOrders\RemoveExamFromLaboratoryOrder;
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

final class LaboratoryOrderExamDestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_removing_one_repeated_exam_targets_only_its_order_exam_id(): void
    {
        [$user, $laboratory, $order, $glucose, $priceList] = $this->activeContext();
        $hemogram = LaboratoryExam::factory()->for($laboratory)->create();
        $firstGlucose = $this->line($laboratory, $order, $glucose, $priceList, [
            'unit_price' => '35.00',
            'exam_name' => 'Glucosa original',
        ]);
        $hemogramLine = $this->line($laboratory, $order, $hemogram, $priceList);
        $secondGlucose = $this->line($laboratory, $order, $glucose, $priceList, [
            'unit_price' => '40.00',
            'exam_name' => 'Glucosa sérica',
        ]);

        $this->request($user, $laboratory, $order, $secondGlucose)->assertNoContent();

        $this->assertDatabaseMissing('laboratory_order_exams', ['id' => $secondGlucose->id]);
        $this->assertDatabaseHas('laboratory_order_exams', [
            'id' => $firstGlucose->id,
            'laboratory_exam_id' => $glucose->id,
            'unit_price' => '35.00',
            'exam_name' => 'Glucosa original',
        ]);
        $this->assertDatabaseHas('laboratory_order_exams', ['id' => $hemogramLine->id]);
        $this->assertDatabaseCount('laboratory_order_exams', 2);
    }

    public function test_first_duplicate_can_be_removed_without_removing_the_later_duplicate(): void
    {
        [$user, $laboratory, $order, $exam, $priceList] = $this->activeContext();
        $first = $this->line($laboratory, $order, $exam, $priceList);
        $second = $this->line($laboratory, $order, $exam, $priceList);

        $this->request($user, $laboratory, $order, $first)->assertNoContent();

        $this->assertDatabaseMissing('laboratory_order_exams', ['id' => $first->id]);
        $this->assertDatabaseHas('laboratory_order_exams', ['id' => $second->id]);
    }

    public function test_middle_of_three_identical_lines_is_the_only_line_removed(): void
    {
        [$user, $laboratory, $order, $exam, $priceList] = $this->activeContext();
        $first = $this->line($laboratory, $order, $exam, $priceList);
        $middle = $this->line($laboratory, $order, $exam, $priceList);
        $last = $this->line($laboratory, $order, $exam, $priceList);

        $this->request($user, $laboratory, $order, $middle)->assertNoContent();

        $this->assertSame(
            [$first->id, $last->id],
            LaboratoryOrderExam::query()->orderBy('id')->pluck('id')->all(),
        );
    }

    public function test_last_line_is_physically_deleted_and_order_economics_become_zero(): void
    {
        [$user, $laboratory, $order, $exam, $priceList] = $this->activeContext([
            'subtotal' => '100.00',
            'discount' => '5.00',
            'taxes' => '10.00',
            'total' => '105.00',
        ]);
        $line = $this->line($laboratory, $order, $exam, $priceList);
        $response = $this->request($user, $laboratory, $order, $line)->assertNoContent();

        $this->assertSame('', $response->getContent());
        $this->assertDatabaseCount('laboratory_order_exams', 0);
        $order->refresh();
        $this->assertSame('0.00', $order->subtotal);
        $this->assertSame('0.00', $order->discount);
        $this->assertSame('0.00', $order->taxes);
        $this->assertSame('0.00', $order->total);
        $this->assertNull($order->discount_type);
        $this->assertNull($order->discount_value);
        $this->assertSame(LaboratoryOrder::STATUS_PENDING, $order->status);

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson("/api/v1/laboratory-orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.subtotal', '0.00')
            ->assertJsonPath('data.discount', '0.00')
            ->assertJsonPath('data.taxes', '0.00')
            ->assertJsonPath('data.total', '0.00');
    }

    public function test_second_delete_of_same_line_returns_404(): void
    {
        [$user, $laboratory, $order, $exam, $priceList] = $this->activeContext();
        $line = $this->line($laboratory, $order, $exam, $priceList);

        $this->request($user, $laboratory, $order, $line)->assertNoContent();
        $this->requestById($user, $laboratory, $order->id, $line->id)->assertNotFound();
    }

    #[DataProvider('nonPendingStatusProvider')]
    public function test_non_pending_order_rejects_delete_and_preserves_line(string $status): void
    {
        [$user, $laboratory, $order, $exam, $priceList] = $this->activeContext(['status' => $status]);
        $line = $this->line($laboratory, $order, $exam, $priceList);

        $this->request($user, $laboratory, $order, $line)
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('laboratory_order_exams', ['id' => $line->id]);
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

    public function test_missing_cross_tenant_and_wrong_parent_resources_return_neutral_404(): void
    {
        config(['app.debug' => false]);
        [$user, $laboratory, $order, $exam, $priceList] = $this->activeContext();
        $line = $this->line($laboratory, $order, $exam, $priceList);
        $otherOrder = LaboratoryOrder::factory()->for($laboratory)->create([
            'price_list_id' => $priceList->id,
            'currency' => $priceList->currency,
            'created_by' => $user->id,
        ]);
        $otherOrderLine = $this->line($laboratory, $otherOrder, $exam, $priceList);
        [, , $foreignOrder, $foreignExam, $foreignPriceList] = $this->activeContext(user: $user);
        $foreignLine = $this->line($foreignOrder->laboratory, $foreignOrder, $foreignExam, $foreignPriceList);

        $missingOrder = $this->requestById($user, $laboratory, 999999, $line->id)->assertNotFound();
        $crossTenantOrder = $this->requestById($user, $laboratory, $foreignOrder->id, $foreignLine->id)->assertNotFound();
        $missingLine = $this->requestById($user, $laboratory, $order->id, 999999)->assertNotFound();
        $wrongOrder = $this->requestById($user, $laboratory, $order->id, $otherOrderLine->id)->assertNotFound();
        $crossTenantLine = $this->requestById($user, $laboratory, $order->id, $foreignLine->id)->assertNotFound();

        foreach ([$crossTenantOrder, $missingLine, $wrongOrder, $crossTenantLine] as $response) {
            $this->assertSame($missingOrder->getContent(), $response->getContent());
        }
        $this->assertDatabaseHas('laboratory_order_exams', ['id' => $line->id]);
        $this->assertDatabaseHas('laboratory_order_exams', ['id' => $otherOrderLine->id]);
        $this->assertDatabaseHas('laboratory_order_exams', ['id' => $foreignLine->id]);
    }

    public function test_inactive_or_changed_catalogs_do_not_block_delete(): void
    {
        [$user, $laboratory, $order, $exam, $priceList, $price] = $this->activeContext();
        $line = $this->line($laboratory, $order, $exam, $priceList, [
            'unit_price' => '35.00',
            'exam_name' => 'Glucosa',
        ]);
        $exam->update(['status' => LaboratoryExam::STATUS_INACTIVE, 'name' => 'Glucosa sérica']);
        $priceList->update(['status' => PriceList::STATUS_INACTIVE]);
        $price->update(['status' => PriceListExam::STATUS_INACTIVE, 'price' => '40.00']);

        $this->request($user, $laboratory, $order, $line)->assertNoContent();

        $this->assertDatabaseMissing('laboratory_order_exams', ['id' => $line->id]);
    }

    public function test_missing_current_price_configuration_does_not_block_delete(): void
    {
        [$user, $laboratory, $order, $exam, $priceList, $price] = $this->activeContext();
        $line = $this->line($laboratory, $order, $exam, $priceList);
        $price->delete();

        $this->request($user, $laboratory, $order, $line)->assertNoContent();
    }

    #[DataProvider('bodyFieldProvider')]
    public function test_delete_rejects_arbitrary_body_fields(string $field): void
    {
        [$user, $laboratory, $order, $exam, $priceList] = $this->activeContext();
        $line = $this->line($laboratory, $order, $exam, $priceList);

        $this->request($user, $laboratory, $order, $line, [$field => 'injected'])
            ->assertUnprocessable()->assertJsonValidationErrors([$field]);
        $this->assertDatabaseHas('laboratory_order_exams', ['id' => $line->id]);
    }

    /** @return array<string, array{string}> */
    public static function bodyFieldProvider(): array
    {
        return collect(['reason', 'notes', 'force', 'laboratory_exam_id', 'quantity'])
            ->mapWithKeys(fn (string $field): array => [$field => [$field]])
            ->all();
    }

    public function test_cross_tenant_order_returns_404_before_body_validation(): void
    {
        config(['app.debug' => false]);
        [$user, $laboratory] = $this->activeTenant();
        [, , $foreignOrder, $foreignExam, $foreignPriceList] = $this->activeContext(user: $user);
        $foreignLine = $this->line($foreignOrder->laboratory, $foreignOrder, $foreignExam, $foreignPriceList);

        $this->requestById($user, $laboratory, $foreignOrder->id, $foreignLine->id, ['force' => true])
            ->assertNotFound();
        $this->assertDatabaseHas('laboratory_order_exams', ['id' => $foreignLine->id]);
    }

    public function test_auth_context_access_and_subscription_pipeline_precede_delete(): void
    {
        $url = '/api/v1/laboratory-orders/1/exams/1';
        $this->deleteJson($url)->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user, 'web')->deleteJson($url)->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');

        $laboratory = Laboratory::factory()->create();
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->deleteJson($url)->assertForbidden()->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->deleteJson($url)->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_malformed_route_identifiers_do_not_match(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        foreach (['bad/exams/1', '1/exams/bad'] as $path) {
            $this->actingAs($user, 'web')
                ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
                ->deleteJson("/api/v1/laboratory-orders/{$path}")
                ->assertNotFound();
        }
    }

    public function test_exception_after_delete_rolls_back_physical_deletion(): void
    {
        [, $laboratory, $order, $exam, $priceList] = $this->activeContext();
        $line = $this->line($laboratory, $order, $exam, $priceList);
        $originalOrder = $order->fresh()->getRawOriginal();
        Event::listen('eloquent.deleted: '.LaboratoryOrderExam::class, fn (): never => throw new RuntimeException('forced post-delete failure'));

        try {
            app(RemoveExamFromLaboratoryOrder::class)->execute($laboratory, $order->id, $line->id);
            $this->fail('The forced post-delete failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced post-delete failure', $exception->getMessage());
        }

        $this->assertDatabaseHas('laboratory_order_exams', ['id' => $line->id]);
        $this->assertSame($originalOrder, $order->fresh()->getRawOriginal());
    }

    public function test_delete_query_is_nested_recalculates_once_and_does_not_revalidate_catalogs(): void
    {
        [$user, $laboratory, $order, $exam, $priceList] = $this->activeContext();
        $line = $this->line($laboratory, $order, $exam, $priceList);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->request($user, $laboratory, $order, $line)->assertNoContent();

        foreach (['laboratory_exams', 'price_lists', 'price_list_exams', 'commercial_client_price_lists'] as $table) {
            $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'from "'.$table.'"')));
        }
        $lineSelect = collect($queries)->first(fn (string $sql): bool => str_contains($sql, 'from "laboratory_order_exams"'));
        $this->assertNotNull($lineSelect);
        $this->assertStringContainsString('"laboratory_id"', $lineSelect);
        $this->assertStringContainsString('"laboratory_order_id"', $lineSelect);
        $this->assertStringContainsString('"id"', $lineSelect);
        $this->assertLessThanOrEqual(
            1,
            collect($queries)->filter(fn (string $sql): bool => str_starts_with($sql, 'update "laboratory_orders"'))->count(),
        );
        $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'sum(')));
    }

    public function test_route_controller_and_openapi_expose_exact_delete_contract(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(
            fn ($route): bool => $route->uri() === 'api/v1/laboratory-orders/{laboratoryOrder}/exams/{laboratoryOrderExam}',
        );

        $this->assertNotNull($route);
        $this->assertSame(['DELETE'], $route->methods());
        $this->assertContains('saas', $route->middleware());
        $this->assertSame('[0-9]+', $route->wheres['laboratoryOrder']);
        $this->assertSame('[0-9]+', $route->wheres['laboratoryOrderExam']);
        $this->assertSame([
            'addExam', 'index', 'listExams', 'removeDiscount', 'removeExam', 'show', 'store', 'updateDiscount', 'updateStatus',
        ], collect((new ReflectionClass(LaboratoryOrderController::class))
            ->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === LaboratoryOrderController::class)
            ->pluck('name')->sort()->values()->all());
        $this->assertTrue(collect(Route::getRoutes()->getRoutes())->contains(
            fn ($candidate): bool => $candidate->uri() === 'api/v1/laboratory-orders/{laboratoryOrder}/exams'
                && in_array('GET', $candidate->methods(), true),
        ));

        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $path = $document['paths']['/api/v1/laboratory-orders/{laboratoryOrder}/exams/{laboratoryOrderExam}'];
        $operation = $path['delete'];
        $operationCount = collect($document['paths'])->sum(fn (array $item): int => count(array_intersect_key(
            $item,
            array_flip(['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace']),
        )));

        $this->assertSame(['delete'], array_keys($path));
        $this->assertSame(67, $operationCount);
        $this->assertArrayNotHasKey('requestBody', $operation);
        $this->assertSame([204, 400, 401, 403, 404, 422], array_keys($operation['responses']));
        $this->assertStringContainsString('no el identificador del examen', strtolower($operation['description']));
    }

    /**
     * @param  array<string, mixed>  $orderAttributes
     * @return array{User, Laboratory, LaboratoryOrder, LaboratoryExam, PriceList, PriceListExam}
     */
    private function activeContext(array $orderAttributes = [], ?User $user = null): array
    {
        [$user, $laboratory] = $this->activeTenant($user);
        $priceList = PriceList::factory()->for($laboratory)->create([
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

    /** @param array<string, mixed> $payload */
    private function request(
        User $user,
        Laboratory $laboratory,
        LaboratoryOrder $order,
        LaboratoryOrderExam $line,
        array $payload = [],
    ): TestResponse {
        return $this->requestById($user, $laboratory, $order->id, $line->id, $payload);
    }

    /** @param array<string, mixed> $payload */
    private function requestById(
        User $user,
        Laboratory $laboratory,
        int $orderId,
        int $lineId,
        array $payload = [],
    ): TestResponse {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->deleteJson("/api/v1/laboratory-orders/{$orderId}/exams/{$lineId}", $payload);
    }
}

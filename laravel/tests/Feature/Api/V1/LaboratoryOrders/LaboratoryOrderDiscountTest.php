<?php

namespace Tests\Feature\Api\V1\LaboratoryOrders;

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
use RuntimeException;
use Tests\TestCase;

final class LaboratoryOrderDiscountTest extends TestCase
{
    use RefreshDatabase;

    public function test_put_sets_replaces_and_repeats_discount_intent_with_order_resource(): void
    {
        [$user, $laboratory, $order, $exam] = $this->context();
        $this->line($laboratory, $order, $exam, '200.00');

        $this->putDiscount($user, $laboratory, $order, ['type' => 'percentage', 'value' => '10'])
            ->assertOk()
            ->assertJsonPath('data.discount_type', 'percentage')
            ->assertJsonPath('data.discount_value', '10.00')
            ->assertJsonPath('data.subtotal', '200.00')
            ->assertJsonPath('data.discount', '20.00')
            ->assertJsonPath('data.taxes', '0.00')
            ->assertJsonPath('data.total', '180.00')
            ->assertJsonMissingPath('data.exams');

        $this->putDiscount($user, $laboratory, $order, ['type' => 'percentage', 'value' => '10.00'])
            ->assertOk()
            ->assertJsonPath('data.discount', '20.00')
            ->assertJsonPath('data.total', '180.00');

        $this->putDiscount($user, $laboratory, $order, ['type' => 'percentage', 'value' => '15.00'])
            ->assertOk()
            ->assertJsonPath('data.discount_value', '15.00')
            ->assertJsonPath('data.discount', '30.00')
            ->assertJsonPath('data.total', '170.00');

        $this->putDiscount($user, $laboratory, $order, ['type' => 'percentage', 'value' => '12.5'])
            ->assertOk()
            ->assertJsonPath('data.discount_value', '12.50')
            ->assertJsonPath('data.discount', '25.00')
            ->assertJsonPath('data.total', '175.00');

        $this->putDiscount($user, $laboratory, $order, ['type' => 'percentage', 'value' => '100.00'])
            ->assertOk()
            ->assertJsonPath('data.discount', '200.00')
            ->assertJsonPath('data.total', '0.00');

        $this->putDiscount($user, $laboratory, $order, ['type' => 'amount', 'value' => '25.00'])
            ->assertOk()
            ->assertJsonPath('data.discount_type', 'amount')
            ->assertJsonPath('data.discount_value', '25.00')
            ->assertJsonPath('data.discount', '25.00')
            ->assertJsonPath('data.total', '175.00');

        $this->putDiscount($user, $laboratory, $order, ['type' => 'percentage', 'value' => 10])
            ->assertOk()
            ->assertJsonPath('data.discount_value', '10.00')
            ->assertJsonPath('data.discount', '20.00');
    }

    public function test_put_uses_snapshots_repeated_lines_and_half_up_calculator(): void
    {
        [$user, $laboratory, $order, $exam, $price] = $this->context();
        $this->line($laboratory, $order, $exam, '35.00');
        $this->line($laboratory, $order, $exam, '40.00');
        $this->line($laboratory, $order, $exam, '0.00');
        $price->update(['price' => '100.00']);

        $this->putDiscount($user, $laboratory, $order, ['type' => 'percentage', 'value' => '10.00'])
            ->assertOk()
            ->assertJsonPath('data.subtotal', '75.00')
            ->assertJsonPath('data.discount', '7.50')
            ->assertJsonPath('data.total', '67.50');

        $order->orderExams()->delete();
        $this->line($laboratory, $order, $exam, '10.05');
        $this->putDiscount($user, $laboratory, $order, ['type' => 'percentage', 'value' => '10.00'])
            ->assertOk()
            ->assertJsonPath('data.discount', '1.01')
            ->assertJsonPath('data.total', '9.04');
    }

    public function test_amount_can_exceed_subtotal_and_empty_order_keeps_intent_for_later_add(): void
    {
        [$user, $laboratory, $order, $exam, $price] = $this->context();

        $this->putDiscount($user, $laboratory, $order, ['type' => 'amount', 'value' => '100.00'])
            ->assertOk()
            ->assertJsonPath('data.subtotal', '0.00')
            ->assertJsonPath('data.discount_value', '100.00')
            ->assertJsonPath('data.discount', '0.00')
            ->assertJsonPath('data.total', '0.00');

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson("/api/v1/laboratory-orders/{$order->id}/exams", ['laboratory_exam_id' => $exam->id])
            ->assertCreated();
        $this->assertOrderEconomics($order, '35.00', '35.00', '0.00');

        $price->update(['price' => '80.00']);
        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson("/api/v1/laboratory-orders/{$order->id}/exams", ['laboratory_exam_id' => $exam->id])
            ->assertCreated();
        $this->assertOrderEconomics($order, '115.00', '100.00', '15.00');
    }

    public function test_maximum_numeric_amount_fits_persisted_domain_on_an_empty_order(): void
    {
        [$user, $laboratory, $order] = $this->context();

        $this->putDiscount($user, $laboratory, $order, ['type' => 'amount', 'value' => '9999999999.99'])
            ->assertOk()
            ->assertJsonPath('data.discount_type', 'amount')
            ->assertJsonPath('data.discount_value', '9999999999.99')
            ->assertJsonPath('data.discount', '0.00')
            ->assertJsonPath('data.total', '0.00');
    }

    public function test_delete_discount_is_idempotent_returns_resource_and_keeps_lines(): void
    {
        [$user, $laboratory, $order, $exam] = $this->context([
            'discount_type' => LaboratoryOrder::DISCOUNT_TYPE_AMOUNT,
            'discount_value' => '100.00',
            'subtotal' => '70.00',
            'discount' => '70.00',
            'total' => '0.00',
        ]);
        $line = $this->line($laboratory, $order, $exam, '70.00');

        $this->deleteDiscount($user, $laboratory, $order)
            ->assertOk()
            ->assertJsonPath('data.discount_type', null)
            ->assertJsonPath('data.discount_value', null)
            ->assertJsonPath('data.subtotal', '70.00')
            ->assertJsonPath('data.discount', '0.00')
            ->assertJsonPath('data.taxes', '0.00')
            ->assertJsonPath('data.total', '70.00');

        $this->assertDatabaseHas('laboratory_order_exams', ['id' => $line->id]);
        $this->deleteDiscount($user, $laboratory, $order)
            ->assertOk()
            ->assertJsonPath('data.discount_type', null)
            ->assertJsonPath('data.discount_value', null)
            ->assertJsonPath('data.total', '70.00');

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->deleteJson("/api/v1/laboratory-orders/{$order->id}/discount", ['discount' => '1.00'])
            ->assertUnprocessable();
    }

    public function test_delete_clears_legacy_effective_discount_and_put_replaces_legacy_value(): void
    {
        [$user, $laboratory, $order, $exam] = $this->context([
            'discount_type' => null,
            'discount_value' => null,
            'subtotal' => '35.00',
            'discount' => '15.00',
            'total' => '20.00',
        ]);
        $this->line($laboratory, $order, $exam, '35.00');

        $this->putDiscount($user, $laboratory, $order, ['type' => 'percentage', 'value' => '10.00'])
            ->assertOk()
            ->assertJsonPath('data.discount', '3.50')
            ->assertJsonPath('data.total', '31.50');

        DB::table('laboratory_orders')->where('id', $order->id)->update([
            'discount_type' => null,
            'discount_value' => null,
            'discount' => '15.00',
            'total' => '20.00',
        ]);
        $this->deleteDiscount($user, $laboratory, $order)
            ->assertOk()
            ->assertJsonPath('data.discount_type', null)
            ->assertJsonPath('data.discount_value', null)
            ->assertJsonPath('data.discount', '0.00')
            ->assertJsonPath('data.total', '35.00');
    }

    #[DataProvider('invalidDiscountProvider')]
    public function test_put_rejects_invalid_discount_payload_without_mutation(array $payload): void
    {
        [$user, $laboratory, $order] = $this->context(['subtotal' => '200.00', 'total' => '200.00']);
        $before = $order->fresh()->getRawOriginal();

        $this->putDiscount($user, $laboratory, $order, $payload)->assertUnprocessable();

        $this->assertSame($before, $order->fresh()->getRawOriginal());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidDiscountProvider(): array
    {
        return [
            'missing value' => [['type' => 'percentage']],
            'unknown key' => [['type' => 'percentage', 'value' => '10.00', 'discount' => '20.00']],
            'server owned field' => [['type' => 'amount', 'value' => '25.00', 'subtotal' => '0.00']],
            'unknown type' => [['type' => 'fixed', 'value' => '10.00']],
            'case mismatch' => [['type' => 'Percentage', 'value' => '10.00']],
            'whitespace type' => [['type' => ' percentage ', 'value' => '10.00']],
            'zero percentage' => [['type' => 'percentage', 'value' => '0']],
            'negative percentage' => [['type' => 'percentage', 'value' => '-1']],
            'percentage over range' => [['type' => 'percentage', 'value' => '100.01']],
            'zero amount' => [['type' => 'amount', 'value' => '0.00']],
            'negative amount' => [['type' => 'amount', 'value' => '-1.00']],
            'too many decimals' => [['type' => 'amount', 'value' => '10.001']],
            'tiny too many decimals' => [['type' => 'percentage', 'value' => '0.001']],
            'numeric fractional JSON float' => [['type' => 'amount', 'value' => 10.5]],
            'boolean' => [['type' => 'amount', 'value' => true]],
            'array' => [['type' => 'amount', 'value' => []]],
            'null' => [['type' => 'amount', 'value' => null]],
            'not numeric' => [['type' => 'amount', 'value' => 'abc']],
            'amount exceeds numeric domain' => [['type' => 'amount', 'value' => '10000000000.00']],
        ];
    }

    public function test_non_pending_and_cross_tenant_orders_are_rejected_before_body_validation(): void
    {
        [$user, $laboratory, $order] = $this->context(['status' => LaboratoryOrder::STATUS_IN_PROCESS]);

        $this->putDiscount($user, $laboratory, $order, ['type' => 'amount', 'value' => '25.00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
        $this->deleteDiscount($user, $laboratory, $order)->assertUnprocessable();

        [$otherUser, $otherLaboratory] = $this->tenant();
        $foreignOrder = LaboratoryOrder::factory()->for($otherLaboratory)->create([
            'price_list_id' => PriceList::factory()->for($otherLaboratory)->create([
                'currency' => 'GTQ',
                'status' => PriceList::STATUS_ACTIVE,
            ])->id,
            'currency' => 'GTQ',
            'created_by' => $otherUser->id,
        ]);
        $this->putDiscount($user, $laboratory, $foreignOrder, ['foo' => true])
            ->assertNotFound()
            ->assertJsonPath('message', 'Resource not found.');
        $this->deleteDiscount($user, $laboratory, $foreignOrder)->assertNotFound();
        $this->putDiscount($user, $laboratory, $order, ['foo' => true], $order->id + 999)->assertNotFound();
    }

    public function test_failure_during_recalculation_rolls_back_put_and_delete_intent_and_economics(): void
    {
        [$user, $laboratory, $order] = $this->context([
            'discount_type' => LaboratoryOrder::DISCOUNT_TYPE_AMOUNT,
            'discount_value' => '10.00',
            'subtotal' => '200.00',
            'discount' => '10.00',
            'total' => '190.00',
        ]);
        $before = $order->fresh()->getRawOriginal();
        $updates = 0;
        Event::listen('eloquent.updating: '.LaboratoryOrder::class, function () use (&$updates): void {
            if (++$updates % 2 === 0) {
                throw new RuntimeException('forced recalculation failure');
            }
        });

        foreach ([
            fn () => $this->putDiscount($user, $laboratory, $order, ['type' => 'percentage', 'value' => '15.00']),
            fn () => $this->deleteDiscount($user, $laboratory, $order),
        ] as $operation) {
            $operation()->assertInternalServerError();
            $this->assertSame($before, $order->fresh()->getRawOriginal());
        }
    }

    public function test_discount_operations_audit_lock_before_intent_and_single_line_query_without_catalog_lookups(): void
    {
        [$user, $laboratory, $order, $exam] = $this->context();
        $this->line($laboratory, $order, $exam, '35.00');
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->putDiscount($user, $laboratory, $order, ['type' => 'percentage', 'value' => '10.00'])->assertOk();
        $lineQueries = collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'from "laboratory_order_exams"'));
        $this->assertCount(1, $lineQueries);
        $this->assertStringContainsString('"laboratory_id"', $lineQueries->sole());
        $this->assertStringContainsString('"laboratory_order_id"', $lineQueries->sole());
        foreach (['laboratory_exams', 'price_lists', 'price_list_exams'] as $table) {
            $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'from "'.$table.'"')));
        }

        $lockIndex = collect($queries)->search(fn (string $statement): bool => str_contains($statement, 'for update'));
        $intentUpdateIndex = collect($queries)->search(fn (string $statement): bool => str_starts_with($statement, 'update "laboratory_orders"')
            && str_contains($statement, '"discount_type"'));
        $lineSelectIndex = collect($queries)->search(fn (string $statement): bool => str_contains($statement, 'from "laboratory_order_exams"'));
        $economicUpdateIndex = collect($queries)->search(fn (string $statement): bool => str_starts_with($statement, 'update "laboratory_orders"')
            && str_contains($statement, '"subtotal"'));

        $this->assertNotFalse($intentUpdateIndex);
        $this->assertNotFalse($lineSelectIndex);
        $this->assertNotFalse($economicUpdateIndex);
        if (DB::getDriverName() === 'pgsql') {
            $this->assertNotFalse($lockIndex);
            $this->assertLessThan($intentUpdateIndex, $lockIndex);
        }
        $this->assertLessThan($lineSelectIndex, $intentUpdateIndex);
        $this->assertLessThan($economicUpdateIndex, $lineSelectIndex);
    }

    public function test_discount_routes_use_saas_and_openapi_exposes_exact_contract(): void
    {
        $put = collect(Route::getRoutes()->getRoutes())->first(
            fn ($route): bool => $route->uri() === 'api/v1/laboratory-orders/{laboratoryOrder}/discount'
                && in_array('PUT', $route->methods(), true),
        );
        $delete = collect(Route::getRoutes()->getRoutes())->first(
            fn ($route): bool => $route->uri() === 'api/v1/laboratory-orders/{laboratoryOrder}/discount'
                && in_array('DELETE', $route->methods(), true),
        );

        $this->assertNotNull($put);
        $this->assertNotNull($delete);
        $this->assertContains('saas', $put->middleware());
        $this->assertContains('saas', $delete->middleware());
        $this->assertSame('[0-9]+', $put->wheres['laboratoryOrder']);

        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $path = $document['paths']['/api/v1/laboratory-orders/{laboratoryOrder}/discount'];
        $input = $document['components']['schemas']['UpdateLaboratoryOrderDiscountInput'];
        $this->assertSame(['put', 'delete'], array_keys($path));
        $this->assertSame(69, collect($document['paths'])->sum(fn (array $item): int => count(array_intersect_key(
            $item,
            array_flip(['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace']),
        ))));
        $this->assertSame(['type', 'value'], $input['required']);
        $this->assertFalse($input['additionalProperties']);
        $this->assertSame(['percentage', 'amount'], $input['properties']['type']['enum']);
        $this->assertSame('#/components/schemas/LaboratoryOrderResponse', $path['put']['responses']['200']['content']['application/json']['schema']['$ref']);
        $this->assertSame('#/components/schemas/LaboratoryOrderResponse', $path['delete']['responses']['200']['content']['application/json']['schema']['$ref']);
    }

    /** @return array{User, Laboratory} */
    private function tenant(): array
    {
        $user = User::factory()->create();
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

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{User, Laboratory, LaboratoryOrder, LaboratoryExam, PriceListExam}
     */
    private function context(array $attributes = []): array
    {
        [$user, $laboratory] = $this->tenant();
        $priceList = PriceList::factory()->for($laboratory)->create([
            'currency' => 'GTQ',
            'status' => PriceList::STATUS_ACTIVE,
        ]);
        $order = LaboratoryOrder::factory()->for($laboratory)->create(array_replace([
            'price_list_id' => $priceList->id,
            'currency' => 'GTQ',
            'status' => LaboratoryOrder::STATUS_PENDING,
            'created_by' => $user->id,
        ], $attributes));
        $exam = LaboratoryExam::factory()->for($laboratory)->create(['status' => LaboratoryExam::STATUS_ACTIVE]);
        $price = PriceListExam::factory()->for($laboratory)->create([
            'price_list_id' => $priceList->id,
            'laboratory_exam_id' => $exam->id,
            'price' => '35.00',
            'status' => PriceListExam::STATUS_ACTIVE,
        ]);

        return [$user, $laboratory, $order, $exam, $price];
    }

    private function line(Laboratory $laboratory, LaboratoryOrder $order, LaboratoryExam $exam, string $price): LaboratoryOrderExam
    {
        return LaboratoryOrderExam::factory()->for($laboratory)->create([
            'laboratory_order_id' => $order->id,
            'laboratory_exam_id' => $exam->id,
            'price_list_id' => $order->price_list_id,
            'unit_price' => $price,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function putDiscount(User $user, Laboratory $laboratory, LaboratoryOrder $order, array $payload, ?int $id = null): TestResponse
    {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->putJson('/api/v1/laboratory-orders/'.($id ?? $order->id).'/discount', $payload);
    }

    private function deleteDiscount(User $user, Laboratory $laboratory, LaboratoryOrder $order): TestResponse
    {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->deleteJson("/api/v1/laboratory-orders/{$order->id}/discount");
    }

    private function assertOrderEconomics(LaboratoryOrder $order, string $subtotal, string $discount, string $total): void
    {
        $order->refresh();
        $this->assertSame($subtotal, $order->subtotal);
        $this->assertSame($discount, $order->discount);
        $this->assertSame('0.00', $order->taxes);
        $this->assertSame($total, $order->total);
        $this->assertSame(LaboratoryOrder::DISCOUNT_TYPE_AMOUNT, $order->discount_type);
        $this->assertSame('100.00', $order->discount_value);
    }
}

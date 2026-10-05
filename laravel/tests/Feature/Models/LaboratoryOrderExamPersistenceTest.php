<?php

namespace Tests\Feature\Models;

use App\Models\Laboratory;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Models\PriceList;
use App\Models\PriceListExam;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LaboratoryOrderExamPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_exam_persists_snapshots_cast_and_relations(): void
    {
        [$laboratory, $order, $exam, $priceList] = $this->ownedContext();

        $orderExam = LaboratoryOrderExam::query()->create($this->attributes(
            $laboratory,
            $order,
            $exam,
            $priceList,
            [
                'unit_price' => '123456.78',
                'exam_code' => 'CBC-2026',
                'exam_name' => 'Hemograma completo',
                'price_list_name' => 'Convenio Empresa ABC',
            ],
        ))->fresh();

        $this->assertSame('123456.78', $orderExam->unit_price);
        $this->assertSame('CBC-2026', $orderExam->exam_code);
        $this->assertSame('Hemograma completo', $orderExam->exam_name);
        $this->assertSame('Convenio Empresa ABC', $orderExam->price_list_name);
        $this->assertNotNull($orderExam->created_at);
        $this->assertNotNull($orderExam->updated_at);
        $this->assertTrue($orderExam->laboratory->is($laboratory));
        $this->assertTrue($orderExam->order->is($order));
        $this->assertTrue($orderExam->exam->is($exam));
        $this->assertTrue($orderExam->priceList->is($priceList));
        $this->assertTrue($laboratory->orderExams->contains($orderExam));
        $this->assertTrue($order->orderExams->contains($orderExam));
        $this->assertTrue($exam->orderExams->contains($orderExam));
        $this->assertTrue($priceList->orderExams->contains($orderExam));
    }

    public function test_schema_contains_exactly_the_approved_columns(): void
    {
        $this->assertSame([
            'id',
            'laboratory_id',
            'laboratory_order_id',
            'laboratory_exam_id',
            'price_list_id',
            'unit_price',
            'exam_code',
            'exam_name',
            'price_list_name',
            'created_at',
            'updated_at',
        ], Schema::getColumnListing('laboratory_order_exams'));
    }

    public function test_factory_creates_tenant_consistent_references_and_catalog_snapshots(): void
    {
        $orderExam = LaboratoryOrderExam::factory()->create()->fresh();

        $this->assertSame($orderExam->laboratory_id, $orderExam->order->laboratory_id);
        $this->assertSame($orderExam->laboratory_id, $orderExam->exam->laboratory_id);
        $this->assertSame($orderExam->laboratory_id, $orderExam->priceList->laboratory_id);
        $this->assertSame($orderExam->order->price_list_id, $orderExam->price_list_id);
        $this->assertSame($orderExam->exam->code, $orderExam->exam_code);
        $this->assertSame($orderExam->exam->name, $orderExam->exam_name);
        $this->assertSame($orderExam->priceList->name, $orderExam->price_list_name);
    }

    public function test_explicit_scope_isolates_tenants_without_http_context(): void
    {
        $laboratoryA = Laboratory::factory()->create();
        $laboratoryB = Laboratory::factory()->create();
        $itemsA = LaboratoryOrderExam::factory()->count(2)->for($laboratoryA)->create();
        $itemB = LaboratoryOrderExam::factory()->for($laboratoryB)->create();
        $currentLaboratory = $this->app->make(CurrentLaboratory::class);

        $this->assertFalse($currentLaboratory->has());
        $this->assertEqualsCanonicalizing(
            $itemsA->modelKeys(),
            LaboratoryOrderExam::forLaboratory($laboratoryA)->pluck('id')->all(),
        );
        $this->assertSame(
            [$itemB->id],
            LaboratoryOrderExam::forLaboratory($laboratoryB)->pluck('id')->all(),
        );
        $this->assertSame(3, LaboratoryOrderExam::query()->count());
        $this->assertFalse($currentLaboratory->has());
    }

    #[DataProvider('crossTenantReferenceProvider')]
    public function test_database_rejects_a_cross_tenant_reference(string $column): void
    {
        [$laboratory, $order, $exam, $priceList] = $this->ownedContext();
        [, $otherOrder, $otherExam, $otherPriceList] = $this->ownedContext();
        $foreignIds = [
            'laboratory_order_id' => $otherOrder->id,
            'laboratory_exam_id' => $otherExam->id,
            'price_list_id' => $otherPriceList->id,
        ];

        $this->expectException(QueryException::class);
        LaboratoryOrderExam::query()->create($this->attributes(
            $laboratory,
            $order,
            $exam,
            $priceList,
            [$column => $foreignIds[$column]],
        ));
    }

    /** @return array<string, array{string}> */
    public static function crossTenantReferenceProvider(): array
    {
        return [
            'order' => ['laboratory_order_id'],
            'exam' => ['laboratory_exam_id'],
            'price list' => ['price_list_id'],
        ];
    }

    public function test_same_exam_can_appear_twice_in_the_same_order_with_independent_ids(): void
    {
        [$laboratory, $order, $exam, $priceList] = $this->ownedContext();
        $attributes = $this->attributes($laboratory, $order, $exam, $priceList);

        $first = LaboratoryOrderExam::query()->create($attributes);
        $second = LaboratoryOrderExam::query()->create($attributes);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, $order->orderExams()->count());
    }

    public function test_factory_can_create_same_exam_three_times_without_deduplicating_relations(): void
    {
        [$laboratory, $order, $exam, $priceList] = $this->ownedContext();

        $lines = LaboratoryOrderExam::factory()->count(3)->for($laboratory)->create([
            'laboratory_order_id' => $order->id,
            'laboratory_exam_id' => $exam->id,
            'price_list_id' => $priceList->id,
        ]);

        $this->assertCount(3, $lines);
        $this->assertCount(3, array_unique($lines->modelKeys()));
        $this->assertSame(3, $order->orderExams()->count());
        $this->assertSame(3, $exam->orderExams()->count());
        $this->assertSame(3, $priceList->orderExams()->count());
    }

    public function test_repeated_exam_lines_preserve_independent_snapshot_values(): void
    {
        [$laboratory, $order, $exam, $priceList] = $this->ownedContext([
            'code' => 'GLU',
            'name' => 'Glucosa',
        ], ['name' => 'Tarifa original']);
        $first = LaboratoryOrderExam::query()->create($this->attributes(
            $laboratory,
            $order,
            $exam,
            $priceList,
            ['unit_price' => '35.00'],
        ));
        $exam->update(['code' => 'GLU-S', 'name' => 'Glucosa sérica']);
        $priceList->update(['name' => 'Tarifa actualizada']);

        $second = LaboratoryOrderExam::query()->create($this->attributes(
            $laboratory,
            $order,
            $exam,
            $priceList,
            ['unit_price' => '40.00'],
        ));

        $this->assertSame('35.00', $first->unit_price);
        $this->assertSame('GLU', $first->exam_code);
        $this->assertSame('Glucosa', $first->exam_name);
        $this->assertSame('Tarifa original', $first->price_list_name);
        $this->assertSame('40.00', $second->unit_price);
        $this->assertSame('GLU-S', $second->exam_code);
        $this->assertSame('Glucosa sérica', $second->exam_name);
        $this->assertSame('Tarifa actualizada', $second->price_list_name);
        $this->assertSame($first->laboratory_exam_id, $second->laboratory_exam_id);
        $this->assertSame($first->laboratory_order_id, $second->laboratory_order_id);
        $this->assertSame($first->price_list_id, $second->price_list_id);
    }

    public function test_same_exam_in_different_orders_is_allowed(): void
    {
        [$laboratory, $order, $exam, $priceList] = $this->ownedContext();
        $otherOrder = LaboratoryOrder::factory()->for($laboratory)->create([
            'price_list_id' => $priceList->id,
        ]);

        LaboratoryOrderExam::query()->create($this->attributes($laboratory, $order, $exam, $priceList));
        LaboratoryOrderExam::query()->create($this->attributes($laboratory, $otherOrder, $exam, $priceList));

        $this->assertSame(2, $exam->orderExams()->count());
    }

    public function test_same_catalog_code_in_different_laboratories_does_not_conflict(): void
    {
        [$laboratoryA, $orderA, $examA, $priceListA] = $this->ownedContext(['code' => 'CBC']);
        [$laboratoryB, $orderB, $examB, $priceListB] = $this->ownedContext(['code' => 'CBC']);

        LaboratoryOrderExam::query()->create($this->attributes($laboratoryA, $orderA, $examA, $priceListA));
        LaboratoryOrderExam::query()->create($this->attributes($laboratoryB, $orderB, $examB, $priceListB));

        $this->assertSame(2, LaboratoryOrderExam::query()->where('exam_code', 'CBC')->count());
    }

    #[DataProvider('validPriceProvider')]
    public function test_valid_decimal_prices_are_persisted_exactly(string $price): void
    {
        $orderExam = LaboratoryOrderExam::factory()->create(['unit_price' => $price])->fresh();

        $this->assertSame($price, $orderExam->unit_price);
    }

    /** @return array<string, array{string}> */
    public static function validPriceProvider(): array
    {
        return [
            'zero' => ['0.00'],
            'representative decimal' => ['123456.78'],
            'maximum numeric 12 2' => ['9999999999.99'],
        ];
    }

    public function test_database_rejects_a_negative_unit_price(): void
    {
        [$laboratory, $order, $exam, $priceList] = $this->ownedContext();

        $this->expectException(QueryException::class);
        DB::table('laboratory_order_exams')->insert($this->attributes(
            $laboratory,
            $order,
            $exam,
            $priceList,
            ['unit_price' => '-0.01'],
        ));
    }

    public function test_postgresql_rejects_numeric_overflow(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('NUMERIC precision is authoritative in PostgreSQL.');
        }

        [$laboratory, $order, $exam, $priceList] = $this->ownedContext();

        $this->expectException(QueryException::class);
        DB::table('laboratory_order_exams')->insert($this->attributes(
            $laboratory,
            $order,
            $exam,
            $priceList,
            ['unit_price' => '10000000000.00'],
        ));
    }

    public function test_catalog_changes_do_not_change_historical_snapshots(): void
    {
        [$laboratory, $order, $exam, $priceList] = $this->ownedContext([
            'code' => 'CBC-OLD',
            'name' => 'Hemograma original',
        ], ['name' => 'Tarifa original']);
        $priceConfiguration = PriceListExam::factory()->for($laboratory)->create([
            'price_list_id' => $priceList->id,
            'laboratory_exam_id' => $exam->id,
            'price' => '50.00',
        ]);
        $orderExam = LaboratoryOrderExam::query()->create($this->attributes(
            $laboratory,
            $order,
            $exam,
            $priceList,
            ['unit_price' => '50.00'],
        ));

        $exam->update([
            'code' => 'CBC-NEW',
            'name' => 'Hemograma renombrado',
            'status' => LaboratoryExam::STATUS_INACTIVE,
        ]);
        $priceList->update([
            'name' => 'Tarifa renombrada',
            'status' => PriceList::STATUS_INACTIVE,
        ]);
        $priceConfiguration->update(['price' => '65.00']);
        $orderExam->refresh();

        $this->assertSame($priceList->id, $orderExam->price_list_id);
        $this->assertSame('CBC-OLD', $orderExam->exam_code);
        $this->assertSame('Hemograma original', $orderExam->exam_name);
        $this->assertSame('Tarifa original', $orderExam->price_list_name);
        $this->assertSame('50.00', $orderExam->unit_price);
        $this->assertModelExists($orderExam);
    }

    #[DataProvider('restrictedParentProvider')]
    public function test_referenced_parent_cannot_be_deleted(string $relation): void
    {
        [$laboratory, $order, $exam, $priceList] = $this->ownedContext();
        LaboratoryOrderExam::query()->create($this->attributes($laboratory, $order, $exam, $priceList));
        $parent = match ($relation) {
            'laboratory' => $laboratory,
            'order' => $order,
            'exam' => $exam,
            'price list' => $priceList,
        };

        $this->expectException(QueryException::class);
        $parent->delete();
    }

    /** @return array<string, array{string}> */
    public static function restrictedParentProvider(): array
    {
        return [
            'laboratory' => ['laboratory'],
            'order' => ['order'],
            'exam' => ['exam'],
            'price list' => ['price list'],
        ];
    }

    public function test_persisting_order_exam_does_not_mutate_order_economics_status_or_timestamp(): void
    {
        [$laboratory, $order, $exam, $priceList] = $this->ownedContext();
        $before = $order->only([
            'subtotal',
            'discount',
            'taxes',
            'total',
            'status',
        ]);
        $updatedAt = $order->updated_at->format('Y-m-d H:i:s.u');

        LaboratoryOrderExam::query()->create($this->attributes($laboratory, $order, $exam, $priceList));

        $order->refresh();

        $this->assertSame($before, $order->only(array_keys($before)));
        $this->assertSame($updatedAt, $order->updated_at->format('Y-m-d H:i:s.u'));
    }

    public function test_model_has_exact_mass_assignment_contract_and_no_hidden_tenant_scope(): void
    {
        $model = new LaboratoryOrderExam;

        $this->assertSame([
            'laboratory_id',
            'laboratory_order_id',
            'laboratory_exam_id',
            'price_list_id',
            'unit_price',
            'exam_code',
            'exam_name',
            'price_list_name',
        ], $model->getFillable());
        $this->assertSame(['*'], $model->getGuarded());
        $this->assertSame([], $model->getGlobalScopes());
        $this->assertFalse(in_array(SoftDeletes::class, class_uses_recursive($model), true));
    }

    /**
     * @param  array<string, mixed>  $examAttributes
     * @param  array<string, mixed>  $priceListAttributes
     * @return array{Laboratory, LaboratoryOrder, LaboratoryExam, PriceList}
     */
    private function ownedContext(
        array $examAttributes = [],
        array $priceListAttributes = [],
    ): array {
        $laboratory = Laboratory::factory()->create();
        $priceList = PriceList::factory()->for($laboratory)->create($priceListAttributes);
        $order = LaboratoryOrder::factory()->for($laboratory)->create([
            'price_list_id' => $priceList->id,
        ]);
        $exam = LaboratoryExam::factory()->for($laboratory)->create($examAttributes);

        return [$laboratory, $order, $exam, $priceList];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function attributes(
        Laboratory $laboratory,
        LaboratoryOrder $order,
        LaboratoryExam $exam,
        PriceList $priceList,
        array $overrides = [],
    ): array {
        return array_merge([
            'laboratory_id' => $laboratory->id,
            'laboratory_order_id' => $order->id,
            'laboratory_exam_id' => $exam->id,
            'price_list_id' => $priceList->id,
            'unit_price' => '50.00',
            'exam_code' => $exam->code,
            'exam_name' => $exam->name,
            'price_list_name' => $priceList->name,
        ], $overrides);
    }
}

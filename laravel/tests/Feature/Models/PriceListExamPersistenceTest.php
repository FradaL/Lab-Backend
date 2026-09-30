<?php

namespace Tests\Feature\Models;

use App\Models\Laboratory;
use App\Models\LaboratoryExam;
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

class PriceListExamPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_list_exam_can_be_persisted_with_exact_decimal_price(): void
    {
        [$laboratory, $priceList, $exam] = $this->ownedContext();

        $item = PriceListExam::query()->create($this->attributes(
            $laboratory,
            $priceList,
            $exam,
            ['price' => '75.00', 'status' => PriceListExam::STATUS_INACTIVE],
        ))->fresh();

        $this->assertSame('75.00', $item->price);
        $this->assertSame(PriceListExam::STATUS_INACTIVE, $item->status);
        $this->assertNotNull($item->created_at);
        $this->assertNotNull($item->updated_at);
    }

    public function test_schema_contains_exactly_the_approved_columns(): void
    {
        $this->assertSame([
            'id',
            'laboratory_id',
            'price_list_id',
            'laboratory_exam_id',
            'price',
            'status',
            'created_at',
            'updated_at',
        ], Schema::getColumnListing('price_list_exams'));
    }

    #[DataProvider('validPriceProvider')]
    public function test_valid_decimal_prices_are_persisted_exactly(string $price): void
    {
        $item = PriceListExam::factory()->create(['price' => $price])->fresh();

        $this->assertSame($price, $item->price);
    }

    /** @return array<string, array{string}> */
    public static function validPriceProvider(): array
    {
        return [
            'zero' => ['0.00'],
            'cent' => ['0.01'],
            'ordinary decimal' => ['12.34'],
            'maximum numeric 12 2' => ['9999999999.99'],
        ];
    }

    public function test_database_rejects_a_negative_price(): void
    {
        [$laboratory, $priceList, $exam] = $this->ownedContext();

        $this->expectException(QueryException::class);
        DB::table('price_list_exams')->insert($this->attributes(
            $laboratory,
            $priceList,
            $exam,
            ['price' => '-0.01'],
        ));
    }

    public function test_postgresql_rejects_numeric_overflow(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('NUMERIC precision is authoritative in PostgreSQL.');
        }

        [$laboratory, $priceList, $exam] = $this->ownedContext();

        $this->expectException(QueryException::class);
        DB::table('price_list_exams')->insert($this->attributes(
            $laboratory,
            $priceList,
            $exam,
            ['price' => '10000000000.00'],
        ));
    }

    public function test_database_defaults_status_to_active(): void
    {
        [$laboratory, $priceList, $exam] = $this->ownedContext();
        $attributes = $this->attributes($laboratory, $priceList, $exam);
        unset($attributes['status']);

        $id = DB::table('price_list_exams')->insertGetId($attributes);

        $this->assertSame(
            PriceListExam::STATUS_ACTIVE,
            PriceListExam::query()->findOrFail($id)->status,
        );
    }

    public function test_all_approved_relationships_resolve_the_expected_models(): void
    {
        [$laboratory, $priceList, $exam] = $this->ownedContext();
        $item = PriceListExam::factory()->create($this->attributes(
            $laboratory,
            $priceList,
            $exam,
        ));

        $this->assertTrue($item->laboratory->is($laboratory));
        $this->assertTrue($item->priceList->is($priceList));
        $this->assertTrue($item->laboratoryExam->is($exam));
        $this->assertTrue($laboratory->priceListExams->contains($item));
        $this->assertTrue($priceList->priceListExams->contains($item));
        $this->assertTrue($exam->priceListExams->contains($item));
    }

    public function test_explicit_scope_isolates_tenants_without_current_laboratory(): void
    {
        $laboratoryA = Laboratory::factory()->create();
        $laboratoryB = Laboratory::factory()->create();
        $itemsA = PriceListExam::factory()->count(2)->for($laboratoryA)->create();
        $itemB = PriceListExam::factory()->for($laboratoryB)->create();
        $currentLaboratory = $this->app->make(CurrentLaboratory::class);

        $this->assertFalse($currentLaboratory->has());
        $this->assertEqualsCanonicalizing(
            $itemsA->modelKeys(),
            PriceListExam::forLaboratory($laboratoryA)->pluck('id')->all(),
        );
        $this->assertSame(
            [$itemB->id],
            PriceListExam::forLaboratory($laboratoryB)->pluck('id')->all(),
        );
        $this->assertFalse($currentLaboratory->has());
    }

    public function test_duplicate_exam_in_the_same_price_list_is_rejected(): void
    {
        [$laboratory, $priceList, $exam] = $this->ownedContext();
        $attributes = $this->attributes($laboratory, $priceList, $exam);
        PriceListExam::query()->create($attributes);

        $this->expectException(QueryException::class);
        PriceListExam::query()->create($attributes);
    }

    public function test_same_exam_in_different_price_lists_is_allowed(): void
    {
        [$laboratory, $priceList, $exam] = $this->ownedContext();
        $otherList = PriceList::factory()->for($laboratory)->create();

        PriceListExam::query()->create($this->attributes($laboratory, $priceList, $exam));
        PriceListExam::query()->create($this->attributes(
            $laboratory,
            $otherList,
            $exam,
            ['price' => '60.00'],
        ));

        $this->assertSame(2, $exam->priceListExams()->count());
    }

    public function test_different_exams_in_the_same_price_list_are_allowed(): void
    {
        [$laboratory, $priceList, $exam] = $this->ownedContext();
        $otherExam = LaboratoryExam::factory()->for($laboratory)->create();

        PriceListExam::query()->create($this->attributes($laboratory, $priceList, $exam));
        PriceListExam::query()->create($this->attributes($laboratory, $priceList, $otherExam));

        $this->assertSame(2, $priceList->priceListExams()->count());
    }

    public function test_database_rejects_a_price_list_from_another_tenant(): void
    {
        [$laboratory, , $exam] = $this->ownedContext();
        [, $otherPriceList] = $this->ownedContext();

        $this->expectException(QueryException::class);
        PriceListExam::query()->create($this->attributes($laboratory, $otherPriceList, $exam));
    }

    public function test_database_rejects_an_exam_from_another_tenant(): void
    {
        [$laboratory, $priceList] = $this->ownedContext();
        [, , $otherExam] = $this->ownedContext();

        $this->expectException(QueryException::class);
        PriceListExam::query()->create($this->attributes($laboratory, $priceList, $otherExam));
    }

    public function test_database_rejects_both_parents_from_another_tenant(): void
    {
        [$laboratory] = $this->ownedContext();
        [, $otherPriceList, $otherExam] = $this->ownedContext();

        $this->expectException(QueryException::class);
        PriceListExam::query()->create($this->attributes(
            $laboratory,
            $otherPriceList,
            $otherExam,
        ));
    }

    #[DataProvider('nonexistentForeignKeyProvider')]
    public function test_database_rejects_nonexistent_foreign_keys(string $column): void
    {
        [$laboratory, $priceList, $exam] = $this->ownedContext();

        $this->expectException(QueryException::class);
        PriceListExam::query()->create($this->attributes(
            $laboratory,
            $priceList,
            $exam,
            [$column => 999999],
        ));
    }

    /** @return array<string, array{string}> */
    public static function nonexistentForeignKeyProvider(): array
    {
        return [
            'laboratory' => ['laboratory_id'],
            'price list' => ['price_list_id'],
            'laboratory exam' => ['laboratory_exam_id'],
        ];
    }

    public function test_referenced_laboratory_cannot_be_deleted(): void
    {
        $item = PriceListExam::factory()->create();

        $this->expectException(QueryException::class);
        $item->laboratory->delete();
    }

    public function test_referenced_price_list_cannot_be_deleted(): void
    {
        $item = PriceListExam::factory()->create();

        $this->expectException(QueryException::class);
        $item->priceList->delete();
    }

    public function test_referenced_laboratory_exam_cannot_be_deleted(): void
    {
        $item = PriceListExam::factory()->create();

        $this->expectException(QueryException::class);
        $item->laboratoryExam->delete();
    }

    public function test_candidate_keys_and_item_unique_are_present(): void
    {
        $indexes = collect(Schema::getIndexes('price_lists'));
        $examIndexes = collect(Schema::getIndexes('laboratory_exams'));
        $itemIndexes = collect(Schema::getIndexes('price_list_exams'));

        $this->assertTrue($indexes->contains(fn (array $index): bool => $index['name'] === 'price_lists_laboratory_id_id_unique'
            && $index['unique']
            && $index['columns'] === ['laboratory_id', 'id']
        ));
        $this->assertTrue($examIndexes->contains(fn (array $index): bool => $index['name'] === 'laboratory_exams_laboratory_id_id_unique'
            && $index['unique']
            && $index['columns'] === ['laboratory_id', 'id']
        ));
        $this->assertTrue($itemIndexes->contains(fn (array $index): bool => $index['name'] === 'price_list_exams_laboratory_list_exam_unique'
            && $index['unique']
            && $index['columns'] === ['laboratory_id', 'price_list_id', 'laboratory_exam_id']
        ));
    }

    public function test_model_has_explicit_mass_assignment_decimal_cast_and_no_hidden_behavior(): void
    {
        $item = new PriceListExam;
        $item->fill([
            'id' => 999999,
            'laboratory_id' => 1,
            'price_list_id' => 2,
            'laboratory_exam_id' => 3,
            'price' => '12.34',
            'status' => PriceListExam::STATUS_ACTIVE,
            'currency' => 'GTQ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame([
            'laboratory_id',
            'price_list_id',
            'laboratory_exam_id',
            'price',
            'status',
        ], $item->getFillable());
        $this->assertSame('12.34', $item->price);
        $this->assertNull($item->getAttribute('id'));
        $this->assertNull($item->getAttribute('currency'));
        $this->assertNull($item->getAttribute('created_at'));
        $this->assertNotContains(SoftDeletes::class, class_uses_recursive(PriceListExam::class));
        $this->assertSame([], $item->getGlobalScopes());
        $this->assertSame([], $item->newModelQuery()->getEagerLoads());
    }

    public function test_default_factory_always_creates_tenant_consistent_parents(): void
    {
        $items = PriceListExam::factory()->count(20)->create();

        $this->assertTrue($items->every(fn (PriceListExam $item): bool => $item->laboratory_id === $item->priceList->laboratory_id
            && $item->laboratory_id === $item->laboratoryExam->laboratory_id
        ));
    }

    public function test_factory_supports_explicit_consistent_fixtures_and_inactive_state(): void
    {
        [$laboratory, $priceList, $exam] = $this->ownedContext();

        $attributes = $this->attributes(
            $laboratory,
            $priceList,
            $exam,
            ['price' => '32.50'],
        );
        unset($attributes['status']);

        $item = PriceListExam::factory()->inactive()->create($attributes);

        $this->assertSame($laboratory->id, $item->laboratory_id);
        $this->assertSame($priceList->id, $item->price_list_id);
        $this->assertSame($exam->id, $item->laboratory_exam_id);
        $this->assertSame('32.50', $item->price);
        $this->assertSame(PriceListExam::STATUS_INACTIVE, $item->status);
    }

    /** @return array{Laboratory, PriceList, LaboratoryExam} */
    private function ownedContext(): array
    {
        $laboratory = Laboratory::factory()->create();

        return [
            $laboratory,
            PriceList::factory()->for($laboratory)->create(),
            LaboratoryExam::factory()->for($laboratory)->create(),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function attributes(
        Laboratory $laboratory,
        PriceList $priceList,
        LaboratoryExam $exam,
        array $overrides = [],
    ): array {
        return array_merge([
            'laboratory_id' => $laboratory->id,
            'price_list_id' => $priceList->id,
            'laboratory_exam_id' => $exam->id,
            'price' => '75.00',
            'status' => PriceListExam::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }
}

<?php

namespace Tests\Feature\Models;

use App\Models\Laboratory;
use App\Models\PriceList;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PriceListPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_list_can_be_persisted_with_all_attributes(): void
    {
        $laboratory = Laboratory::factory()->create();

        $priceList = PriceList::query()->create([
            'laboratory_id' => $laboratory->id,
            'name' => 'Lista General',
            'description' => 'Precios generales del laboratorio.',
            'currency' => 'USD',
            'is_default' => true,
            'status' => PriceList::STATUS_INACTIVE,
        ])->fresh();

        $this->assertDatabaseHas('price_lists', [
            'id' => $priceList->id,
            'laboratory_id' => $laboratory->id,
            'name' => 'Lista General',
            'description' => 'Precios generales del laboratorio.',
            'currency' => 'USD',
            'is_default' => true,
            'status' => PriceList::STATUS_INACTIVE,
        ]);
        $this->assertNotNull($priceList->id);
        $this->assertTrue($priceList->is_default);
        $this->assertNotNull($priceList->created_at);
        $this->assertNotNull($priceList->updated_at);
    }

    public function test_database_defaults_and_nullable_description_are_applied_physically(): void
    {
        $laboratory = Laboratory::factory()->create();

        $id = DB::table('price_lists')->insertGetId([
            'laboratory_id' => $laboratory->id,
            'name' => 'Sin configuración explícita',
            'description' => null,
            'currency' => 'GTQ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $priceList = PriceList::query()->findOrFail($id);

        $this->assertNull($priceList->description);
        $this->assertFalse($priceList->is_default);
        $this->assertSame(PriceList::STATUS_ACTIVE, $priceList->status);
    }

    public function test_schema_contains_exactly_the_approved_columns(): void
    {
        $this->assertSame([
            'id',
            'laboratory_id',
            'name',
            'description',
            'currency',
            'is_default',
            'status',
            'created_at',
            'updated_at',
        ], Schema::getColumnListing('price_lists'));
    }

    #[DataProvider('validCurrencyProvider')]
    public function test_database_accepts_structurally_valid_currencies(string $currency): void
    {
        $priceList = PriceList::factory()->create(['currency' => $currency]);

        $this->assertSame($currency, $priceList->currency);
    }

    /** @return array<string, array{string}> */
    public static function validCurrencyProvider(): array
    {
        return [
            'quetzal' => ['GTQ'],
            'us dollar' => ['USD'],
            'euro' => ['EUR'],
        ];
    }

    #[DataProvider('invalidCurrencyProvider')]
    public function test_database_rejects_structurally_invalid_currencies(string $currency): void
    {
        $this->expectException(QueryException::class);

        PriceList::factory()->create(['currency' => $currency]);
    }

    /** @return array<string, array{string}> */
    public static function invalidCurrencyProvider(): array
    {
        return [
            'lowercase' => ['gtq'],
            'mixed case' => ['Gtq'],
            'one letter' => ['Q'],
            'two letters' => ['US'],
            'four letters' => ['USDD'],
            'numeric' => ['123'],
            'alphanumeric' => ['G1Q'],
            'empty' => [''],
            'leading whitespace' => [' GT'],
            'embedded whitespace' => ['G Q'],
            'trailing whitespace' => ['GT '],
        ];
    }

    public function test_duplicate_name_is_rejected_within_the_same_laboratory(): void
    {
        $laboratory = Laboratory::factory()->create();
        PriceList::factory()->for($laboratory)->create(['name' => 'Lista General']);

        $this->expectException(QueryException::class);
        PriceList::factory()->for($laboratory)->create(['name' => 'Lista General']);
    }

    public function test_same_name_is_allowed_across_laboratories(): void
    {
        PriceList::factory()->create(['name' => 'Lista General']);
        PriceList::factory()->create(['name' => 'Lista General']);

        $this->assertSame(2, PriceList::query()->where('name', 'Lista General')->count());
    }

    public function test_names_are_case_sensitive_within_the_same_laboratory(): void
    {
        $laboratory = Laboratory::factory()->create();
        PriceList::factory()->for($laboratory)->create(['name' => 'Lista General']);
        PriceList::factory()->for($laboratory)->create(['name' => 'lista general']);

        $this->assertSame(2, $laboratory->priceLists()->count());
    }

    public function test_second_default_is_rejected_within_the_same_laboratory(): void
    {
        $laboratory = Laboratory::factory()->create();
        PriceList::factory()->for($laboratory)->asDefault()->create();

        $this->expectException(QueryException::class);
        PriceList::factory()->for($laboratory)->asDefault()->create();
    }

    public function test_each_laboratory_can_have_its_own_default(): void
    {
        $defaultA = PriceList::factory()->asDefault()->create();
        $defaultB = PriceList::factory()->asDefault()->create();

        $this->assertNotSame($defaultA->laboratory_id, $defaultB->laboratory_id);
        $this->assertSame(2, PriceList::query()->where('is_default', true)->count());
    }

    public function test_zero_defaults_and_multiple_non_default_lists_are_allowed(): void
    {
        $laboratoryWithoutLists = Laboratory::factory()->create();
        $laboratory = Laboratory::factory()->create();
        PriceList::factory()->count(3)->for($laboratory)->create();

        $this->assertSame(0, $laboratoryWithoutLists->priceLists()->count());
        $this->assertSame(3, $laboratory->priceLists()->count());
        $this->assertSame(0, $laboratory->priceLists()->where('is_default', true)->count());
    }

    public function test_inactive_default_is_physically_allowed(): void
    {
        $priceList = PriceList::factory()->inactive()->asDefault()->create()->fresh();

        $this->assertTrue($priceList->is_default);
        $this->assertSame(PriceList::STATUS_INACTIVE, $priceList->status);
    }

    public function test_database_rejects_a_nonexistent_laboratory(): void
    {
        $this->expectException(QueryException::class);
        PriceList::factory()->create(['laboratory_id' => 999999]);
    }

    public function test_laboratory_with_a_price_list_cannot_be_deleted(): void
    {
        $laboratory = Laboratory::factory()->create();
        PriceList::factory()->for($laboratory)->create();

        $this->expectException(QueryException::class);
        $laboratory->delete();
    }

    public function test_relations_return_only_the_correct_tenant_records(): void
    {
        $laboratory = Laboratory::factory()->create();
        $priceLists = PriceList::factory()->count(2)->for($laboratory)->create();
        PriceList::factory()->create();

        $this->assertTrue($priceLists->first()->laboratory->is($laboratory));
        $this->assertEqualsCanonicalizing(
            $priceLists->modelKeys(),
            $laboratory->priceLists->modelKeys(),
        );
    }

    public function test_explicit_scope_isolates_both_laboratories_without_http_context(): void
    {
        $laboratoryA = Laboratory::factory()->create();
        $laboratoryB = Laboratory::factory()->create();
        $priceListsA = PriceList::factory()->count(2)->for($laboratoryA)->create();
        $priceListB = PriceList::factory()->for($laboratoryB)->create();
        $currentLaboratory = $this->app->make(CurrentLaboratory::class);

        $this->assertFalse($currentLaboratory->has());
        $this->assertCount(3, PriceList::query()->get());
        $this->assertEqualsCanonicalizing(
            $priceListsA->modelKeys(),
            PriceList::forLaboratory($laboratoryA)->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$priceListB->id],
            PriceList::forLaboratory($laboratoryB)->pluck('id')->all(),
        );
        $this->assertFalse($currentLaboratory->has());
    }

    public function test_model_uses_explicit_mass_assignment_boolean_cast_and_no_hidden_behavior(): void
    {
        $priceList = new PriceList;
        $priceList->fill([
            'id' => 999999,
            'laboratory_id' => 1,
            'name' => 'Lista General',
            'description' => null,
            'currency' => 'GTQ',
            'is_default' => 1,
            'status' => PriceList::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame([
            'laboratory_id',
            'name',
            'description',
            'currency',
            'is_default',
            'status',
        ], $priceList->getFillable());
        $this->assertNull($priceList->getAttribute('id'));
        $this->assertNull($priceList->getAttribute('created_at'));
        $this->assertNull($priceList->getAttribute('updated_at'));
        $this->assertTrue($priceList->is_default);
        $this->assertNotContains(SoftDeletes::class, class_uses_recursive(PriceList::class));
        $this->assertSame([], $priceList->getGlobalScopes());
        $this->assertSame([], $priceList->newModelQuery()->getEagerLoads());
        $this->assertFalse(method_exists($priceList, 'laboratoryExams'));
        $this->assertFalse(method_exists($priceList, 'commercialEntities'));
    }

    public function test_factory_defaults_and_states_are_predictable(): void
    {
        $standard = PriceList::factory()->create();
        $inactive = PriceList::factory()->inactive()->create();
        $default = PriceList::factory()->asDefault()->create();

        $this->assertSame('GTQ', $standard->currency);
        $this->assertFalse($standard->is_default);
        $this->assertSame(PriceList::STATUS_ACTIVE, $standard->status);
        $this->assertSame(PriceList::STATUS_INACTIVE, $inactive->status);
        $this->assertTrue($default->is_default);
    }

    public function test_factory_preserves_explicit_laboratory_ownership(): void
    {
        $laboratory = Laboratory::factory()->create();

        $priceList = PriceList::factory()->create(['laboratory_id' => $laboratory->id]);

        $this->assertSame($laboratory->id, $priceList->laboratory_id);
        $this->assertTrue($priceList->laboratory->is($laboratory));
    }

    public function test_factory_does_not_replace_an_existing_default(): void
    {
        $laboratory = Laboratory::factory()->create();
        $firstDefault = PriceList::factory()->for($laboratory)->asDefault()->create();

        $this->assertDatabaseHas('price_lists', [
            'id' => $firstDefault->id,
            'laboratory_id' => $laboratory->id,
            'is_default' => true,
        ]);
        $this->assertSame(1, $laboratory->priceLists()->count());

        $this->expectException(QueryException::class);
        PriceList::factory()->for($laboratory)->asDefault()->create();
    }
}

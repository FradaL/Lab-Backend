<?php

namespace Tests\Feature\Models;

use App\Models\CommercialClient;
use App\Models\CommercialClientPriceList;
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

class CommercialClientPriceListPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignment_persists_with_all_approved_attributes_and_relations(): void
    {
        [$laboratory, $client, $priceList] = $this->ownedContext();

        $assignment = CommercialClientPriceList::query()->create($this->attributes(
            $laboratory,
            $client,
            $priceList,
            [
                'starts_at' => '2026-01-01',
                'ends_at' => '2026-12-31',
                'status' => CommercialClientPriceList::STATUS_ACTIVE,
            ],
        ))->fresh();

        $this->assertDatabaseHas('commercial_client_price_lists', [
            'id' => $assignment->id,
            'laboratory_id' => $laboratory->id,
            'commercial_client_id' => $client->id,
            'price_list_id' => $priceList->id,
            'status' => CommercialClientPriceList::STATUS_ACTIVE,
        ]);
        $this->assertSame('2026-01-01', $assignment->starts_at->toDateString());
        $this->assertSame('2026-12-31', $assignment->ends_at?->toDateString());
        $this->assertTrue($assignment->laboratory->is($laboratory));
        $this->assertTrue($assignment->commercialClient->is($client));
        $this->assertTrue($assignment->priceList->is($priceList));
        $this->assertTrue($laboratory->commercialClientPriceLists->contains($assignment));
        $this->assertTrue($client->priceListAssignments->contains($assignment));
        $this->assertTrue($priceList->commercialClientAssignments->contains($assignment));
        $this->assertNotNull($assignment->created_at);
        $this->assertNotNull($assignment->updated_at);
    }

    public function test_schema_contains_exactly_the_approved_columns(): void
    {
        $this->assertSame([
            'id',
            'laboratory_id',
            'commercial_client_id',
            'price_list_id',
            'starts_at',
            'ends_at',
            'status',
            'created_at',
            'updated_at',
        ], Schema::getColumnListing('commercial_client_price_lists'));
        $this->assertNotContains('price_list_id', Schema::getColumnListing('commercial_clients'));
        $this->assertNotContains('is_default', Schema::getColumnListing('commercial_client_price_lists'));
        $this->assertNotContains('branch_id', Schema::getColumnListing('commercial_client_price_lists'));
    }

    public function test_null_end_date_persists_and_status_defaults_to_active(): void
    {
        [$laboratory, $client, $priceList] = $this->ownedContext();
        $attributes = $this->attributes($laboratory, $client, $priceList, ['ends_at' => null]);
        unset($attributes['status']);

        $id = DB::table('commercial_client_price_lists')->insertGetId($attributes);
        $assignment = CommercialClientPriceList::query()->findOrFail($id);

        $this->assertNull($assignment->ends_at);
        $this->assertSame(CommercialClientPriceList::STATUS_ACTIVE, $assignment->status);
    }

    public function test_same_day_range_is_valid_and_dates_are_cast(): void
    {
        $assignment = CommercialClientPriceList::factory()->create([
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-01-01',
        ])->fresh();

        $this->assertSame('2026-01-01', $assignment->starts_at->toDateString());
        $this->assertSame('2026-01-01', $assignment->ends_at?->toDateString());
    }

    public function test_database_rejects_an_end_date_before_the_start_date(): void
    {
        $this->expectException(QueryException::class);

        CommercialClientPriceList::factory()->create([
            'starts_at' => '2026-01-02',
            'ends_at' => '2026-01-01',
        ]);
    }

    #[DataProvider('validStatusProvider')]
    public function test_database_accepts_approved_statuses(string $status): void
    {
        $assignment = CommercialClientPriceList::factory()->create(['status' => $status]);

        $this->assertSame($status, $assignment->status);
    }

    /** @return array<string, array{string}> */
    public static function validStatusProvider(): array
    {
        return [
            'active' => [CommercialClientPriceList::STATUS_ACTIVE],
            'inactive' => [CommercialClientPriceList::STATUS_INACTIVE],
        ];
    }

    #[DataProvider('invalidStatusProvider')]
    public function test_database_rejects_unapproved_statuses(string $status): void
    {
        $this->expectException(QueryException::class);

        CommercialClientPriceList::factory()->create(['status' => $status]);
    }

    /** @return array<string, array{string}> */
    public static function invalidStatusProvider(): array
    {
        return [
            'pending' => ['pending'],
            'expired' => ['expired'],
            'enabled' => ['enabled'],
            'disabled' => ['disabled'],
            'empty' => [''],
        ];
    }

    public function test_database_rejects_a_commercial_client_from_another_tenant(): void
    {
        [$laboratory, , $priceList] = $this->ownedContext();
        [, $otherClient] = $this->ownedContext();

        $this->expectException(QueryException::class);
        CommercialClientPriceList::query()->create($this->attributes(
            $laboratory,
            $otherClient,
            $priceList,
        ));
    }

    public function test_database_rejects_a_price_list_from_another_tenant(): void
    {
        [$laboratory, $client] = $this->ownedContext();
        [, , $otherPriceList] = $this->ownedContext();

        $this->expectException(QueryException::class);
        CommercialClientPriceList::query()->create($this->attributes(
            $laboratory,
            $client,
            $otherPriceList,
        ));
    }

    public function test_database_rejects_both_parents_from_other_tenants(): void
    {
        [$laboratory] = $this->ownedContext();
        [, $otherClient] = $this->ownedContext();
        [, , $thirdPriceList] = $this->ownedContext();

        $this->expectException(QueryException::class);
        CommercialClientPriceList::query()->create($this->attributes(
            $laboratory,
            $otherClient,
            $thirdPriceList,
        ));
    }

    public function test_referenced_laboratory_cannot_be_deleted(): void
    {
        $assignment = CommercialClientPriceList::factory()->create();

        $this->expectException(QueryException::class);
        $assignment->laboratory->delete();
    }

    public function test_referenced_commercial_client_cannot_be_deleted(): void
    {
        $assignment = CommercialClientPriceList::factory()->create();

        $this->expectException(QueryException::class);
        $assignment->commercialClient->delete();
    }

    public function test_referenced_price_list_cannot_be_deleted(): void
    {
        $assignment = CommercialClientPriceList::factory()->create();

        $this->expectException(QueryException::class);
        $assignment->priceList->delete();
    }

    public function test_exact_temporal_duplicate_is_rejected(): void
    {
        [$laboratory, $client, $priceList] = $this->ownedContext();
        $attributes = $this->attributes($laboratory, $client, $priceList);
        CommercialClientPriceList::query()->create($attributes);

        $this->expectException(QueryException::class);
        CommercialClientPriceList::query()->create($attributes);
    }

    public function test_same_client_and_price_list_can_have_historical_start_dates(): void
    {
        [$laboratory, $client, $priceList] = $this->ownedContext();

        CommercialClientPriceList::query()->create($this->attributes(
            $laboratory,
            $client,
            $priceList,
            ['starts_at' => '2026-01-01', 'ends_at' => '2026-12-31'],
        ));
        CommercialClientPriceList::query()->create($this->attributes(
            $laboratory,
            $client,
            $priceList,
            ['starts_at' => '2027-01-01'],
        ));

        $this->assertSame(2, $client->priceListAssignments()->count());
    }

    public function test_same_client_can_have_multiple_price_lists_in_history(): void
    {
        [$laboratory, $client, $priceList] = $this->ownedContext();
        $otherPriceList = PriceList::factory()->for($laboratory)->create();

        CommercialClientPriceList::query()->create($this->attributes(
            $laboratory,
            $client,
            $priceList,
            ['starts_at' => '2026-01-01', 'ends_at' => '2026-12-31'],
        ));
        CommercialClientPriceList::query()->create($this->attributes(
            $laboratory,
            $client,
            $otherPriceList,
            ['starts_at' => '2027-01-01'],
        ));

        $this->assertSame(2, $client->priceListAssignments()->count());
    }

    public function test_multiple_non_overlapping_active_configurations_are_physically_allowed(): void
    {
        [$laboratory, $client, $priceList] = $this->ownedContext();
        $otherPriceList = PriceList::factory()->for($laboratory)->create();

        CommercialClientPriceList::query()->create($this->attributes(
            $laboratory,
            $client,
            $priceList,
            ['starts_at' => '2026-01-01', 'ends_at' => '2026-12-31'],
        ));
        CommercialClientPriceList::query()->create($this->attributes(
            $laboratory,
            $client,
            $otherPriceList,
            ['starts_at' => '2027-01-01'],
        ));

        $this->assertSame(2, $client->priceListAssignments()
            ->where('status', CommercialClientPriceList::STATUS_ACTIVE)
            ->count());
    }

    public function test_explicit_scope_isolates_tenants_without_http_context(): void
    {
        $laboratoryA = Laboratory::factory()->create();
        $laboratoryB = Laboratory::factory()->create();
        $assignmentsA = CommercialClientPriceList::factory()->count(2)->for($laboratoryA)->create();
        $assignmentB = CommercialClientPriceList::factory()->for($laboratoryB)->create();
        $currentLaboratory = $this->app->make(CurrentLaboratory::class);

        $this->assertFalse($currentLaboratory->has());
        $this->assertEqualsCanonicalizing(
            $assignmentsA->modelKeys(),
            CommercialClientPriceList::forLaboratory($laboratoryA)->pluck('id')->all(),
        );
        $this->assertSame(
            [$assignmentB->id],
            CommercialClientPriceList::forLaboratory($laboratoryB)->pluck('id')->all(),
        );
        $this->assertFalse($currentLaboratory->has());
    }

    public function test_model_has_explicit_mass_assignment_date_casts_and_no_hidden_behavior(): void
    {
        $assignment = new CommercialClientPriceList;
        $assignment->fill([
            'id' => 999999,
            'laboratory_id' => 1,
            'commercial_client_id' => 2,
            'price_list_id' => 3,
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-12-31',
            'status' => CommercialClientPriceList::STATUS_ACTIVE,
            'is_default' => true,
            'branch_id' => 4,
            'order_id' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame([
            'laboratory_id',
            'commercial_client_id',
            'price_list_id',
            'starts_at',
            'ends_at',
            'status',
        ], $assignment->getFillable());
        $this->assertSame('2026-01-01', $assignment->starts_at->toDateString());
        $this->assertSame('2026-12-31', $assignment->ends_at?->toDateString());
        $this->assertNull($assignment->getAttribute('id'));
        $this->assertNull($assignment->getAttribute('is_default'));
        $this->assertNull($assignment->getAttribute('branch_id'));
        $this->assertNull($assignment->getAttribute('order_id'));
        $this->assertNull($assignment->getAttribute('created_at'));
        $this->assertNull($assignment->getAttribute('updated_at'));
        $this->assertNotContains(SoftDeletes::class, class_uses_recursive(CommercialClientPriceList::class));
        $this->assertSame([], $assignment->getGlobalScopes());
        $this->assertSame([], $assignment->newModelQuery()->getEagerLoads());
    }

    public function test_factory_defaults_states_and_explicit_laboratory_are_tenant_consistent(): void
    {
        $laboratory = Laboratory::factory()->create();
        $standard = CommercialClientPriceList::factory()->for($laboratory)->create();
        $inactive = CommercialClientPriceList::factory()->inactive()->create();
        $generated = CommercialClientPriceList::factory()->count(10)->create();

        $this->assertSame($laboratory->id, $standard->laboratory_id);
        $this->assertSame('2026-01-01', $standard->starts_at->toDateString());
        $this->assertNull($standard->ends_at);
        $this->assertSame(CommercialClientPriceList::STATUS_ACTIVE, $standard->status);
        $this->assertSame(CommercialClientPriceList::STATUS_INACTIVE, $inactive->status);
        $this->assertTrue($generated->every(
            fn (CommercialClientPriceList $assignment): bool => $assignment->laboratory_id === $assignment->commercialClient->laboratory_id
                && $assignment->laboratory_id === $assignment->priceList->laboratory_id,
        ));
    }

    public function test_candidate_keys_unique_and_resolver_index_are_present(): void
    {
        $clientIndexes = collect(Schema::getIndexes('commercial_clients'));
        $priceListIndexes = collect(Schema::getIndexes('price_lists'));
        $assignmentIndexes = collect(Schema::getIndexes('commercial_client_price_lists'));

        $this->assertTrue($clientIndexes->contains(
            fn (array $index): bool => $index['name'] === 'commercial_clients_laboratory_id_id_unique'
                && $index['unique']
                && $index['columns'] === ['laboratory_id', 'id'],
        ));
        $this->assertTrue($priceListIndexes->contains(
            fn (array $index): bool => $index['name'] === 'price_lists_laboratory_id_id_unique'
                && $index['unique']
                && $index['columns'] === ['laboratory_id', 'id'],
        ));
        $this->assertTrue($assignmentIndexes->contains(
            fn (array $index): bool => $index['name'] === 'ccpl_laboratory_client_list_starts_unique'
                && $index['unique']
                && $index['columns'] === ['laboratory_id', 'commercial_client_id', 'price_list_id', 'starts_at'],
        ));
        $this->assertTrue($assignmentIndexes->contains(
            fn (array $index): bool => $index['name'] === 'ccpl_laboratory_client_status_starts_index'
                && ! $index['unique']
                && $index['columns'] === ['laboratory_id', 'commercial_client_id', 'status', 'starts_at'],
        ));
    }

    public function test_no_unapproved_direct_or_unrelated_relations_are_added(): void
    {
        $client = new CommercialClient;
        $assignment = new CommercialClientPriceList;

        $this->assertFalse(method_exists($client, 'priceList'));
        $this->assertFalse(method_exists($assignment, 'patient'));
        $this->assertFalse(method_exists($assignment, 'doctor'));
        $this->assertFalse(method_exists($assignment, 'branch'));
        $this->assertFalse(method_exists($assignment, 'order'));
    }

    /** @return array{Laboratory, CommercialClient, PriceList} */
    private function ownedContext(): array
    {
        $laboratory = Laboratory::factory()->create();

        return [
            $laboratory,
            CommercialClient::factory()->for($laboratory)->create(),
            PriceList::factory()->for($laboratory)->create(),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function attributes(
        Laboratory $laboratory,
        CommercialClient $client,
        PriceList $priceList,
        array $overrides = [],
    ): array {
        return array_merge([
            'laboratory_id' => $laboratory->id,
            'commercial_client_id' => $client->id,
            'price_list_id' => $priceList->id,
            'starts_at' => '2026-01-01',
            'ends_at' => null,
            'status' => CommercialClientPriceList::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }
}

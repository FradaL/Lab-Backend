<?php

namespace Tests\Feature\Models;

use App\Models\CommercialClient;
use App\Models\Laboratory;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CommercialClientPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_commercial_client_can_be_persisted_with_all_attributes(): void
    {
        $laboratory = Laboratory::factory()->create();
        $client = CommercialClient::query()->create([
            'laboratory_id' => $laboratory->id,
            'name' => 'Entidad Comercial Uno',
            'type' => CommercialClient::TYPE_INSURANCE,
            'tax_id' => '1234567-8',
            'phone' => '+502 2222-3333',
            'email' => 'contacto@example.test',
            'address' => 'Ciudad de Guatemala',
            'notes' => 'Observación administrativa.',
            'status' => CommercialClient::STATUS_INACTIVE,
        ])->fresh();

        $this->assertDatabaseHas('commercial_clients', [
            'id' => $client->id,
            'laboratory_id' => $laboratory->id,
            'name' => 'Entidad Comercial Uno',
            'type' => CommercialClient::TYPE_INSURANCE,
            'tax_id' => '1234567-8',
            'phone' => '+502 2222-3333',
            'email' => 'contacto@example.test',
            'address' => 'Ciudad de Guatemala',
            'notes' => 'Observación administrativa.',
            'status' => CommercialClient::STATUS_INACTIVE,
        ]);
        $this->assertTrue($client->laboratory->is($laboratory));
        $this->assertNotNull($client->created_at);
        $this->assertNotNull($client->updated_at);
    }

    public function test_minimal_record_uses_database_defaults_and_nullable_fields(): void
    {
        $laboratory = Laboratory::factory()->create();
        $id = DB::table('commercial_clients')->insertGetId([
            'laboratory_id' => $laboratory->id,
            'name' => 'Entidad Mínima',
            'type' => CommercialClient::TYPE_OTHER,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $client = CommercialClient::query()->findOrFail($id);

        $this->assertSame(CommercialClient::STATUS_ACTIVE, $client->status);
        $this->assertNull($client->tax_id);
        $this->assertNull($client->phone);
        $this->assertNull($client->email);
        $this->assertNull($client->address);
        $this->assertNull($client->notes);
    }

    public function test_schema_contains_exactly_the_approved_columns(): void
    {
        $this->assertSame([
            'id',
            'laboratory_id',
            'name',
            'type',
            'tax_id',
            'phone',
            'email',
            'address',
            'notes',
            'status',
            'created_at',
            'updated_at',
        ], Schema::getColumnListing('commercial_clients'));

        $this->assertSame([], array_values(array_intersect(
            Schema::getColumnListing('patients'),
            ['commercial_client_id', 'insurance_id', 'agreement_id', 'company_id'],
        )));
    }

    #[DataProvider('validTypeProvider')]
    public function test_all_approved_types_can_be_persisted(string $type): void
    {
        $client = CommercialClient::factory()->create(['type' => $type]);

        $this->assertSame($type, $client->type);
    }

    /** @return array<string, array{string}> */
    public static function validTypeProvider(): array
    {
        return [
            'insurance' => [CommercialClient::TYPE_INSURANCE],
            'company' => [CommercialClient::TYPE_COMPANY],
            'agreement' => [CommercialClient::TYPE_AGREEMENT],
            'other' => [CommercialClient::TYPE_OTHER],
        ];
    }

    public function test_database_rejects_an_invalid_type(): void
    {
        $this->expectException(QueryException::class);
        CommercialClient::factory()->create(['type' => 'invalid']);
    }

    #[DataProvider('validStatusProvider')]
    public function test_approved_statuses_can_be_persisted(string $status): void
    {
        $client = CommercialClient::factory()->create(['status' => $status]);

        $this->assertSame($status, $client->status);
    }

    /** @return array<string, array{string}> */
    public static function validStatusProvider(): array
    {
        return [
            'active' => [CommercialClient::STATUS_ACTIVE],
            'inactive' => [CommercialClient::STATUS_INACTIVE],
        ];
    }

    public function test_database_rejects_an_invalid_status(): void
    {
        $this->expectException(QueryException::class);
        CommercialClient::factory()->create(['status' => 'invalid']);
    }

    public function test_name_is_unique_within_a_laboratory(): void
    {
        $laboratory = Laboratory::factory()->create();
        CommercialClient::factory()->for($laboratory)->create(['name' => 'Entidad Repetida']);

        $this->expectException(QueryException::class);
        CommercialClient::factory()->for($laboratory)->create(['name' => 'Entidad Repetida']);
    }

    public function test_same_name_is_allowed_across_laboratories(): void
    {
        CommercialClient::factory()->create(['name' => 'Entidad Compartida']);
        CommercialClient::factory()->create(['name' => 'Entidad Compartida']);

        $this->assertSame(2, CommercialClient::query()->where('name', 'Entidad Compartida')->count());
    }

    public function test_names_are_case_sensitive_within_a_laboratory(): void
    {
        $laboratory = Laboratory::factory()->create();
        CommercialClient::factory()->for($laboratory)->create(['name' => 'Entidad Comercial']);
        CommercialClient::factory()->for($laboratory)->create(['name' => 'entidad comercial']);

        $this->assertSame(2, $laboratory->commercialClients()->count());
    }

    public function test_duplicate_optional_identifiers_are_allowed(): void
    {
        $laboratory = Laboratory::factory()->create();
        $duplicates = [
            'tax_id' => '1234567-8',
            'email' => 'shared@example.test',
            'phone' => '+502 2222-3333',
        ];
        CommercialClient::factory()->for($laboratory)->create($duplicates);
        CommercialClient::factory()->for($laboratory)->create($duplicates);

        $this->assertSame(2, $laboratory->commercialClients()->count());
    }

    public function test_nonexistent_laboratory_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        CommercialClient::factory()->create(['laboratory_id' => 999999]);
    }

    public function test_laboratory_with_a_commercial_client_cannot_be_deleted(): void
    {
        $laboratory = Laboratory::factory()->create();
        CommercialClient::factory()->for($laboratory)->create();

        $this->expectException(QueryException::class);
        $laboratory->delete();
    }

    public function test_explicit_scope_and_relations_isolate_tenants_without_http_context(): void
    {
        $laboratoryA = Laboratory::factory()->create();
        $laboratoryB = Laboratory::factory()->create();
        $clientsA = CommercialClient::factory()->count(2)->for($laboratoryA)->create();
        $clientB = CommercialClient::factory()->for($laboratoryB)->create();
        $currentLaboratory = $this->app->make(CurrentLaboratory::class);

        $this->assertFalse($currentLaboratory->has());
        $this->assertCount(3, CommercialClient::query()->get());
        $this->assertEqualsCanonicalizing(
            $clientsA->modelKeys(),
            CommercialClient::forLaboratory($laboratoryA)->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$clientB->id],
            CommercialClient::forLaboratory($laboratoryB)->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing($clientsA->modelKeys(), $laboratoryA->commercialClients->modelKeys());
        $this->assertTrue($clientB->laboratory->is($laboratoryB));
        $this->assertFalse($currentLaboratory->has());
    }

    public function test_model_has_explicit_mass_assignment_and_no_unapproved_behavior(): void
    {
        $client = new CommercialClient;
        $client->fill([
            'id' => 999999,
            'laboratory_id' => 1,
            'name' => 'Entidad Comercial',
            'type' => CommercialClient::TYPE_COMPANY,
            'tax_id' => null,
            'phone' => null,
            'email' => null,
            'address' => null,
            'notes' => null,
            'status' => CommercialClient::STATUS_ACTIVE,
            'price_list_id' => 100,
            'branch_id' => 200,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame([
            'laboratory_id',
            'name',
            'type',
            'tax_id',
            'phone',
            'email',
            'address',
            'notes',
            'status',
        ], $client->getFillable());
        $this->assertNull($client->getAttribute('id'));
        $this->assertNull($client->getAttribute('price_list_id'));
        $this->assertNull($client->getAttribute('branch_id'));
        $this->assertNull($client->getAttribute('created_at'));
        $this->assertNull($client->getAttribute('updated_at'));
        $this->assertNotContains(SoftDeletes::class, class_uses_recursive(CommercialClient::class));
        $this->assertSame([], $client->getGlobalScopes());
        $this->assertSame([], $client->newModelQuery()->getEagerLoads());
        $this->assertFalse(method_exists($client, 'priceList'));
        $this->assertFalse(method_exists($client, 'patients'));
        $this->assertFalse(method_exists($client, 'doctors'));
        $this->assertFalse(method_exists($client, 'branch'));
    }

    public function test_factory_defaults_states_and_explicit_laboratory_are_predictable(): void
    {
        $laboratory = Laboratory::factory()->create();
        $standard = CommercialClient::factory()->for($laboratory)->create();
        $inactive = CommercialClient::factory()->inactive()->create();
        $insurance = CommercialClient::factory()->insurance()->create();
        $company = CommercialClient::factory()->company()->create();
        $agreement = CommercialClient::factory()->agreement()->create();
        $other = CommercialClient::factory()->other()->create();

        $this->assertSame($laboratory->id, $standard->laboratory_id);
        $this->assertSame(CommercialClient::STATUS_ACTIVE, $standard->status);
        $this->assertSame(CommercialClient::STATUS_INACTIVE, $inactive->status);
        $this->assertSame(CommercialClient::TYPE_INSURANCE, $insurance->type);
        $this->assertSame(CommercialClient::TYPE_COMPANY, $company->type);
        $this->assertSame(CommercialClient::TYPE_AGREEMENT, $agreement->type);
        $this->assertSame(CommercialClient::TYPE_OTHER, $other->type);
        $this->assertSame(1, $laboratory->commercialClients()->count());
    }

    public function test_no_particular_row_is_created_implicitly(): void
    {
        CommercialClient::factory()->count(3)->create();

        $this->assertFalse(CommercialClient::query()->where('name', 'Particular')->exists());
    }
}

<?php

namespace Tests\Feature\Models;

use App\Models\Branch;
use App\Models\CommercialClient;
use App\Models\Doctor;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\Patient;
use App\Models\PriceList;
use App\Models\User;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LaboratoryOrderPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_persists_approved_fields_casts_and_relations(): void
    {
        [$laboratory, $branch, $patient, $doctor, $client, $priceList, $user] = $this->ownedContext();
        $order = LaboratoryOrder::query()->create($this->attributes(
            $laboratory,
            $branch,
            $patient,
            $doctor,
            $client,
            $priceList,
            $user,
            [
                'code' => 'ORD-2026-0001',
                'ordered_at' => '2026-10-03 14:35:27',
                'notes' => 'Persistencia clínica y comercial.',
                'subtotal' => '123456.78',
                'discount_type' => LaboratoryOrder::DISCOUNT_TYPE_AMOUNT,
                'discount_value' => '456.78',
                'discount' => '456.78',
                'taxes' => '120.00',
                'total' => '123120.00',
                'currency' => 'USD',
            ],
        ))->fresh();

        $this->assertSame('ORD-2026-0001', $order->code);
        $this->assertSame('2026-10-03 14:35:27', $order->ordered_at->format('Y-m-d H:i:s'));
        $this->assertSame(LaboratoryOrder::STATUS_PENDING, $order->status);
        $this->assertSame('123456.78', $order->subtotal);
        $this->assertSame(LaboratoryOrder::DISCOUNT_TYPE_AMOUNT, $order->discount_type);
        $this->assertSame('456.78', $order->discount_value);
        $this->assertSame('456.78', $order->discount);
        $this->assertSame('120.00', $order->taxes);
        $this->assertSame('123120.00', $order->total);
        $this->assertSame('USD', $order->currency);
        $this->assertTrue($order->laboratory->is($laboratory));
        $this->assertTrue($order->branch->is($branch));
        $this->assertTrue($order->patient->is($patient));
        $this->assertTrue($order->doctor->is($doctor));
        $this->assertTrue($order->commercialClient->is($client));
        $this->assertTrue($order->priceList->is($priceList));
        $this->assertTrue($order->createdBy->is($user));
        $this->assertTrue($laboratory->orders->contains($order));
        $this->assertTrue($branch->orders->contains($order));
        $this->assertTrue($patient->orders->contains($order));
        $this->assertTrue($doctor->orders->contains($order));
        $this->assertTrue($client->orders->contains($order));
        $this->assertTrue($priceList->orders->contains($order));
        $this->assertTrue($user->createdLaboratoryOrders->contains($order));
    }

    public function test_schema_contains_exactly_the_approved_columns(): void
    {
        $this->assertSame([
            'id',
            'laboratory_id',
            'branch_id',
            'patient_id',
            'doctor_id',
            'commercial_client_id',
            'price_list_id',
            'code',
            'ordered_at',
            'status',
            'notes',
            'subtotal',
            'discount',
            'taxes',
            'total',
            'currency',
            'created_by',
            'created_at',
            'updated_at',
            'commercial_client_name',
            'commercial_client_type',
            'price_list_name',
            'discount_type',
            'discount_value',
        ], Schema::getColumnListing('laboratory_orders'));
    }

    public function test_scope_isolates_tenants_without_http_context(): void
    {
        $laboratoryA = Laboratory::factory()->create();
        $laboratoryB = Laboratory::factory()->create();
        $ordersA = LaboratoryOrder::factory()->count(2)->for($laboratoryA)->create();
        $orderB = LaboratoryOrder::factory()->for($laboratoryB)->create();
        $currentLaboratory = $this->app->make(CurrentLaboratory::class);

        $this->assertFalse($currentLaboratory->has());
        $this->assertEqualsCanonicalizing(
            $ordersA->modelKeys(),
            LaboratoryOrder::forLaboratory($laboratoryA)->pluck('id')->all(),
        );
        $this->assertSame(
            [$orderB->id],
            LaboratoryOrder::forLaboratory($laboratoryB)->pluck('id')->all(),
        );
        $this->assertFalse($currentLaboratory->has());
    }

    #[DataProvider('tenantParentProvider')]
    public function test_database_rejects_cross_tenant_parent(string $column): void
    {
        [$laboratory, $branch, $patient, $doctor, $client, $priceList, $user] = $this->ownedContext();
        [, $otherBranch, $otherPatient, $otherDoctor, $otherClient, $otherPriceList] = $this->ownedContext();
        $foreignIds = [
            'branch_id' => $otherBranch->id,
            'patient_id' => $otherPatient->id,
            'doctor_id' => $otherDoctor->id,
            'commercial_client_id' => $otherClient->id,
            'price_list_id' => $otherPriceList->id,
        ];

        $this->expectException(QueryException::class);
        LaboratoryOrder::query()->create($this->attributes(
            $laboratory,
            $branch,
            $patient,
            $doctor,
            $client,
            $priceList,
            $user,
            [$column => $foreignIds[$column]],
        ));
    }

    /** @return array<string, array{string}> */
    public static function tenantParentProvider(): array
    {
        return [
            'branch' => ['branch_id'],
            'patient' => ['patient_id'],
            'doctor' => ['doctor_id'],
            'commercial client' => ['commercial_client_id'],
            'price list' => ['price_list_id'],
        ];
    }

    public function test_nullable_doctor_and_particular_are_valid_together(): void
    {
        $order = LaboratoryOrder::factory()->withoutDoctor()->particular()->create()->fresh();

        $this->assertNull($order->doctor_id);
        $this->assertNull($order->doctor);
        $this->assertNull($order->commercial_client_id);
        $this->assertNull($order->commercialClient);
        $this->assertFalse(CommercialClient::query()->where('name', 'Particular')->exists());
    }

    public function test_created_by_accepts_any_valid_user_without_tenant_membership(): void
    {
        $laboratory = Laboratory::factory()->create();
        $user = User::factory()->create();
        $order = LaboratoryOrder::factory()->for($laboratory)->create(['created_by' => $user->id]);

        $this->assertTrue($order->createdBy->is($user));
        $this->assertFalse($user->laboratories()->whereKey($laboratory->id)->exists());
    }

    public function test_database_rejects_nonexistent_created_by(): void
    {
        $this->expectException(QueryException::class);
        LaboratoryOrder::factory()->create(['created_by' => 999999]);
    }

    public function test_code_is_unique_within_a_laboratory(): void
    {
        $laboratory = Laboratory::factory()->create();
        LaboratoryOrder::factory()->for($laboratory)->create(['code' => 'ORD-001']);

        $this->expectException(QueryException::class);
        LaboratoryOrder::factory()->for($laboratory)->create(['code' => 'ORD-001']);
    }

    public function test_same_code_is_allowed_across_laboratories(): void
    {
        LaboratoryOrder::factory()->create(['code' => 'ORD-001']);
        LaboratoryOrder::factory()->create(['code' => 'ORD-001']);

        $this->assertSame(2, LaboratoryOrder::query()->where('code', 'ORD-001')->count());
    }

    #[DataProvider('invalidStatusProvider')]
    public function test_database_rejects_unsupported_statuses(string $status): void
    {
        $this->expectException(QueryException::class);
        LaboratoryOrder::factory()->create(['status' => $status]);
    }

    /** @return array<string, array{string}> */
    public static function invalidStatusProvider(): array
    {
        return [
            'draft' => ['draft'],
            'paid' => ['paid'],
            'processing' => ['processing'],
            'done' => ['done'],
            'foo' => ['foo'],
        ];
    }

    #[DataProvider('validStatusProvider')]
    public function test_database_accepts_each_workflow_status(string $status): void
    {
        $order = LaboratoryOrder::factory()->create(['status' => $status]);

        $this->assertSame($status, $order->status);
    }

    /** @return array<string, array{string}> */
    public static function validStatusProvider(): array
    {
        return [
            'pending' => [LaboratoryOrder::STATUS_PENDING],
            'in process' => [LaboratoryOrder::STATUS_IN_PROCESS],
            'completed' => [LaboratoryOrder::STATUS_COMPLETED],
            'cancelled' => [LaboratoryOrder::STATUS_CANCELLED],
        ];
    }

    public function test_database_defaults_status_and_money_to_initial_values(): void
    {
        [$laboratory, $branch, $patient, , , $priceList, $user] = $this->ownedContext();
        $attributes = $this->attributes(
            $laboratory,
            $branch,
            $patient,
            null,
            null,
            $priceList,
            $user,
        );
        unset(
            $attributes['status'],
            $attributes['subtotal'],
            $attributes['discount'],
            $attributes['taxes'],
            $attributes['total'],
        );

        $order = LaboratoryOrder::query()->findOrFail(
            DB::table('laboratory_orders')->insertGetId($attributes),
        );

        $this->assertSame(LaboratoryOrder::STATUS_PENDING, $order->status);
        $this->assertSame('0.00', $order->subtotal);
        $this->assertNull($order->discount_type);
        $this->assertNull($order->discount_value);
        $this->assertSame('0.00', $order->discount);
        $this->assertSame('0.00', $order->taxes);
        $this->assertSame('0.00', $order->total);
    }

    #[DataProvider('negativeMoneyProvider')]
    public function test_database_rejects_each_negative_money_value(string $column): void
    {
        $this->expectException(QueryException::class);
        LaboratoryOrder::factory()->create([$column => '-0.01']);
    }

    /** @return array<string, array{string}> */
    public static function negativeMoneyProvider(): array
    {
        return [
            'subtotal' => ['subtotal'],
            'discount' => ['discount'],
            'taxes' => ['taxes'],
            'total' => ['total'],
        ];
    }

    #[DataProvider('validCurrencyProvider')]
    public function test_structurally_valid_currencies_are_accepted(string $currency): void
    {
        $order = LaboratoryOrder::factory()->create(['currency' => $currency]);

        $this->assertSame($currency, $order->currency);
    }

    /** @return array<string, array{string}> */
    public static function validCurrencyProvider(): array
    {
        return ['GTQ' => ['GTQ'], 'USD' => ['USD']];
    }

    #[DataProvider('invalidCurrencyProvider')]
    public function test_database_rejects_structurally_invalid_currency(string $currency): void
    {
        $this->expectException(QueryException::class);
        LaboratoryOrder::factory()->create(['currency' => $currency]);
    }

    /** @return array<string, array{string}> */
    public static function invalidCurrencyProvider(): array
    {
        return [
            'lowercase' => ['gtq'],
            'too short' => ['G'],
            'too long' => ['GTQQ'],
            'numeric' => ['12Q'],
        ];
    }

    #[DataProvider('restrictedParentProvider')]
    public function test_referenced_parents_cannot_be_hard_deleted(string $relation): void
    {
        $order = LaboratoryOrder::factory()->create();
        $parent = $order->getRelationValue($relation);

        $this->expectException(QueryException::class);
        $parent->delete();
    }

    /** @return array<string, array{string}> */
    public static function restrictedParentProvider(): array
    {
        return [
            'laboratory' => ['laboratory'],
            'branch' => ['branch'],
            'patient' => ['patient'],
            'doctor' => ['doctor'],
            'commercial client' => ['commercialClient'],
            'price list' => ['priceList'],
            'created by' => ['createdBy'],
        ];
    }

    public function test_model_has_explicit_mass_assignment_casts_and_no_hidden_tenancy(): void
    {
        $order = new LaboratoryOrder;
        $order->fill([
            'id' => 999999,
            'laboratory_id' => 1,
            'branch_id' => 2,
            'patient_id' => 3,
            'doctor_id' => null,
            'commercial_client_id' => null,
            'price_list_id' => 4,
            'code' => 'ORD-001',
            'ordered_at' => '2026-10-03 14:35:27',
            'status' => LaboratoryOrder::STATUS_PENDING,
            'notes' => null,
            'subtotal' => '1.20',
            'discount_type' => LaboratoryOrder::DISCOUNT_TYPE_PERCENTAGE,
            'discount_value' => '10.00',
            'discount' => '0.10',
            'taxes' => '0.05',
            'total' => '1.15',
            'currency' => 'GTQ',
            'created_by' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame([
            'laboratory_id',
            'branch_id',
            'patient_id',
            'doctor_id',
            'commercial_client_id',
            'commercial_client_name',
            'commercial_client_type',
            'price_list_id',
            'price_list_name',
            'code',
            'ordered_at',
            'status',
            'notes',
            'subtotal',
            'discount',
            'discount_type',
            'discount_value',
            'taxes',
            'total',
            'currency',
            'created_by',
        ], $order->getFillable());
        $this->assertNull($order->getAttribute('id'));
        $this->assertNull($order->getAttribute('created_at'));
        $this->assertNull($order->getAttribute('updated_at'));
        $this->assertSame('1.20', $order->subtotal);
        $this->assertSame('10.00', $order->discount_value);
        $this->assertSame('0.10', $order->discount);
        $this->assertNotContains(SoftDeletes::class, class_uses_recursive(LaboratoryOrder::class));
        $this->assertSame([], $order->getGlobalScopes());
        $this->assertSame([], $order->newModelQuery()->getEagerLoads());
        $source = file_get_contents(app_path('Models/LaboratoryOrder.php'));
        $factorySource = file_get_contents(database_path('factories/LaboratoryOrderFactory.php'));
        $this->assertStringNotContainsString('CurrentLaboratory', $source);
        $this->assertStringNotContainsString('CurrentLaboratory', $factorySource);
    }

    public function test_factory_defaults_states_and_tenant_consistency(): void
    {
        $orders = LaboratoryOrder::factory()->count(10)->create();
        $particular = LaboratoryOrder::factory()->particular()->create();
        $withoutDoctor = LaboratoryOrder::factory()->withoutDoctor()->create();
        $usdPriceList = PriceList::factory()->create(['currency' => 'USD']);
        $usdOrder = LaboratoryOrder::factory()->create([
            'laboratory_id' => $usdPriceList->laboratory_id,
            'price_list_id' => $usdPriceList->id,
        ]);

        $this->assertTrue($orders->every(fn (LaboratoryOrder $order): bool => $order->laboratory_id === $order->branch->laboratory_id
            && $order->laboratory_id === $order->patient->laboratory_id
            && $order->laboratory_id === $order->doctor->laboratory_id
            && $order->laboratory_id === $order->commercialClient->laboratory_id
            && $order->laboratory_id === $order->priceList->laboratory_id
            && $order->commercial_client_name === $order->commercialClient->name
            && $order->commercial_client_type === $order->commercialClient->type
            && $order->price_list_name === $order->priceList->name
            && $order->currency === $order->priceList->currency
            && $order->status === LaboratoryOrder::STATUS_PENDING
            && $order->subtotal === '0.00'
            && $order->discount_type === null
            && $order->discount_value === null
            && $order->discount === '0.00'
            && $order->taxes === '0.00'
            && $order->total === '0.00'
        ));
        $this->assertNull($particular->commercial_client_id);
        $this->assertNull($particular->commercial_client_name);
        $this->assertNull($particular->commercial_client_type);
        $this->assertNull($withoutDoctor->doctor_id);
        $this->assertSame('USD', $usdOrder->currency);
    }

    public function test_candidate_keys_and_query_indexes_are_present(): void
    {
        foreach (['branches', 'patients', 'doctors', 'commercial_clients', 'price_lists'] as $table) {
            $expected = $table.'_laboratory_id_id_unique';
            $this->assertTrue(collect(Schema::getIndexes($table))->contains(
                fn (array $index): bool => $index['name'] === $expected
                    && $index['unique']
                    && $index['columns'] === ['laboratory_id', 'id'],
            ));
        }

        $indexes = collect(Schema::getIndexes('laboratory_orders'));
        foreach ([
            'laboratory_orders_laboratory_code_unique',
            'laboratory_orders_laboratory_status_index',
            'laboratory_orders_laboratory_ordered_at_index',
            'laboratory_orders_laboratory_patient_ordered_at_index',
        ] as $name) {
            $this->assertTrue($indexes->contains(fn (array $index): bool => $index['name'] === $name));
        }
    }

    /**
     * @return array{Laboratory, Branch, Patient, Doctor, CommercialClient, PriceList, User}
     */
    private function ownedContext(): array
    {
        $laboratory = Laboratory::factory()->create();

        return [
            $laboratory,
            Branch::factory()->for($laboratory)->create(),
            Patient::factory()->for($laboratory)->create(),
            Doctor::factory()->for($laboratory)->create(),
            CommercialClient::factory()->for($laboratory)->create(),
            PriceList::factory()->for($laboratory)->create(),
            User::factory()->create(),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function attributes(
        Laboratory $laboratory,
        Branch $branch,
        Patient $patient,
        ?Doctor $doctor,
        ?CommercialClient $client,
        PriceList $priceList,
        User $user,
        array $overrides = [],
    ): array {
        return array_merge([
            'laboratory_id' => $laboratory->id,
            'branch_id' => $branch->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor?->id,
            'commercial_client_id' => $client?->id,
            'commercial_client_name' => $client?->name,
            'commercial_client_type' => $client?->type,
            'price_list_id' => $priceList->id,
            'price_list_name' => $priceList->name,
            'code' => 'ORD-BASE-001',
            'ordered_at' => '2026-10-03 09:15:42',
            'status' => LaboratoryOrder::STATUS_PENDING,
            'notes' => null,
            'subtotal' => '0.00',
            'discount_type' => null,
            'discount_value' => null,
            'discount' => '0.00',
            'taxes' => '0.00',
            'total' => '0.00',
            'currency' => $priceList->currency,
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }
}

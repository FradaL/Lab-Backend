<?php

namespace Tests\Feature\Api\V1\LaboratoryOrders;

use App\Models\Branch;
use App\Models\CommercialClient;
use App\Models\Doctor;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Models\Patient;
use App\Models\PriceList;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class LaboratoryOrderFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC'));
    }

    public function test_date_filters_are_independent_half_open_and_include_the_complete_day(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $before = $this->order($user, $laboratory, ['ordered_at' => '2026-09-30 23:59:59']);
        $first = $this->order($user, $laboratory, ['ordered_at' => '2026-10-01 00:00:00']);
        $last = $this->order($user, $laboratory, ['ordered_at' => '2026-10-05 23:59:59']);
        $after = $this->order($user, $laboratory, ['ordered_at' => '2026-10-06 00:00:00']);

        $this->indexRequest($user, $laboratory, ['date_from' => '2026-10-01'])
            ->assertOk()->assertJsonPath('data.*.id', [$after->id, $last->id, $first->id]);
        $this->indexRequest($user, $laboratory, ['date_to' => '2026-10-05'])
            ->assertOk()->assertJsonPath('data.*.id', [$last->id, $first->id, $before->id]);
        $this->indexRequest($user, $laboratory, [
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-05',
        ])->assertOk()->assertJsonPath('data.*.id', [$last->id, $first->id]);
        $this->indexRequest($user, $laboratory, [
            'date_from' => '2026-10-05',
            'date_to' => '2026-10-05',
        ])->assertOk()->assertJsonPath('data.*.id', [$last->id]);
    }

    public function test_invalid_and_inverted_date_ranges_are_rejected(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        foreach (['2026/10/05', '05-10-2026', '2026-13-01', 'texto', '2026-10-05 00:00:00', ' 2026-10-05 '] as $date) {
            $this->indexRequest($user, $laboratory, ['date_from' => $date])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['date_from']);
        }

        $this->indexRequest($user, $laboratory, [
            'date_from' => '2026-10-10',
            'date_to' => '2026-10-01',
        ])->assertUnprocessable()->assertJsonValidationErrors(['date_to']);
    }

    public function test_status_uses_every_canonical_value_and_rejects_other_or_multiple_values(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        foreach (LaboratoryOrder::STATUSES as $index => $status) {
            $order = $this->order($user, $laboratory, [
                'status' => $status,
                'ordered_at' => '2026-10-0'.($index + 1).' 08:00:00',
            ]);

            $this->indexRequest($user, $laboratory, ['status' => $status])
                ->assertOk()
                ->assertJsonPath('data.*.id', [$order->id]);
        }

        foreach (['draft', 'paid', 'processing', '', ' pending '] as $status) {
            $this->indexRequest($user, $laboratory, ['status' => $status])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['status']);
        }
        $this->indexRequest($user, $laboratory, ['status' => ['pending', 'completed']])
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);
    }

    public function test_exact_foreign_key_filters_include_inactive_historical_entities(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $branch = Branch::factory()->for($laboratory)->create(['status' => 'inactive']);
        $doctor = Doctor::factory()->for($laboratory)->create(['status' => Doctor::STATUS_INACTIVE]);
        $client = CommercialClient::factory()->inactive()->for($laboratory)->create();
        $priceList = PriceList::factory()->inactive()->for($laboratory)->create();
        $matching = $this->order($user, $laboratory, [
            'branch_id' => $branch->id,
            'doctor_id' => $doctor->id,
            'commercial_client_id' => $client->id,
            'price_list_id' => $priceList->id,
        ]);
        $this->order($user, $laboratory);

        foreach ([
            'branch_id' => $branch->id,
            'doctor_id' => $doctor->id,
            'commercial_client_id' => $client->id,
            'price_list_id' => $priceList->id,
        ] as $filter => $id) {
            $this->indexRequest($user, $laboratory, [$filter => $id])
                ->assertOk()
                ->assertJsonPath('data.*.id', [$matching->id]);
        }
    }

    public function test_foreign_key_filters_reject_cross_tenant_and_missing_ids(): void
    {
        $user = User::factory()->create();
        [, $laboratoryA] = $this->activeTenant($user);
        [, $laboratoryB] = $this->activeTenant($user);
        $foreignIds = [
            'branch_id' => Branch::factory()->for($laboratoryB)->create()->id,
            'doctor_id' => Doctor::factory()->for($laboratoryB)->create()->id,
            'commercial_client_id' => CommercialClient::factory()->for($laboratoryB)->create()->id,
            'price_list_id' => PriceList::factory()->for($laboratoryB)->create()->id,
        ];

        foreach ($foreignIds as $filter => $id) {
            $this->indexRequest($user, $laboratoryA, [$filter => $id])
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$filter]);
            $this->indexRequest($user, $laboratoryA, [$filter => 999999])
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$filter]);
        }
    }

    public function test_foreign_key_filters_require_positive_scalar_integers(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        foreach (['branch_id', 'doctor_id', 'commercial_client_id', 'price_list_id'] as $filter) {
            foreach ([0, -1, 'abc', 1.5, [1]] as $value) {
                $this->indexRequest($user, $laboratory, [$filter => $value])
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors([$filter]);
            }
        }
    }

    public function test_commercial_context_is_explicit_composable_and_empty_means_omitted(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $clientA = CommercialClient::factory()->for($laboratory)->create();
        $clientB = CommercialClient::factory()->for($laboratory)->create();
        $particular = $this->order($user, $laboratory, [
            'commercial_client_id' => null,
            'commercial_client_name' => null,
            'commercial_client_type' => null,
            'ordered_at' => '2026-10-03 08:00:00',
        ]);
        $clientOrderA = $this->order($user, $laboratory, [
            'commercial_client_id' => $clientA->id,
            'ordered_at' => '2026-10-02 08:00:00',
        ]);
        $clientOrderB = $this->order($user, $laboratory, [
            'commercial_client_id' => $clientB->id,
            'ordered_at' => '2026-10-01 08:00:00',
        ]);

        $this->indexRequest($user, $laboratory, ['commercial_context' => 'particular'])
            ->assertOk()->assertJsonPath('data.*.id', [$particular->id]);
        $this->indexRequest($user, $laboratory, ['commercial_context' => 'client'])
            ->assertOk()->assertJsonPath('data.*.id', [$clientOrderA->id, $clientOrderB->id]);
        $this->indexRequest($user, $laboratory, ['commercial_context' => 'client', 'commercial_client_id' => $clientA->id])
            ->assertOk()->assertJsonPath('data.*.id', [$clientOrderA->id]);
        $this->indexRequest($user, $laboratory, ['commercial_client_id' => $clientB->id])
            ->assertOk()->assertJsonPath('data.*.id', [$clientOrderB->id]);
        $this->indexRequest($user, $laboratory, ['commercial_context' => ''])
            ->assertOk()->assertJsonCount(3, 'data');

        $this->indexRequest($user, $laboratory, ['commercial_context' => 'particular', 'commercial_client_id' => $clientA->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['commercial_client_id']);
        $this->indexRequest($user, $laboratory, ['commercial_context' => 'other'])
            ->assertUnprocessable()->assertJsonValidationErrors(['commercial_context']);
        $this->indexRequest($user, $laboratory, ['commercial_context' => ' client '])
            ->assertUnprocessable()->assertJsonValidationErrors(['commercial_context']);
        $this->indexRequest($user, $laboratory, ['commercial_context' => ['client']])
            ->assertUnprocessable()->assertJsonValidationErrors(['commercial_context']);
    }

    public function test_search_and_all_compatible_filters_compose_with_and_semantics(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $branch = Branch::factory()->for($laboratory)->create();
        $doctor = Doctor::factory()->for($laboratory)->create();
        $client = CommercialClient::factory()->for($laboratory)->create();
        $priceList = PriceList::factory()->for($laboratory)->create();
        $patient = Patient::factory()->for($laboratory)->create([
            'first_names' => 'Ana María',
            'last_names' => 'López',
        ]);
        $matching = $this->order($user, $laboratory, [
            'branch_id' => $branch->id,
            'doctor_id' => $doctor->id,
            'commercial_client_id' => $client->id,
            'price_list_id' => $priceList->id,
            'patient_id' => $patient->id,
            'status' => LaboratoryOrder::STATUS_PENDING,
            'ordered_at' => '2026-10-03 08:00:00',
        ]);
        $this->order($user, $laboratory, [
            'branch_id' => $branch->id,
            'doctor_id' => $doctor->id,
            'commercial_client_id' => $client->id,
            'price_list_id' => $priceList->id,
            'patient_id' => $patient->id,
            'status' => LaboratoryOrder::STATUS_COMPLETED,
            'ordered_at' => '2026-10-03 08:00:00',
        ]);

        $this->indexRequest($user, $laboratory, [
            'search' => 'Ana López',
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-05',
            'status' => LaboratoryOrder::STATUS_PENDING,
            'branch_id' => $branch->id,
            'doctor_id' => $doctor->id,
            'commercial_client_id' => $client->id,
            'commercial_context' => 'client',
            'price_list_id' => $priceList->id,
        ])->assertOk()->assertJsonPath('data.*.id', [$matching->id]);

        $this->indexRequest($user, $laboratory, ['search' => 'Ana', 'status' => LaboratoryOrder::STATUS_COMPLETED])
            ->assertOk()->assertJsonCount(1, 'data');
        $this->indexRequest($user, $laboratory, ['search' => 'Ana', 'branch_id' => $branch->id])
            ->assertOk()->assertJsonCount(2, 'data');
        $this->indexRequest($user, $laboratory, ['search' => 'Ana', 'date_from' => '2026-10-04'])
            ->assertOk()->assertJsonPath('data', []);
    }

    public function test_particular_combines_with_status_branch_pagination_links_and_fixed_ordering(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $branch = Branch::factory()->for($laboratory)->create();
        $orders = [];
        foreach (range(1, 3) as $day) {
            $orders[] = $this->order($user, $laboratory, [
                'branch_id' => $branch->id,
                'commercial_client_id' => null,
                'commercial_client_name' => null,
                'commercial_client_type' => null,
                'status' => LaboratoryOrder::STATUS_PENDING,
                'ordered_at' => "2026-10-0{$day} 08:00:00",
            ]);
        }
        $this->order($user, $laboratory, [
            'branch_id' => $branch->id,
            'commercial_client_id' => null,
            'commercial_client_name' => null,
            'commercial_client_type' => null,
            'status' => LaboratoryOrder::STATUS_COMPLETED,
        ]);

        $response = $this->indexRequest($user, $laboratory, [
            'commercial_context' => 'particular',
            'status' => LaboratoryOrder::STATUS_PENDING,
            'branch_id' => $branch->id,
            'page' => 1,
            'per_page' => 2,
        ])->assertOk();

        $this->assertSame([$orders[2]->id, $orders[1]->id], $response->json('data.*.id'));
        $this->assertSame(3, $response->json('meta.total'));
        parse_str(parse_url($response->json('links.next'), PHP_URL_QUERY), $nextQuery);
        $this->assertSame('particular', $nextQuery['commercial_context']);
        $this->assertSame('pending', $nextQuery['status']);
        $this->assertSame((string) $branch->id, $nextQuery['branch_id']);
        $this->assertSame('2', $nextQuery['per_page']);
    }

    public function test_filters_preserve_response_snapshots_exam_count_and_constant_queries(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $branch = Branch::factory()->for($laboratory)->create();
        $doctor = Doctor::factory()->for($laboratory)->create();
        $client = CommercialClient::factory()->for($laboratory)->create(['name' => 'Cliente Histórico']);
        $priceList = PriceList::factory()->for($laboratory)->create();
        $order = $this->order($user, $laboratory, [
            'branch_id' => $branch->id,
            'doctor_id' => $doctor->id,
            'commercial_client_id' => $client->id,
            'commercial_client_name' => 'Cliente Histórico',
            'price_list_id' => $priceList->id,
            'currency' => 'GTQ',
            'total' => '125.50',
        ]);
        LaboratoryOrderExam::factory()->count(2)->for($laboratory)->create([
            'laboratory_order_id' => $order->id,
            'price_list_id' => $priceList->id,
        ]);
        $client->update(['name' => 'Cliente Actual']);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $response = $this->indexRequest($user, $laboratory, [
            'branch_id' => $branch->id,
            'doctor_id' => $doctor->id,
            'commercial_client_id' => $client->id,
            'price_list_id' => $priceList->id,
        ])->assertOk()
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.commercial_client.name', 'Cliente Histórico')
            ->assertJsonPath('data.0.exam_count', 2)
            ->assertJsonPath('data.0.currency', 'GTQ')
            ->assertJsonPath('data.0.total', '125.50');

        $this->assertSame([
            'id', 'code', 'ordered_at', 'status', 'branch', 'patient', 'doctor',
            'commercial_client', 'exam_count', 'currency', 'total', 'created_by',
        ], array_keys($response->json('data.0')));
        $this->assertCount(2, collect($queries)->filter(
            fn (string $sql): bool => str_contains($sql, 'from "laboratory_orders"'),
        ));
        foreach (['commercial_clients', 'price_lists'] as $table) {
            $catalogQueries = collect($queries)->filter(
                fn (string $sql): bool => str_contains($sql, "from \"{$table}\""),
            );
            $this->assertCount(1, $catalogQueries);
            $this->assertStringContainsString('count(*)', $catalogQueries->first());
        }
        foreach (['price_list_exams', 'laboratory_exams'] as $table) {
            $this->assertFalse(collect($queries)->contains(
                fn (string $sql): bool => str_contains($sql, "from \"{$table}\""),
            ));
        }
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

        $this->assignAllOrderPermissions($user, $laboratory);

        return [$user, $laboratory];
    }

    /** @param array<string, mixed> $attributes */
    private function order(User $user, Laboratory $laboratory, array $attributes = []): LaboratoryOrder
    {
        return LaboratoryOrder::factory()->for($laboratory)->create(array_replace([
            'created_by' => $user->id,
        ], $attributes));
    }

    /** @param array<string, mixed> $query */
    private function indexRequest(User $user, Laboratory $laboratory, array $query = []): TestResponse
    {
        $uri = '/api/v1/laboratory-orders?'.http_build_query($query);

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson($uri);
    }
}

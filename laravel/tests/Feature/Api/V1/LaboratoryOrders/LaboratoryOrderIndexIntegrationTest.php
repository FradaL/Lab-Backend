<?php

namespace Tests\Feature\Api\V1\LaboratoryOrders;

use App\Models\Branch;
use App\Models\CommercialClient;
use App\Models\Doctor;
use App\Models\Laboratory;
use App\Models\LaboratoryExam;
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

final class LaboratoryOrderIndexIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'UTC'));
    }

    public function test_realistic_multi_tenant_query_preserves_history_current_labels_and_read_only_state(): void
    {
        $user = User::factory()->create(['name' => 'Recepción Histórica']);
        [, $laboratoryA] = $this->activeTenant($user);
        [, $laboratoryB] = $this->activeTenant($user);
        $branch = Branch::factory()->for($laboratoryA)->create(['name' => 'Central']);
        $otherBranch = Branch::factory()->for($laboratoryA)->create(['name' => 'Norte']);
        $patient = Patient::factory()->for($laboratoryA)->create([
            'first_names' => 'Ana María',
            'last_names' => 'López García',
        ]);
        $doctor = Doctor::factory()->for($laboratoryA)->create([
            'first_names' => 'Doctor',
            'last_names' => 'Histórico',
        ]);
        $client = CommercialClient::factory()->company()->for($laboratoryA)->create([
            'name' => 'Empresa Actual Inicial',
        ]);
        $priceList = PriceList::factory()->for($laboratoryA)->create(['name' => 'Convenio A']);
        $target = $this->order($user, $laboratoryA, [
            'branch_id' => $branch->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'commercial_client_id' => $client->id,
            'commercial_client_name' => 'Empresa Histórica',
            'commercial_client_type' => CommercialClient::TYPE_COMPANY,
            'price_list_id' => $priceList->id,
            'price_list_name' => 'Convenio Histórico',
            'code' => 'ORD-2026-ANA-001',
            'status' => LaboratoryOrder::STATUS_PENDING,
            'ordered_at' => '2026-10-05 23:59:59',
            'currency' => 'GTQ',
            'total' => '321.45',
        ]);
        $this->order($user, $laboratoryA, [
            'branch_id' => $branch->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'commercial_client_id' => $client->id,
            'price_list_id' => $priceList->id,
            'code' => 'ORD-2026-ANA-COMPLETED',
            'status' => LaboratoryOrder::STATUS_COMPLETED,
            'ordered_at' => '2026-10-05 10:00:00',
        ]);
        $this->order($user, $laboratoryA, [
            'branch_id' => $otherBranch->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'commercial_client_id' => $client->id,
            'price_list_id' => $priceList->id,
            'code' => 'ORD-2026-ANA-NORTE',
            'status' => LaboratoryOrder::STATUS_PENDING,
            'ordered_at' => '2026-10-05 11:00:00',
        ]);
        $exam = LaboratoryExam::factory()->for($laboratoryA)->create(['name' => 'Glucosa']);
        LaboratoryOrderExam::factory()->count(3)->for($laboratoryA)->create([
            'laboratory_order_id' => $target->id,
            'laboratory_exam_id' => $exam->id,
            'price_list_id' => $priceList->id,
        ]);

        $foreignPatient = Patient::factory()->for($laboratoryB)->create([
            'first_names' => 'Ana María',
            'last_names' => 'López García',
        ]);
        $foreignOrder = $this->order($user, $laboratoryB, [
            'patient_id' => $foreignPatient->id,
            'code' => 'ORD-2026-ANA-001',
            'status' => LaboratoryOrder::STATUS_PENDING,
            'ordered_at' => '2026-10-05 23:59:59',
        ]);

        $branch->update(['name' => 'Central Actual', 'status' => 'inactive']);
        $patient->update(['first_names' => 'Ana Actual', 'last_names' => 'López Renovada']);
        $doctor->update([
            'first_names' => 'Doctora Actual',
            'last_names' => 'Renovada',
            'status' => Doctor::STATUS_INACTIVE,
        ]);
        $client->update([
            'name' => 'Empresa Actual',
            'type' => CommercialClient::TYPE_INSURANCE,
            'status' => CommercialClient::STATUS_INACTIVE,
        ]);
        $priceList->update(['name' => 'Convenio Actual', 'status' => PriceList::STATUS_INACTIVE]);
        $exam->update(['name' => 'Glucosa Actual', 'status' => LaboratoryExam::STATUS_INACTIVE]);
        $user->update(['name' => 'Recepción Actual']);

        $trackedTables = [
            'laboratory_orders', 'laboratory_order_exams', 'patients', 'doctors',
            'branches', 'commercial_clients', 'price_lists', 'price_list_exams',
            'laboratory_exams',
        ];
        $before = $this->tableSnapshots($trackedTables);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $response = $this->indexRequest($user, $laboratoryA, [
            'search' => 'Ana Actual López',
            'date_from' => '2026-10-05',
            'date_to' => '2026-10-05',
            'status' => LaboratoryOrder::STATUS_PENDING,
            'branch_id' => $branch->id,
            'doctor_id' => $doctor->id,
            'commercial_client_id' => $client->id,
            'commercial_context' => 'client',
            'price_list_id' => $priceList->id,
            'per_page' => 10,
        ])->assertOk()->assertJsonPath('data.*.id', [$target->id]);
        $requestQueries = $queries;

        $response
            ->assertJsonPath('data.0.branch.name', 'Central Actual')
            ->assertJsonPath('data.0.patient.first_names', 'Ana Actual')
            ->assertJsonPath('data.0.patient.last_names', 'López Renovada')
            ->assertJsonPath('data.0.doctor.first_names', 'Doctora Actual')
            ->assertJsonPath('data.0.doctor.last_names', 'Renovada')
            ->assertJsonPath('data.0.created_by.name', 'Recepción Actual')
            ->assertJsonPath('data.0.commercial_client.name', 'Empresa Histórica')
            ->assertJsonPath('data.0.commercial_client.type', CommercialClient::TYPE_COMPANY)
            ->assertJsonPath('data.0.exam_count', 3)
            ->assertJsonPath('data.0.currency', 'GTQ')
            ->assertJsonPath('data.0.total', '321.45');
        $this->assertSame($before, $this->tableSnapshots($trackedTables));

        $orderQueries = collect($requestQueries)->filter(
            fn (string $sql): bool => str_contains($sql, 'from "laboratory_orders"'),
        );
        $this->assertCount(2, $orderQueries);
        $pageQuery = $orderQueries->first(fn (string $sql): bool => str_contains($sql, 'order by'));
        $this->assertNotNull($pageQuery);
        $this->assertMatchesRegularExpression(
            '/"laboratory_id" = \? and \("code" (?:like|ilike) \? escape \'!\' or exists/',
            $pageQuery,
        );
        foreach (['status', 'branch_id', 'doctor_id', 'commercial_client_id', 'price_list_id'] as $column) {
            $this->assertStringContainsString("\"{$column}\" = ?", $pageQuery);
        }
        $this->assertStringNotContainsString(' join ', $pageQuery);
        $this->assertStringNotContainsString('distinct', $pageQuery);
        $this->assertFalse(collect($requestQueries)->contains(
            fn (string $sql): bool => preg_match('/\A(insert|update|delete)\b/', ltrim($sql)) === 1,
        ));
        foreach (['commercial_clients', 'price_lists'] as $table) {
            $catalogQueries = collect($requestQueries)->filter(
                fn (string $sql): bool => str_contains($sql, "from \"{$table}\""),
            );
            $this->assertCount(1, $catalogQueries);
            $this->assertStringContainsString('count(*)', $catalogQueries->first());
        }
        foreach (['price_list_exams', 'laboratory_exams'] as $table) {
            $this->assertFalse(collect($requestQueries)->contains(
                fn (string $sql): bool => str_contains($sql, "from \"{$table}\""),
            ));
        }

        $this->indexRequest($user, $laboratoryB, ['search' => 'Ana'])
            ->assertOk()
            ->assertJsonPath('data.*.id', [$foreignOrder->id]);
    }

    public function test_particular_search_filters_paginate_without_duplicates_and_terminal_states_remain_visible(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $branch = Branch::factory()->for($laboratory)->create();
        $patient = Patient::factory()->for($laboratory)->create([
            'first_names' => 'Ana',
            'last_names' => 'Particular',
        ]);
        $orders = [];
        $timestamps = [
            '2026-10-01 08:00:00',
            '2026-10-02 08:00:00',
            '2026-10-03 08:00:00',
            '2026-10-03 08:00:00',
            '2026-10-04 08:00:00',
        ];
        foreach ($timestamps as $index => $orderedAt) {
            $orders[] = $this->order($user, $laboratory, [
                'branch_id' => $branch->id,
                'patient_id' => $patient->id,
                'commercial_client_id' => null,
                'commercial_client_name' => null,
                'commercial_client_type' => null,
                'code' => "ORD-ANA-PARTICULAR-{$index}",
                'status' => LaboratoryOrder::STATUS_PENDING,
                'ordered_at' => $orderedAt,
            ]);
        }
        $client = CommercialClient::factory()->for($laboratory)->create();
        $this->order($user, $laboratory, [
            'branch_id' => $branch->id,
            'patient_id' => $patient->id,
            'commercial_client_id' => $client->id,
            'status' => LaboratoryOrder::STATUS_PENDING,
            'ordered_at' => '2026-10-05 08:00:00',
        ]);
        $completed = $this->order($user, $laboratory, [
            'patient_id' => $patient->id,
            'status' => LaboratoryOrder::STATUS_COMPLETED,
        ]);
        $cancelled = $this->order($user, $laboratory, [
            'patient_id' => $patient->id,
            'status' => LaboratoryOrder::STATUS_CANCELLED,
        ]);
        $query = [
            'search' => 'Ana',
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-31',
            'status' => LaboratoryOrder::STATUS_PENDING,
            'branch_id' => $branch->id,
            'commercial_context' => 'particular',
            'per_page' => 2,
        ];

        $pages = [];
        foreach ([1, 2, 3] as $page) {
            $pages[$page] = $this->indexRequest($user, $laboratory, [...$query, 'page' => $page])
                ->assertOk();
        }

        $expected = [$orders[4]->id, $orders[3]->id, $orders[2]->id, $orders[1]->id, $orders[0]->id];
        $actual = array_merge(...array_map(
            fn (TestResponse $response): array => $response->json('data.*.id'),
            $pages,
        ));
        $this->assertSame($expected, $actual);
        $this->assertCount(5, array_unique($actual));
        $this->assertSame(5, $pages[1]->json('meta.total'));
        $this->assertSame(3, $pages[1]->json('meta.last_page'));
        parse_str(parse_url($pages[1]->json('links.next'), PHP_URL_QUERY), $nextQuery);
        foreach ($query as $parameter => $value) {
            $this->assertSame((string) $value, $nextQuery[$parameter]);
        }

        $this->indexRequest($user, $laboratory, ['search' => 'Ana', 'status' => LaboratoryOrder::STATUS_COMPLETED])
            ->assertOk()->assertJsonPath('data.*.id', [$completed->id]);
        $this->indexRequest($user, $laboratory, ['search' => 'Ana', 'status' => LaboratoryOrder::STATUS_CANCELLED])
            ->assertOk()->assertJsonPath('data.*.id', [$cancelled->id]);
        $this->indexRequest($user, $laboratory, ['search' => 'Sin coincidencia'])
            ->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
    }

    public function test_relation_query_count_is_constant_for_pages_of_one_ten_and_fifty_orders(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $branch = Branch::factory()->for($laboratory)->create();
        $patient = Patient::factory()->for($laboratory)->create();
        $doctor = Doctor::factory()->for($laboratory)->create();
        $client = CommercialClient::factory()->for($laboratory)->create();
        $priceList = PriceList::factory()->for($laboratory)->create();
        foreach (range(1, 50) as $number) {
            $this->order($user, $laboratory, [
                'branch_id' => $branch->id,
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'commercial_client_id' => $client->id,
                'commercial_client_name' => $client->name,
                'commercial_client_type' => $client->type,
                'price_list_id' => $priceList->id,
                'price_list_name' => $priceList->name,
                'code' => sprintf('ORD-SCALE-%03d', $number),
                'ordered_at' => '2026-10-05 08:00:00',
            ]);
        }
        DB::enableQueryLog();
        $totalQueryCounts = [];

        foreach ([1, 10, 50] as $perPage) {
            DB::flushQueryLog();
            $this->indexRequest($user, $laboratory, ['per_page' => $perPage])
                ->assertOk()
                ->assertJsonCount($perPage, 'data');
            $queries = collect(DB::getQueryLog())->pluck('query')->map('strtolower');
            $totalQueryCounts[] = $queries->count();
            $this->assertCount(2, $queries->filter(
                fn (string $sql): bool => str_contains($sql, 'from "laboratory_orders"'),
            ));
            foreach (['branches', 'patients', 'doctors', 'users'] as $table) {
                $this->assertCount(1, $queries->filter(
                    fn (string $sql): bool => str_contains($sql, "from \"{$table}\""),
                ));
            }
            foreach (['commercial_clients', 'price_lists', 'price_list_exams', 'laboratory_exams'] as $table) {
                $this->assertFalse($queries->contains(
                    fn (string $sql): bool => str_contains($sql, "from \"{$table}\""),
                ));
            }
            $pageQuery = $queries->first(
                fn (string $sql): bool => str_contains($sql, 'from "laboratory_orders"')
                    && str_contains($sql, 'order by'),
            );
            $this->assertNotNull($pageQuery);
            $this->assertStringContainsString('laboratory_order_exams', $pageQuery);
            $this->assertStringNotContainsString(' join ', $pageQuery);
            $this->assertStringNotContainsString('distinct', $pageQuery);
        }

        $this->assertSame([$totalQueryCounts[0], $totalQueryCounts[0], $totalQueryCounts[0]], $totalQueryCounts);
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

    /** @param list<string> $tables */
    private function tableSnapshots(array $tables): array
    {
        return collect($tables)->mapWithKeys(fn (string $table): array => [
            $table => DB::table($table)->orderBy('id')->get()->map(
                fn (object $row): array => (array) $row,
            )->all(),
        ])->all();
    }
}

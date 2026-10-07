<?php

namespace Tests\Feature\Api\V1\LaboratoryOrders;

use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Models\Patient;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class LaboratoryOrderSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC'));
    }

    public function test_code_search_is_partial_case_insensitive_and_does_not_search_order_ids(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $patient = $this->patient($laboratory, 'Paciente', 'Código');
        $order = $this->order($user, $laboratory, $patient, [
            'code' => 'ORD-2026-000123',
        ]);
        foreach (['ORD-2026-000123', 'ord-2026', '000123'] as $search) {
            $this->indexRequest($user, $laboratory, ['search' => $search])
                ->assertOk()
                ->assertJsonPath('data.*.id', [$order->id]);
        }

        [, $idLaboratory] = $this->activeTenant($user);
        $idPatient = $this->patient($idLaboratory, 'Patient', 'Identifier');
        $idOrder = $this->order($user, $idLaboratory, $idPatient, ['code' => 'ALPHA-CODE']);

        $this->indexRequest($user, $idLaboratory, ['search' => (string) $idOrder->id])
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_patient_search_supports_partial_names_accents_and_all_whitespace_tokens(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $anaLopez = $this->patient($laboratory, 'Ana María', 'López García');
        $anaOther = $this->patient($laboratory, 'Ana María', 'Méndez García');
        $juan = $this->patient($laboratory, 'Juan Carlos', 'De León Pérez');
        $anaOrder = $this->order($user, $laboratory, $anaLopez, [
            'code' => 'ORDER-ANA',
            'ordered_at' => '2026-10-01 08:00:00',
        ]);
        $anaOtherOrder = $this->order($user, $laboratory, $anaOther, [
            'code' => 'ORDER-OTHER',
            'ordered_at' => '2026-10-02 08:00:00',
        ]);
        $juanOrder = $this->order($user, $laboratory, $juan, ['code' => 'ORDER-JUAN']);

        foreach ([
            'Ana' => [$anaOtherOrder->id, $anaOrder->id],
            'Mar' => [$anaOtherOrder->id, $anaOrder->id],
            'lópez' => [$anaOrder->id],
            'garc' => [$anaOtherOrder->id, $anaOrder->id],
            'Ana López' => [$anaOrder->id],
            '  Ana   María López   García  ' => [$anaOrder->id],
        ] as $search => $expectedIds) {
            $this->indexRequest($user, $laboratory, ['search' => $search])
                ->assertOk()
                ->assertJsonPath('data.*.id', $expectedIds);
        }

        if (DB::getDriverName() === 'pgsql') {
            $this->indexRequest($user, $laboratory, ['search' => 'LÓPEZ'])
                ->assertOk()
                ->assertJsonPath('data.*.id', [$anaOrder->id]);
        }

        foreach (['Juan', 'Carlos', 'De León', 'Juan De León', 'Juan Carlos De León', 'Carlos Pérez'] as $search) {
            $this->indexRequest($user, $laboratory, ['search' => $search])
                ->assertOk()
                ->assertJsonPath('data.*.id', [$juanOrder->id]);
        }

        $this->indexRequest($user, $laboratory, ['search' => 'Ana López'])
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_sql_wildcards_and_backslash_are_literal_text(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $patient = $this->patient($laboratory, 'Literal', 'Search');
        $percent = $this->order($user, $laboratory, $patient, ['code' => 'ORD-100%-DONE']);
        $underscore = $this->order($user, $laboratory, $patient, ['code' => 'ORD-CODE_X']);
        $backslash = $this->order($user, $laboratory, $patient, ['code' => 'ORD-BACK\\SLASH']);
        $bang = $this->order($user, $laboratory, $patient, ['code' => 'ORD-BANG!VALUE']);
        $this->order($user, $laboratory, $patient, ['code' => 'ORD-ORDINARY']);

        foreach ([
            '%' => $percent->id,
            '_' => $underscore->id,
            '\\' => $backslash->id,
            '!' => $bang->id,
        ] as $search => $expectedId) {
            $this->indexRequest($user, $laboratory, ['search' => $search])
                ->assertOk()
                ->assertJsonPath('data.*.id', [$expectedId]);
        }
    }

    public function test_empty_search_follows_catalog_convention_and_invalid_search_is_rejected(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $patient = $this->patient($laboratory, 'Empty', 'Convention');
        $order = $this->order($user, $laboratory, $patient);

        foreach (['', '    '] as $search) {
            $this->indexRequest($user, $laboratory, ['search' => $search])
                ->assertOk()
                ->assertJsonPath('data.*.id', [$order->id]);
        }

        $this->indexRequest($user, $laboratory, ['search' => str_repeat('a', 151)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['search']);
        $this->indexRequest($user, $laboratory, ['search' => ['Ana']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['search']);
    }

    public function test_code_and_patient_search_remain_isolated_to_header_tenant(): void
    {
        $user = User::factory()->create();
        [, $laboratoryA] = $this->activeTenant($user);
        [, $laboratoryB] = $this->activeTenant($user);
        $patientA = $this->patient($laboratoryA, 'Ana', 'Compartida');
        $patientB = $this->patient($laboratoryB, 'Ana', 'Compartida');
        $orderA = $this->order($user, $laboratoryA, $patientA, ['code' => 'ORD-SHARED-001']);
        $orderB = $this->order($user, $laboratoryB, $patientB, ['code' => 'ORD-SHARED-001']);

        foreach (['Ana Compartida', 'ORD-SHARED-001'] as $search) {
            $this->indexRequest($user, $laboratoryA, ['search' => $search])
                ->assertOk()
                ->assertJsonPath('data.*.id', [$orderA->id]);
            $this->indexRequest($user, $laboratoryB, ['search' => $search])
                ->assertOk()
                ->assertJsonPath('data.*.id', [$orderB->id]);
        }
    }

    public function test_code_and_patient_match_returns_each_order_once_and_no_match_is_empty(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $patient = $this->patient($laboratory, 'Alpha', 'Patient');
        $order = $this->order($user, $laboratory, $patient, ['code' => 'ORD-ALPHA']);

        $this->indexRequest($user, $laboratory, ['search' => 'Alpha'])
            ->assertOk()
            ->assertJsonPath('data.*.id', [$order->id])
            ->assertJsonPath('meta.total', 1);
        $this->indexRequest($user, $laboratory, ['search' => 'No existe'])
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    public function test_search_preserves_pagination_ordering_statuses_exam_count_and_response_shape(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $patient = $this->patient($laboratory, 'Shared', 'Name');
        $statuses = LaboratoryOrder::STATUSES;
        $orders = [];

        foreach ($statuses as $index => $status) {
            $orders[] = $this->order($user, $laboratory, $patient, [
                'code' => "ORD-STATE-{$index}",
                'status' => $status,
                'ordered_at' => match ($index) {
                    0 => '2026-10-01 08:00:00',
                    1, 2 => '2026-10-03 08:00:00',
                    3 => '2026-10-04 08:00:00',
                },
            ]);
        }
        LaboratoryOrderExam::factory()->count(3)->for($laboratory)->create([
            'laboratory_order_id' => $orders[3]->id,
            'price_list_id' => $orders[3]->price_list_id,
        ]);

        $page1 = $this->indexRequest($user, $laboratory, [
            'search' => '  Shared   Name ',
            'page' => 1,
            'per_page' => 2,
        ])->assertOk();
        $page2 = $this->indexRequest($user, $laboratory, [
            'search' => 'Shared Name',
            'page' => 2,
            'per_page' => 2,
        ])->assertOk();

        $this->assertSame(4, $page1->json('meta.total'));
        $this->assertSame([$orders[3]->id, $orders[2]->id], $page1->json('data.*.id'));
        $this->assertSame([$orders[1]->id, $orders[0]->id], $page2->json('data.*.id'));
        $this->assertSame(3, $page1->json('data.0.exam_count'));
        $this->assertEqualsCanonicalizing($statuses, array_merge(
            $page1->json('data.*.status'),
            $page2->json('data.*.status'),
        ));
        $this->assertSame([
            'id', 'code', 'ordered_at', 'status', 'branch', 'patient', 'doctor',
            'commercial_client', 'exam_count', 'currency', 'total', 'created_by',
        ], array_keys($page1->json('data.0')));

        parse_str(parse_url($page1->json('links.next'), PHP_URL_QUERY), $nextQuery);
        $this->assertSame('Shared   Name', $nextQuery['search']);
        $this->assertSame('2', $nextQuery['per_page']);
    }

    public function test_search_uses_grouped_exists_and_keeps_a_constant_query_count(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $patient = $this->patient($laboratory, 'Query', 'Patient');
        foreach (range(1, 5) as $number) {
            $this->order($user, $laboratory, $patient, ['code' => "ORD-QUERY-{$number}"]);
        }

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->indexRequest($user, $laboratory, ['search' => 'query patient', 'per_page' => 100])
            ->assertOk()
            ->assertJsonCount(5, 'data');

        $orderQueries = collect($queries)->filter(
            fn (string $sql): bool => str_contains($sql, 'from "laboratory_orders"'),
        );
        $this->assertCount(2, $orderQueries);
        $pageQuery = $orderQueries->first(fn (string $sql): bool => str_contains($sql, 'order by'));
        $this->assertNotNull($pageQuery);
        $this->assertMatchesRegularExpression(
            '/"laboratory_id" = \? and \("code" (?:like|ilike) \? escape \'!\' or exists/',
            $pageQuery,
        );
        $this->assertStringContainsString('"first_names"', $pageQuery);
        $this->assertStringContainsString('"last_names"', $pageQuery);
        $this->assertCount(1, collect($queries)->filter(
            fn (string $sql): bool => preg_match('/^select .* from "patients" where "patients"\."id" in/', $sql) === 1,
        ));
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

    private function patient(Laboratory $laboratory, string $firstNames, string $lastNames): Patient
    {
        return Patient::factory()->for($laboratory)->create([
            'first_names' => $firstNames,
            'last_names' => $lastNames,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function order(
        User $user,
        Laboratory $laboratory,
        Patient $patient,
        array $attributes = [],
    ): LaboratoryOrder {
        return LaboratoryOrder::factory()
            ->for($laboratory)
            ->for($patient)
            ->create(array_replace(['created_by' => $user->id], $attributes));
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

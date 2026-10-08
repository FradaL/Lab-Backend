<?php

namespace Tests\Feature\Api\V1\LaboratoryOrders;

use App\Http\Controllers\Api\V1\LaboratoryOrderController;
use App\Models\CommercialClient;
use App\Models\Laboratory;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class LaboratoryOrderIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC'));
    }

    public function test_empty_list_returns_standard_pagination_contract(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->indexRequest($user, $laboratory)
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 0)
            ->assertJsonStructure([
                'data',
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_list_resource_is_summary_and_uses_persisted_snapshots_and_line_count(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->for($laboratory)->create([
            'name' => 'Cliente Histórico',
            'type' => CommercialClient::TYPE_COMPANY,
        ]);
        $order = $this->order($user, $laboratory, [
            'commercial_client_id' => $client->id,
            'commercial_client_name' => 'Cliente Histórico',
            'commercial_client_type' => CommercialClient::TYPE_COMPANY,
            'currency' => 'GTQ',
            'total' => '175.00',
        ]);
        $exam = LaboratoryExam::factory()->for($laboratory)->create();
        LaboratoryOrderExam::factory()->count(3)->for($laboratory)->create([
            'laboratory_order_id' => $order->id,
            'laboratory_exam_id' => $exam->id,
            'price_list_id' => $order->price_list_id,
        ]);
        $client->update([
            'name' => 'Cliente Renombrado',
            'type' => CommercialClient::TYPE_INSURANCE,
        ]);

        $response = $this->indexRequest($user, $laboratory)->assertOk();
        $item = $response->json('data.0');

        $this->assertSame([
            'id', 'code', 'ordered_at', 'status', 'branch', 'patient', 'doctor',
            'commercial_client', 'exam_count', 'currency', 'total', 'created_by',
        ], array_keys($item));
        $this->assertSame(['id', 'name'], array_keys($item['branch']));
        $this->assertSame(['id', 'first_names', 'last_names'], array_keys($item['patient']));
        $this->assertSame(['id', 'first_names', 'last_names'], array_keys($item['doctor']));
        $this->assertSame(['id', 'name', 'type'], array_keys($item['commercial_client']));
        $this->assertSame(['id', 'name'], array_keys($item['created_by']));
        $response
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.commercial_client.name', 'Cliente Histórico')
            ->assertJsonPath('data.0.commercial_client.type', CommercialClient::TYPE_COMPANY)
            ->assertJsonPath('data.0.exam_count', 3)
            ->assertJsonPath('data.0.currency', 'GTQ')
            ->assertJsonPath('data.0.total', '175.00')
            ->assertJsonPath('data.0.created_by.id', $user->id)
            ->assertJsonMissingPath('data.0.notes')
            ->assertJsonMissingPath('data.0.price_list')
            ->assertJsonMissingPath('data.0.exams');
        $this->assertIsInt($item['id']);
        $this->assertIsInt($item['exam_count']);
        $this->assertIsString($item['total']);
    }

    public function test_particular_without_doctor_and_zero_exams_returns_nulls_and_zero_count(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $order = $this->order($user, $laboratory, [
            'doctor_id' => null,
            'commercial_client_id' => null,
            'commercial_client_name' => null,
            'commercial_client_type' => null,
        ]);

        $this->indexRequest($user, $laboratory)
            ->assertOk()
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.doctor', null)
            ->assertJsonPath('data.0.commercial_client', null)
            ->assertJsonPath('data.0.exam_count', 0);
    }

    public function test_tenant_header_isolates_multi_membership_in_both_directions(): void
    {
        $user = User::factory()->create();
        [, $laboratoryA] = $this->activeTenant($user);
        [, $laboratoryB] = $this->activeTenant($user);
        $orderA = $this->order($user, $laboratoryA);
        $orderB = $this->order($user, $laboratoryB);

        $this->indexRequest($user, $laboratoryA)
            ->assertOk()
            ->assertJsonPath('data.*.id', [$orderA->id]);
        $this->indexRequest($user, $laboratoryB)
            ->assertOk()
            ->assertJsonPath('data.*.id', [$orderB->id]);
    }

    public function test_all_statuses_are_visible_in_ordered_at_descending_then_id_descending_order(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $oldest = $this->order($user, $laboratory, [
            'ordered_at' => '2026-10-01 08:00:00',
            'status' => LaboratoryOrder::STATUS_PENDING,
        ]);
        $tieFirst = $this->order($user, $laboratory, [
            'ordered_at' => '2026-10-03 08:00:00',
            'status' => LaboratoryOrder::STATUS_IN_PROCESS,
        ]);
        $tieSecond = $this->order($user, $laboratory, [
            'ordered_at' => '2026-10-03 08:00:00',
            'status' => LaboratoryOrder::STATUS_COMPLETED,
        ]);
        $newest = $this->order($user, $laboratory, [
            'ordered_at' => '2026-10-04 08:00:00',
            'status' => LaboratoryOrder::STATUS_CANCELLED,
        ]);

        $response = $this->indexRequest($user, $laboratory)->assertOk();

        $this->assertSame([
            $newest->id,
            $tieSecond->id,
            $tieFirst->id,
            $oldest->id,
        ], $response->json('data.*.id'));
        $this->assertEqualsCanonicalizing(
            LaboratoryOrder::STATUSES,
            $response->json('data.*.status'),
        );
    }

    public function test_server_side_pagination_has_stable_non_overlapping_pages_and_preserves_query(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        foreach (range(1, 5) as $day) {
            $this->order($user, $laboratory, [
                'ordered_at' => "2026-10-0{$day} 08:00:00",
            ]);
        }

        $page1 = $this->indexRequest($user, $laboratory, ['page' => 1, 'per_page' => 2])->assertOk();
        $page2 = $this->indexRequest($user, $laboratory, ['page' => 2, 'per_page' => 2])->assertOk();
        $page3 = $this->indexRequest($user, $laboratory, ['page' => 3, 'per_page' => 2])->assertOk();
        $empty = $this->indexRequest($user, $laboratory, ['page' => 99, 'per_page' => 2])->assertOk();

        $this->assertSame(2, $page1->json('meta.per_page'));
        $this->assertSame(5, $page1->json('meta.total'));
        $this->assertSame(3, $page1->json('meta.last_page'));
        $this->assertCount(2, $page1->json('data'));
        $this->assertCount(2, $page2->json('data'));
        $this->assertCount(1, $page3->json('data'));
        $this->assertSame([], $empty->json('data'));
        $allIds = array_merge(
            $page1->json('data.*.id'),
            $page2->json('data.*.id'),
            $page3->json('data.*.id'),
        );
        $this->assertCount(5, array_unique($allIds));
        $this->assertStringContainsString('per_page=2', $page1->json('links.next'));

        $this->indexRequest($user, $laboratory, ['per_page' => 100])
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    #[DataProvider('invalidQueryProvider')]
    public function test_invalid_future_tenant_and_unknown_query_parameters_are_rejected(
        array $query,
        string $field,
    ): void {
        [$user, $laboratory] = $this->activeTenant();

        $this->indexRequest($user, $laboratory, $query)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidQueryProvider(): array
    {
        return [
            'page zero' => [['page' => 0], 'page'],
            'page negative' => [['page' => -1], 'page'],
            'page text' => [['page' => 'abc'], 'page'],
            'per page zero' => [['per_page' => 0], 'per_page'],
            'per page negative' => [['per_page' => -1], 'per_page'],
            'per page text' => [['per_page' => 'abc'], 'per_page'],
            'per page above maximum' => [['per_page' => 101], 'per_page'],
            'tenant injection' => [['laboratory_id' => 1], 'laboratory_id'],
            'laboratory alias' => [['laboratory' => 1], 'laboratory'],
            'lab alias' => [['lab' => 1], 'lab'],
            'tenant alias' => [['tenant' => 1], 'tenant'],
            'institution alias' => [['institution_id' => 1], 'institution_id'],
            'future sorting' => [['sort' => 'ordered_at'], 'sort'],
            'future direction' => [['direction' => 'desc'], 'direction'],
            'future order by' => [['order_by' => 'ordered_at'], 'order_by'],
            'patient id remains unsupported' => [['patient_id' => 1], 'patient_id'],
            'doctor alias remains unsupported' => [['doctor' => 1], 'doctor'],
            'unknown' => [['foo' => 'bar'], 'foo'],
        ];
    }

    public function test_saas_pipeline_precedes_index_validation(): void
    {
        $this->getJson('/api/v1/laboratory-orders?foo=bar')->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->getJson('/api/v1/laboratory-orders?foo=bar')
            ->assertBadRequest()->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', 'invalid')
            ->getJson('/api/v1/laboratory-orders?foo=bar')
            ->assertBadRequest()->assertJsonPath('code', 'INVALID_LABORATORY_CONTEXT');

        $laboratory = Laboratory::factory()->create();
        $this->createCurrentSubscription($laboratory);
        $this->indexRequest($user, $laboratory, ['foo' => 'bar'])
            ->assertForbidden()->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $laboratory->subscriptions()->delete();
        $this->indexRequest($user, $laboratory, ['foo' => 'bar'])
            ->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_eager_loading_and_with_count_use_constant_queries_without_catalog_reads(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        foreach (range(1, 5) as $number) {
            $order = $this->order($user, $laboratory);
            LaboratoryOrderExam::factory()->count($number)->for($laboratory)->create([
                'laboratory_order_id' => $order->id,
                'price_list_id' => $order->price_list_id,
            ]);
        }
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->indexRequest($user, $laboratory, ['per_page' => 100])->assertOk();

        foreach (['branches', 'patients', 'doctors', 'users'] as $table) {
            $this->assertCount(1, collect($queries)->filter(
                fn (string $sql): bool => str_contains($sql, "from \"{$table}\""),
            ), "Expected one eager-load query for {$table}.");
        }
        $orderQueries = collect($queries)->filter(
            fn (string $sql): bool => str_contains($sql, 'from "laboratory_orders"'),
        );
        $this->assertCount(2, $orderQueries);
        $pageQuery = $orderQueries->first(
            fn (string $sql): bool => str_contains($sql, 'order by'),
        );
        $this->assertNotNull($pageQuery);
        $this->assertStringContainsString('laboratory_order_exams', $pageQuery);
        $this->assertStringContainsString('count(*)', $pageQuery);
        $this->assertStringContainsString('order by "ordered_at" desc, "id" desc', $pageQuery);
        foreach (['commercial_clients', 'price_lists', 'price_list_exams', 'laboratory_exams'] as $table) {
            $this->assertFalse(collect($queries)->contains(
                fn (string $sql): bool => str_contains($sql, "from \"{$table}\""),
            ));
        }
        $this->assertFalse(collect($queries)->contains(
            fn (string $sql): bool => preg_match('/\A(insert|update|delete)\b/', ltrim($sql)) === 1,
        ));
    }

    public function test_route_and_openapi_publish_only_the_base_index_contract(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(
            fn ($route): bool => $route->uri() === 'api/v1/laboratory-orders'
                && in_array('GET', $route->methods(), true),
        );

        $this->assertNotNull($route);
        $this->assertSame(LaboratoryOrderController::class.'@index', $route->getActionName());
        $this->assertContains('saas', $route->gatherMiddleware());

        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $operation = $document['paths']['/api/v1/laboratory-orders']['get'];
        $parameters = collect($operation['parameters']);
        $inlineParameterNames = $parameters->pluck('name')->filter()->values();

        $this->assertEqualsCanonicalizing(
            [
                'page', 'per_page', 'search', 'date_from', 'date_to', 'status',
                'branch_id', 'doctor_id', 'commercial_client_id', 'commercial_context',
                'price_list_id',
            ],
            $inlineParameterNames->all(),
        );
        $this->assertTrue($parameters->contains(
            fn (array $parameter): bool => ($parameter['$ref'] ?? null) === '#/components/parameters/LaboratoryContextHeader',
        ));
        $this->assertSame('#/components/schemas/LaboratoryOrderListResponse', $operation['responses']['200']['content']['application/json']['schema']['$ref']);
        $this->assertSame([200, 400, 401, 403, 404, 422], array_keys($operation['responses']));
        $searchParameter = $parameters->firstWhere('name', 'search');
        $this->assertEqualsCanonicalizing(['string', 'null'], $searchParameter['schema']['type']);
        $this->assertSame(150, $searchParameter['schema']['maxLength']);
        $this->assertSame(['pending', 'in_process', 'completed', 'cancelled'], $parameters->firstWhere('name', 'status')['schema']['enum']);
        $this->assertSame(['particular', 'client'], $parameters->firstWhere('name', 'commercial_context')['schema']['enum']);
        $this->assertNotContains('sort', $inlineParameterNames);
    }

    /** @return array{User, Laboratory} */
    private function activeTenant(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $this->createCurrentSubscription($laboratory);

        $this->assignAllOrderPermissions($user, $laboratory);

        return [$user, $laboratory];
    }

    private function createCurrentSubscription(Laboratory $laboratory): Subscription
    {
        return Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ]);
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
        $uri = '/api/v1/laboratory-orders';
        if ($query !== []) {
            $uri .= '?'.http_build_query($query);
        }

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson($uri);
    }
}

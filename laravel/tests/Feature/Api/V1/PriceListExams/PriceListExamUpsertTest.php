<?php

namespace Tests\Feature\Api\V1\PriceListExams;

use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use App\Models\LaboratoryExam;
use App\Models\PriceList;
use App\Models\PriceListExam;
use App\Models\SampleType;
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

class PriceListExamUpsertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-29 12:00:00', 'UTC'));
    }

    public function test_create_returns_201_with_exact_resource_and_server_controlled_ownership(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        [$list, $exam, $area, $sample] = $this->catalogs($laboratory);

        $response = $this->upsert($user, $laboratory, $list, $exam, ['price' => '75.00'])
            ->assertCreated()
            ->assertJsonPath('data.laboratory_exam.id', $exam->id)
            ->assertJsonPath('data.laboratory_exam.code', $exam->code)
            ->assertJsonPath('data.laboratory_exam.name', $exam->name)
            ->assertJsonPath('data.laboratory_exam.laboratory_area.id', $area->id)
            ->assertJsonPath('data.laboratory_exam.sample_type.id', $sample->id)
            ->assertJsonPath('data.price', '75.00')
            ->assertJsonPath('data.status', PriceListExam::STATUS_ACTIVE)
            ->assertJsonPath('data.created_at', '2026-09-29T12:00:00.000000Z')
            ->assertJsonPath('data.updated_at', '2026-09-29T12:00:00.000000Z');

        $payload = $response->json('data');
        $this->assertSame([
            'id', 'laboratory_exam', 'price', 'status', 'created_at', 'updated_at',
        ], array_keys($payload));
        $this->assertSame([
            'id', 'code', 'name', 'laboratory_area', 'sample_type',
        ], array_keys($payload['laboratory_exam']));
        $this->assertArrayNotHasKey('laboratory_id', $payload);
        $this->assertArrayNotHasKey('price_list_id', $payload);
        $this->assertArrayNotHasKey('laboratory_exam_id', $payload);
        $this->assertArrayNotHasKey('currency', $payload);
        $this->assertArrayNotHasKey('pivot', $payload);
        $this->assertDatabaseHas('price_list_exams', [
            'laboratory_id' => $laboratory->id,
            'price_list_id' => $list->id,
            'laboratory_exam_id' => $exam->id,
            'price' => '75.00',
            'status' => PriceListExam::STATUS_ACTIVE,
        ]);
    }

    public function test_existing_assignment_updates_only_price_and_returns_200(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        [$list, $exam] = $this->catalogs($laboratory);
        $item = $this->price($laboratory, $list, $exam, [
            'price' => '75.00',
            'status' => PriceListExam::STATUS_INACTIVE,
        ]);

        $this->upsert($user, $laboratory, $list, $exam, ['price' => '80'])
            ->assertOk()
            ->assertJsonPath('data.id', $item->id)
            ->assertJsonPath('data.price', '80.00')
            ->assertJsonPath('data.status', PriceListExam::STATUS_INACTIVE);

        $this->assertDatabaseCount('price_list_exams', 1);
        $this->assertDatabaseHas('price_list_exams', [
            'id' => $item->id,
            'price' => '80.00',
            'status' => PriceListExam::STATUS_INACTIVE,
        ]);
    }

    public function test_idempotent_update_executes_no_update_and_preserves_updated_at(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        [$list, $exam] = $this->catalogs($laboratory);
        $item = $this->price($laboratory, $list, $exam, ['price' => '75.00']);
        $originalUpdatedAt = $item->getRawOriginal('updated_at');
        $updates = [];
        DB::listen(function (QueryExecuted $query) use (&$updates): void {
            if (preg_match('/^update\s+"?price_list_exams/i', ltrim($query->sql)) === 1) {
                $updates[] = $query->sql;
            }
        });

        $this->upsert($user, $laboratory, $list, $exam, ['price' => '75.00'])
            ->assertOk()->assertJsonPath('data.price', '75.00');

        $this->assertSame([], $updates);
        $this->assertSame($originalUpdatedAt, $item->fresh()->getRawOriginal('updated_at'));
    }

    #[DataProvider('validPriceProvider')]
    public function test_valid_prices_are_normalized_without_float_decisions(string $json, string $expected): void
    {
        [$user, $laboratory] = $this->activeTenant();
        [$list, $exam] = $this->catalogs($laboratory);

        $this->rawUpsert($user, $laboratory, $list, $exam, $json)
            ->assertCreated()
            ->assertJsonPath('data.price', $expected);
        $this->assertSame($expected, PriceListExam::query()->sole()->price);
    }

    /** @return array<string, array{string, string}> */
    public static function validPriceProvider(): array
    {
        return [
            'string decimal' => ['{"price":"75.00"}', '75.00'],
            'integer number' => ['{"price":75}', '75.00'],
            'decimal number' => ['{"price":75.5}', '75.50'],
            'zero number' => ['{"price":0}', '0.00'],
            'zero string' => ['{"price":"0.00"}', '0.00'],
            'one cent' => ['{"price":"0.01"}', '0.01'],
            'maximum' => ['{"price":"9999999999.99"}', '9999999999.99'],
        ];
    }

    #[DataProvider('invalidPriceProvider')]
    public function test_invalid_prices_return_422_without_writes(string $json): void
    {
        [$user, $laboratory] = $this->activeTenant();
        [$list, $exam] = $this->catalogs($laboratory);

        $this->rawUpsert($user, $laboratory, $list, $exam, $json)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('price');
        $this->assertDatabaseCount('price_list_exams', 0);
    }

    /** @return array<string, array{string}> */
    public static function invalidPriceProvider(): array
    {
        return [
            'missing' => ['{}'],
            'null' => ['{"price":null}'],
            'empty string' => ['{"price":""}'],
            'whitespace only' => ['{"price":"   "}'],
            'leading whitespace' => ['{"price":" 75.00"}'],
            'trailing whitespace' => ['{"price":"75.00 "}'],
            'three decimals string' => ['{"price":"75.001"}'],
            'three decimals number' => ['{"price":75.001}'],
            'negative' => ['{"price":"-0.01"}'],
            'boolean' => ['{"price":true}'],
            'array' => ['{"price":[]}'],
            'object' => ['{"price":{}}'],
            'text' => ['{"price":"abc"}'],
            'scientific string' => ['{"price":"1e3"}'],
            'scientific number' => ['{"price":1e3}'],
            'comma decimal' => ['{"price":"75,00"}'],
            'over maximum' => ['{"price":"10000000000.00"}'],
        ];
    }

    #[DataProvider('unknownFieldProvider')]
    public function test_unknown_and_injected_fields_are_rejected_atomically(string $field): void
    {
        [$user, $laboratory] = $this->activeTenant();
        [$list, $exam] = $this->catalogs($laboratory);
        $item = $this->price($laboratory, $list, $exam, ['price' => '25.00']);

        $this->upsert($user, $laboratory, $list, $exam, ['price' => '75.00', $field => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertSame('25.00', $item->fresh()->price);
    }

    /** @return array<string, array{string}> */
    public static function unknownFieldProvider(): array
    {
        return [
            'status' => ['status'],
            'laboratory id' => ['laboratory_id'],
            'price list id' => ['price_list_id'],
            'exam id' => ['laboratory_exam_id'],
            'currency' => ['currency'],
            'discount' => ['discount'],
            'created at' => ['created_at'],
        ];
    }

    public function test_create_requires_active_parents_and_accumulates_both_errors(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        [$list, $exam] = $this->catalogs($laboratory);
        $list->update(['status' => PriceList::STATUS_INACTIVE]);
        $exam->update(['status' => LaboratoryExam::STATUS_INACTIVE]);

        $this->upsert($user, $laboratory, $list, $exam, ['price' => '75.00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price_list', 'laboratory_exam']);
        $this->assertDatabaseCount('price_list_exams', 0);
    }

    public function test_existing_assignment_can_be_maintained_with_both_parents_inactive(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        [$list, $exam] = $this->catalogs($laboratory);
        $item = $this->price($laboratory, $list, $exam, [
            'price' => '20.00',
            'status' => PriceListExam::STATUS_INACTIVE,
        ]);
        $list->update(['status' => PriceList::STATUS_INACTIVE]);
        $exam->update(['status' => LaboratoryExam::STATUS_INACTIVE]);

        $this->upsert($user, $laboratory, $list, $exam, ['price' => '30.00'])
            ->assertOk()
            ->assertJsonPath('data.price', '30.00')
            ->assertJsonPath('data.status', PriceListExam::STATUS_INACTIVE);
        $this->assertSame('30.00', $item->fresh()->price);
    }

    public function test_target_lookup_order_precedes_validation_and_returns_neutral_404(): void
    {
        config(['app.debug' => false]);
        [$user, $laboratory] = $this->activeTenant();
        [$list, $exam] = $this->catalogs($laboratory);
        [, $foreignLaboratory] = $this->activeTenant($user);
        [$foreignList, $foreignExam] = $this->catalogs($foreignLaboratory);

        $cases = [
            [$foreignList->id, $exam->id],
            [999999, $exam->id],
            [$list->id, $foreignExam->id],
            [$list->id, 999999],
            [$foreignList->id, $foreignExam->id],
        ];
        foreach ($cases as [$listId, $examId]) {
            $this->rawUpsert($user, $laboratory, $listId, $examId, '{"price":null}')
                ->assertNotFound()
                ->assertExactJson(['message' => 'Resource not found.']);
        }
    }

    public function test_two_tenants_and_context_switching_remain_isolated(): void
    {
        $user = User::factory()->create();
        [, $laboratoryA] = $this->activeTenant($user);
        [, $laboratoryB] = $this->activeTenant($user);
        [$listA, $examA] = $this->catalogs($laboratoryA);
        [$listB, $examB] = $this->catalogs($laboratoryB);

        $this->upsert($user, $laboratoryA, $listA, $examA, ['price' => '10.00'])->assertCreated();
        $this->upsert($user, $laboratoryB, $listB, $examB, ['price' => '20.00'])->assertCreated();
        $this->upsert($user, $laboratoryA, $listA, $examA, ['price' => '11.00'])->assertOk();
        $this->upsert($user, $laboratoryB, $listB, $examB, ['price' => '21.00'])->assertOk();

        $this->assertDatabaseHas('price_list_exams', ['laboratory_id' => $laboratoryA->id, 'price' => '11.00']);
        $this->assertDatabaseHas('price_list_exams', ['laboratory_id' => $laboratoryB->id, 'price' => '21.00']);
        $this->assertDatabaseCount('price_list_exams', 2);
    }

    public function test_get_reflects_price_created_and_updated_by_put(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        [$list, $exam] = $this->catalogs($laboratory);

        $this->upsert($user, $laboratory, $list, $exam, ['price' => '40.00'])->assertCreated();
        $this->upsert($user, $laboratory, $list, $exam, ['price' => '45.50'])->assertOk();

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson("/api/v1/price-lists/{$list->id}/exams")
            ->assertOk()
            ->assertJsonPath('data.0.price', '45.50');
    }

    public function test_query_counts_are_stable_and_noop_has_no_write(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        [$list, $exam] = $this->catalogs($laboratory);

        $create = $this->captureQueries(fn (): TestResponse => $this->upsert($user, $laboratory, $list, $exam, ['price' => '10.00']));
        $update = $this->captureQueries(fn (): TestResponse => $this->upsert($user, $laboratory, $list, $exam, ['price' => '11.00']));
        $noop = $this->captureQueries(fn (): TestResponse => $this->upsert($user, $laboratory, $list, $exam, ['price' => '11.00']));

        $this->assertCount(6, $this->catalogQueries($create));
        $this->assertCount(6, $this->catalogQueries($update));
        $this->assertCount(5, $this->catalogQueries($noop));
        $this->assertCount(0, array_filter($noop, fn (array $query): bool => str_starts_with(strtolower(ltrim($query['query'])), 'update')));
    }

    public function test_saas_pipeline_precedes_targets_and_validation(): void
    {
        $this->putJson('/api/v1/price-lists/999/exams/999', ['price' => null])->assertUnauthorized();

        $this->actingAs(User::factory()->create(), 'web')
            ->putJson('/api/v1/price-lists/999/exams/999', ['price' => null])
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');
    }

    public function test_route_and_openapi_match_the_upsert_contract(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/price-lists/{priceList}/exams'))
            ->values();
        $put = $routes->first(fn ($route): bool => $route->uri() === 'api/v1/price-lists/{priceList}/exams/{laboratoryExam}');

        $this->assertCount(4, $routes);
        $this->assertSame(['PUT'], $put->methods());
        $this->assertSame(['priceList' => '[0-9]+', 'laboratoryExam' => '[0-9]+'], $put->wheres);
        $this->assertContains('saas', $put->middleware());

        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $operation = $document['paths']['/api/v1/price-lists/{priceList}/exams/{laboratoryExam}']['put'];
        $schema = $document['components']['schemas']['UpsertPriceListExamInput'];
        $examPriceOperations = collect($document['paths'])
            ->flatMap(fn (array $path): array => array_values(array_intersect_key($path, array_flip(['get', 'post', 'put', 'patch', 'delete']))))
            ->filter(fn (array $operation): bool => in_array('Exam Prices', $operation['tags'] ?? [], true));

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertCount(5, $examPriceOperations);
        $this->assertSame(['price'], $schema['required']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(['price'], array_keys($schema['properties']));
        $this->assertCount(2, $schema['properties']['price']['oneOf']);
        $this->assertSame('#/components/schemas/UpsertPriceListExamInput', $operation['requestBody']['content']['application/json']['schema']['$ref']);
        $this->assertSame([200, 201, 400, 401, 403, 404, 422], array_keys($operation['responses']));
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
        $this->assignDirectLaboratoryPermissions($user, $laboratory, [
            'exam_prices.view', 'exam_prices.manage',
        ]);

        return [$user, $laboratory];
    }

    /** @return array{PriceList, LaboratoryExam, LaboratoryArea, SampleType} */
    private function catalogs(Laboratory $laboratory): array
    {
        $list = PriceList::factory()->for($laboratory)->create();
        $area = LaboratoryArea::factory()->for($laboratory)->create();
        $sample = SampleType::factory()->for($laboratory)->create();
        $exam = LaboratoryExam::factory()->for($laboratory)->create([
            'laboratory_area_id' => $area->id,
            'sample_type_id' => $sample->id,
        ]);

        return [$list, $exam, $area, $sample];
    }

    private function price(Laboratory $laboratory, PriceList $list, LaboratoryExam $exam, array $attributes = []): PriceListExam
    {
        return PriceListExam::factory()->create([
            'laboratory_id' => $laboratory->id,
            'price_list_id' => $list->id,
            'laboratory_exam_id' => $exam->id,
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function upsert(User $user, Laboratory $laboratory, PriceList|int $list, LaboratoryExam|int $exam, array $payload): TestResponse
    {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->putJson($this->uri($list, $exam), $payload);
    }

    private function rawUpsert(User $user, Laboratory $laboratory, PriceList|int $list, LaboratoryExam|int $exam, string $json): TestResponse
    {
        $this->assignDirectLaboratoryPermission($user, $laboratory, 'exam_prices.manage');
        $this->actingAs($user, 'web');

        return $this->call('PUT', $this->uri($list, $exam), [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_LABORATORY_ID' => (string) $laboratory->id,
        ], $json);
    }

    private function uri(PriceList|int $list, LaboratoryExam|int $exam): string
    {
        $listId = $list instanceof PriceList ? $list->id : $list;
        $examId = $exam instanceof LaboratoryExam ? $exam->id : $exam;

        return "/api/v1/price-lists/{$listId}/exams/{$examId}";
    }

    /** @return list<array{query: string, bindings: array<int, mixed>, time: float}> */
    private function captureQueries(callable $callback): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $callback()->assertSuccessful();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return $queries;
    }

    /** @param list<array{query: string, bindings: array<int, mixed>, time: float}> $queries */
    private function catalogQueries(array $queries): array
    {
        return array_values(array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'price_lists')
            || str_contains($query['query'], 'laboratory_exams')
            || str_contains($query['query'], 'price_list_exams')
            || str_contains($query['query'], 'laboratory_areas')
            || str_contains($query['query'], 'sample_types')));
    }
}

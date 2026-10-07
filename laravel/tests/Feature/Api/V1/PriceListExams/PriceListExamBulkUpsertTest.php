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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PriceListExamBulkUpsertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00', 'UTC'));
    }

    public function test_create_update_and_noop_are_atomic_ordered_and_counted(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $list = PriceList::factory()->for($laboratory)->create();
        [$examA, $examB, $examC] = $this->exams($laboratory, 3);
        $unchanged = $this->price($laboratory, $list, $examA, ['price' => '75.00', 'status' => 'inactive']);
        $updated = $this->price($laboratory, $list, $examB, ['price' => '40.00', 'status' => 'active']);
        $unchangedAt = $unchanged->getRawOriginal('updated_at');
        $createdAt = $updated->getRawOriginal('created_at');
        $this->travel(5)->minutes();

        $response = $this->bulk($user, $laboratory, $list, ['items' => [
            ['laboratory_exam_id' => $examC->id, 'price' => '20'],
            ['laboratory_exam_id' => $examA->id, 'price' => '75.00'],
            ['laboratory_exam_id' => $examB->id, 'price' => '45.50'],
        ]])->assertOk()
            ->assertJsonPath('meta.created', 1)
            ->assertJsonPath('meta.updated', 1)
            ->assertJsonPath('meta.unchanged', 1)
            ->assertJsonCount(3, 'data');

        $this->assertSame([$examC->id, $examA->id, $examB->id], collect($response->json('data'))->pluck('laboratory_exam.id')->all());
        $this->assertSame(['20.00', '75.00', '45.50'], collect($response->json('data'))->pluck('price')->all());
        $this->assertSame('inactive', $unchanged->fresh()->status);
        $this->assertSame($unchangedAt, $unchanged->fresh()->getRawOriginal('updated_at'));
        $this->assertSame('active', $updated->fresh()->status);
        $this->assertSame($createdAt, $updated->fresh()->getRawOriginal('created_at'));
        $this->assertSame('2026-09-30 12:05:00', $updated->fresh()->getRawOriginal('updated_at'));
        $this->assertSame('active', PriceListExam::query()->where('laboratory_exam_id', $examC->id)->value('status'));
    }

    public function test_omitted_items_are_completely_untouched(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $list = PriceList::factory()->for($laboratory)->create();
        [$included, $omittedA, $omittedB] = $this->exams($laboratory, 3);
        $this->price($laboratory, $list, $included, ['price' => '10.00']);
        $a = $this->price($laboratory, $list, $omittedA, ['price' => '20.00', 'status' => 'inactive'])->fresh()->getAttributes();
        $b = $this->price($laboratory, $list, $omittedB, ['price' => '30.00'])->fresh()->getAttributes();

        $this->bulk($user, $laboratory, $list, ['items' => [['laboratory_exam_id' => $included->id, 'price' => '11.00']]])->assertOk();

        $this->assertSame($a, PriceListExam::query()->where('laboratory_exam_id', $omittedA->id)->firstOrFail()->getAttributes());
        $this->assertSame($b, PriceListExam::query()->where('laboratory_exam_id', $omittedB->id)->firstOrFail()->getAttributes());
        $this->assertDatabaseCount('price_list_exams', 3);
    }

    #[DataProvider('validPriceProvider')]
    public function test_exact_decimal_contract_is_reused(string $token, string $expected): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $list = PriceList::factory()->for($laboratory)->create();
        [$exam] = $this->exams($laboratory, 1);

        $this->rawBulk($user, $laboratory, $list, '{"items":[{"laboratory_exam_id":'.$exam->id.',"price":'.$token.'}]}')
            ->assertOk()->assertJsonPath('data.0.price', $expected);
    }

    public static function validPriceProvider(): array
    {
        return [
            'decimal string' => ['"75.00"', '75.00'], 'integer' => ['75', '75.00'],
            'decimal number' => ['75.5', '75.50'], 'zero string' => ['"0.00"', '0.00'],
            'zero number' => ['0', '0.00'], 'cent' => ['0.01', '0.01'],
            'maximum' => ['9999999999.99', '9999999999.99'],
        ];
    }

    #[DataProvider('invalidPriceProvider')]
    public function test_invalid_price_rolls_back_entire_batch(string $token): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $list = PriceList::factory()->for($laboratory)->create();
        [$validExam, $invalidExam] = $this->exams($laboratory, 2);
        $existing = $this->price($laboratory, $list, $validExam, ['price' => '10.00']);
        $before = $existing->fresh()->getAttributes();
        $json = '{"items":[{"laboratory_exam_id":'.$validExam->id.',"price":"20.00"},{"laboratory_exam_id":'.$invalidExam->id.',"price":'.$token.'}]}';

        $this->rawBulk($user, $laboratory, $list, $json)
            ->assertUnprocessable()->assertJsonValidationErrors('items.1.price');
        $this->assertSame($before, $existing->fresh()->getAttributes());
        $this->assertDatabaseCount('price_list_exams', 1);
    }

    public static function invalidPriceProvider(): array
    {
        return [
            'negative' => ['"-0.01"'], 'three decimals' => ['"75.001"'],
            'leading whitespace' => ['" 75.00"'], 'trailing whitespace' => ['"75.00 "'],
            'scientific string' => ['"1e3"'], 'scientific number' => ['1e3'],
            'over maximum' => ['"10000000000.00"'], 'boolean' => ['true'],
            'null' => ['null'], 'array' => ['[]'], 'object' => ['{}'],
            'text' => ['"abc"'], 'comma' => ['"75,00"'],
        ];
    }

    #[DataProvider('invalidStructureProvider')]
    public function test_closed_structural_contract_returns_indexed_errors(array $payload, string $path): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $list = PriceList::factory()->for($laboratory)->create();
        [$exam] = $this->exams($laboratory, 1);
        $payload = $this->replaceExamPlaceholder($payload, $exam->id);

        $this->bulk($user, $laboratory, $list, $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($path);
        $this->assertDatabaseCount('price_list_exams', 0);
    }

    public static function invalidStructureProvider(): array
    {
        return [
            'missing items' => [[], 'items'], 'empty items' => [['items' => []], 'items'],
            'null items' => [['items' => null], 'items'], 'string items' => [['items' => 'x'], 'items'],
            'top field' => [['items' => [['laboratory_exam_id' => 1, 'price' => '1.00']], 'status' => 'active'], 'status'],
            'missing id' => [['items' => [['price' => '1.00']]], 'items.0.laboratory_exam_id'],
            'missing price' => [['items' => [['laboratory_exam_id' => 1]]], 'items.0.price'],
            'string id' => [['items' => [['laboratory_exam_id' => '1', 'price' => '1.00']]], 'items.0.laboratory_exam_id'],
            'item status' => [['items' => [['laboratory_exam_id' => 1, 'price' => '1.00', 'status' => 'inactive']]], 'items.0.status'],
            'ownership' => [['items' => [['laboratory_exam_id' => 1, 'price' => '1.00', 'laboratory_id' => 1]]], 'items.0.laboratory_id'],
            'currency' => [['items' => [['laboratory_exam_id' => 1, 'price' => '1.00', 'currency' => 'GTQ']]], 'items.0.currency'],
        ];
    }

    public function test_duplicates_are_rejected_on_later_occurrence_without_writes(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $list = PriceList::factory()->for($laboratory)->create();
        [$exam] = $this->exams($laboratory, 1);

        $this->bulk($user, $laboratory, $list, ['items' => [
            ['laboratory_exam_id' => $exam->id, 'price' => '10.00'],
            ['laboratory_exam_id' => $exam->id, 'price' => '20.00'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('items.1.laboratory_exam_id');
        $this->assertDatabaseCount('price_list_exams', 0);
    }

    public function test_all_missing_and_cross_tenant_exams_are_neutral_422_and_atomic(): void
    {
        $user = User::factory()->create();
        [, $laboratory] = $this->activeTenant($user);
        [, $foreignLaboratory] = $this->activeTenant($user);
        $list = PriceList::factory()->for($laboratory)->create();
        [$valid] = $this->exams($laboratory, 1);
        [$foreign] = $this->exams($foreignLaboratory, 1);
        $existing = $this->price($laboratory, $list, $valid, ['price' => '10.00']);

        $response = $this->bulk($user, $laboratory, $list, ['items' => [
            ['laboratory_exam_id' => $valid->id, 'price' => '20.00'],
            ['laboratory_exam_id' => $foreign->id, 'price' => '30.00'],
            ['laboratory_exam_id' => 999999, 'price' => '40.00'],
        ]])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.1.laboratory_exam_id', 'items.2.laboratory_exam_id']);
        $this->assertSame('The selected laboratory exam is invalid.', $response->json('errors')['items.1.laboratory_exam_id'][0]);
        $this->assertSame('The selected laboratory exam is invalid.', $response->json('errors')['items.2.laboratory_exam_id'][0]);
        $this->assertSame('10.00', $existing->fresh()->price);
        $this->assertDatabaseCount('price_list_exams', 1);
    }

    public function test_price_list_lookup_precedes_invalid_payload(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        [, $laboratory] = $this->activeTenant($user);
        [, $foreignLaboratory] = $this->activeTenant($user);
        $foreignList = PriceList::factory()->for($foreignLaboratory)->create();

        foreach ([$foreignList->id, 999999] as $listId) {
            $this->bulk($user, $laboratory, $listId, ['items' => []])
                ->assertNotFound()->assertExactJson(['message' => 'Resource not found.']);
        }
    }

    public function test_inactive_parent_matrix_distinguishes_existing_from_new_atomically(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $list = PriceList::factory()->for($laboratory)->create(['status' => 'inactive']);
        [$existingExam, $newExam] = $this->exams($laboratory, 2);
        $existingExam->update(['status' => 'inactive']);
        $newExam->update(['status' => 'inactive']);
        $existing = $this->price($laboratory, $list, $existingExam, ['price' => '10.00', 'status' => 'inactive']);

        $this->bulk($user, $laboratory, $list, ['items' => [['laboratory_exam_id' => $existingExam->id, 'price' => '11.00']]])
            ->assertOk()->assertJsonPath('data.0.status', 'inactive');
        $snapshot = $existing->fresh()->getAttributes();

        $this->bulk($user, $laboratory, $list, ['items' => [
            ['laboratory_exam_id' => $existingExam->id, 'price' => '12.00'],
            ['laboratory_exam_id' => $newExam->id, 'price' => '20.00'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('items.1.laboratory_exam_id');
        $this->assertSame($snapshot, $existing->fresh()->getAttributes());
        $this->assertDatabaseCount('price_list_exams', 1);
    }

    public function test_one_hundred_items_pass_and_one_hundred_one_fails(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $list = PriceList::factory()->for($laboratory)->create();
        $exams = $this->exams($laboratory, 101);
        $items = collect($exams)->map(fn (LaboratoryExam $exam) => ['laboratory_exam_id' => $exam->id, 'price' => '1.00'])->all();

        $this->bulk($user, $laboratory, $list, ['items' => array_slice($items, 0, 100)])
            ->assertOk()->assertJsonCount(100, 'data')->assertJsonPath('meta.created', 100);
        $before = PriceListExam::query()->count();
        $this->bulk($user, $laboratory, $list, ['items' => $items])
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertSame($before, PriceListExam::query()->count());
    }

    public function test_read_query_count_is_constant_for_one_ten_and_one_hundred_noops(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $list = PriceList::factory()->for($laboratory)->create();
        $exams = $this->exams($laboratory, 100);
        foreach ($exams as $exam) {
            $this->price($laboratory, $list, $exam, ['price' => '1.00']);
        }

        $counts = [];
        foreach ([1, 10, 100] as $size) {
            $items = collect($exams)->take($size)->map(fn (LaboratoryExam $exam) => ['laboratory_exam_id' => $exam->id, 'price' => '1.00'])->all();
            $queries = $this->captureQueries(fn () => $this->bulk($user, $laboratory, $list, ['items' => $items]));
            $counts[$size] = count(array_filter($queries, fn (array $query) => str_starts_with(strtolower(ltrim($query['query'])), 'select') && $this->isDomainQuery($query['query'])));
        }

        $this->assertSame($counts[1], $counts[10]);
        $this->assertSame($counts[10], $counts[100]);
        $this->assertSame(8, $counts[100]);
    }

    public function test_get_reflects_bulk_and_tenants_remain_isolated(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $listA = PriceList::factory()->for($labA)->create();
        $listB = PriceList::factory()->for($labB)->create();
        [$examA] = $this->exams($labA, 1);
        [$examB] = $this->exams($labB, 1);

        $this->bulk($user, $labA, $listA, ['items' => [['laboratory_exam_id' => $examA->id, 'price' => '10.00']]])->assertOk();
        $this->bulk($user, $labB, $listB, ['items' => [['laboratory_exam_id' => $examB->id, 'price' => '20.00']]])->assertOk();
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', (string) $labA->id)
            ->getJson("/api/v1/price-lists/{$listA->id}/exams")
            ->assertOk()->assertJsonPath('data.0.price', '10.00')->assertJsonPath('meta.total', 1);
        $this->assertDatabaseHas('price_list_exams', ['laboratory_id' => $labB->id, 'price' => '20.00']);
    }

    public function test_saas_pipeline_and_bulk_route_precedence(): void
    {
        $this->putJson('/api/v1/price-lists/999/exams/bulk', ['items' => []])->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'web')
            ->putJson('/api/v1/price-lists/999/exams/bulk', ['items' => []])
            ->assertBadRequest()->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');

        $route = collect(Route::getRoutes()->getRoutes())->first(fn ($route) => $route->uri() === 'api/v1/price-lists/{priceList}/exams/bulk');
        $this->assertSame(['PUT'], $route->methods());
        $this->assertSame(['priceList' => '[0-9]+'], $route->wheres);
        $this->assertContains('saas', $route->middleware());
    }

    public function test_openapi_matches_bulk_contract_and_four_operations(): void
    {
        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $operation = $document['paths']['/api/v1/price-lists/{priceList}/exams/bulk']['put'];
        $schema = $document['components']['schemas']['BulkUpsertPriceListExamInput'];
        $item = $document['components']['schemas']['BulkUpsertPriceListExamItemInput'];
        $operations = collect($document['paths'])->flatMap(fn (array $path) => array_values(array_intersect_key($path, array_flip(['get', 'post', 'put', 'patch', 'delete']))));

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertCount(5, $operations->filter(fn (array $operation) => in_array('Exam Prices', $operation['tags'] ?? [], true)));
        $this->assertSame(['items'], $schema['required']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(1, $schema['properties']['items']['minItems']);
        $this->assertSame(100, $schema['properties']['items']['maxItems']);
        $this->assertSame(['laboratory_exam_id', 'price'], $item['required']);
        $this->assertFalse($item['additionalProperties']);
        $this->assertSame('#/components/schemas/BulkUpsertPriceListExamInput', $operation['requestBody']['content']['application/json']['schema']['$ref']);
        $this->assertSame([200, 400, 401, 403, 404, 422], array_keys($operation['responses']));
    }

    private function activeTenant(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        Subscription::factory()->for($laboratory)->create(['starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'trial_ends_at' => null]);
        $this->assignDirectLaboratoryPermission($user, $laboratory, 'exam_prices.manage');

        $this->assignDirectLaboratoryPermissions($user, $laboratory, ['exam_prices.view', 'exam_prices.manage']);

        return [$user, $laboratory];
    }

    /** @return list<LaboratoryExam> */
    private function exams(Laboratory $laboratory, int $count): array
    {
        $area = LaboratoryArea::factory()->for($laboratory)->create();
        $sample = SampleType::factory()->for($laboratory)->create();

        return LaboratoryExam::factory()->count($count)->for($laboratory)->create(['laboratory_area_id' => $area->id, 'sample_type_id' => $sample->id])->all();
    }

    private function price(Laboratory $laboratory, PriceList $list, LaboratoryExam $exam, array $attributes = []): PriceListExam
    {
        return PriceListExam::factory()->create(['laboratory_id' => $laboratory->id, 'price_list_id' => $list->id, 'laboratory_exam_id' => $exam->id, ...$attributes]);
    }

    private function bulk(User $user, Laboratory $laboratory, PriceList|int $list, array $payload): TestResponse
    {
        $listId = $list instanceof PriceList ? $list->id : $list;

        return $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->putJson("/api/v1/price-lists/{$listId}/exams/bulk", $payload);
    }

    private function rawBulk(User $user, Laboratory $laboratory, PriceList $list, string $json): TestResponse
    {
        $this->assignDirectLaboratoryPermission($user, $laboratory, 'exam_prices.manage');
        $this->actingAs($user, 'web');

        return $this->call('PUT', "/api/v1/price-lists/{$list->id}/exams/bulk", [], [], [], [
            'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'HTTP_X_LABORATORY_ID' => (string) $laboratory->id,
        ], $json);
    }

    private function replaceExamPlaceholder(array $payload, int $examId): array
    {
        if (! is_array($payload['items'] ?? null)) {
            return $payload;
        }

        foreach ($payload['items'] as $index => $item) {
            if (is_array($item) && ($item['laboratory_exam_id'] ?? null) === 1) {
                $payload['items'][$index]['laboratory_exam_id'] = $examId;
            }
        }

        return $payload;
    }

    private function captureQueries(callable $callback): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $callback()->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return $queries;
    }

    private function isDomainQuery(string $sql): bool
    {
        return str_contains($sql, 'price_lists') || str_contains($sql, 'laboratory_exams') || str_contains($sql, 'price_list_exams') || str_contains($sql, 'laboratory_areas') || str_contains($sql, 'sample_types');
    }
}

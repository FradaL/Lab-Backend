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

class AvailablePriceListExamsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00', 'UTC'));
    }

    public function test_only_commercially_available_exams_are_returned_with_exact_reception_shape(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $list = PriceList::factory()->for($laboratory)->create(['currency' => 'USD']);
        $area = LaboratoryArea::factory()->for($laboratory)->create();
        $sample = SampleType::factory()->for($laboratory)->create();
        $available = $this->exam($laboratory, $area, $sample, ['code' => 'HEM001', 'name' => 'Hemograma completo']);
        $missing = $this->exam($laboratory, $area, $sample, ['code' => 'HEM002']);
        $inactivePriceExam = $this->exam($laboratory, $area, $sample, ['code' => 'HEM003']);
        $inactiveExam = $this->exam($laboratory, $area, $sample, ['code' => 'HEM004', 'status' => LaboratoryExam::STATUS_INACTIVE]);
        $this->price($laboratory, $list, $inactivePriceExam, ['status' => PriceListExam::STATUS_INACTIVE]);
        $this->price($laboratory, $list, $inactiveExam);
        $availablePrice = $this->price($laboratory, $list, $available, ['price' => '0.00']);
        $area->update(['status' => 'inactive']);
        $sample->update(['status' => 'inactive']);

        $response = $this->catalog($user, $laboratory, $list)->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $available->id)
            ->assertJsonPath('data.0.price', '0.00')
            ->assertJsonPath('meta.currency', 'USD')
            ->assertJsonPath('meta.total', 1);

        $this->assertNotSame($availablePrice->id, $response->json('data.0.id'));
        $this->assertSame(['id', 'code', 'name', 'price', 'laboratory_area', 'sample_type'], array_keys($response->json('data.0')));
        $this->assertSame(['id', 'code', 'name'], array_keys($response->json('data.0.laboratory_area')));
        $this->assertSame(['id', 'name'], array_keys($response->json('data.0.sample_type')));
        $this->assertNotContains($missing->id, collect($response->json('data'))->pluck('id')->all());
    }

    public function test_inactive_price_list_is_a_business_error_after_query_validation(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $list = PriceList::factory()->inactive()->for($laboratory)->create();

        $this->catalog($user, $laboratory, $list)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price_list']);
        $this->catalog($user, $laboratory, $list, ['sort' => 'invalid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort'])
            ->assertJsonMissingValidationErrors(['price_list']);
    }

    public function test_price_list_lookup_wins_over_invalid_query_for_missing_and_cross_tenant_targets(): void
    {
        $user = User::factory()->create();
        [, $laboratoryA] = $this->activeTenant($user);
        [, $laboratoryB] = $this->activeTenant($user);
        $listB = PriceList::factory()->for($laboratoryB)->create();
        config(['app.debug' => false]);

        foreach ([999999, $listB->id] as $listId) {
            $response = $this->catalog($user, $laboratoryA, $listId, ['sort' => 'invalid'])
                ->assertNotFound()
                ->assertExactJson(['message' => 'Resource not found.']);
            foreach (['SQLSTATE', 'constraint', '/var/www', 'trace'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $response->getContent());
            }
        }
    }

    public function test_search_is_case_insensitive_grouped_and_limited_to_available_items(): void
    {
        $user = User::factory()->create();
        [, $laboratoryA] = $this->activeTenant($user);
        [, $laboratoryB] = $this->activeTenant($user);
        $listA = PriceList::factory()->for($laboratoryA)->create();
        $listB = PriceList::factory()->for($laboratoryB)->create();
        $areaA = LaboratoryArea::factory()->for($laboratoryA)->create();
        $sampleA = SampleType::factory()->for($laboratoryA)->create();
        $codeMatch = $this->exam($laboratoryA, $areaA, $sampleA, ['code' => 'HEMO001', 'name' => 'Conteo']);
        $nameMatch = $this->exam($laboratoryA, $areaA, $sampleA, ['code' => 'CBC001', 'name' => 'Hemograma completo']);
        $inactivePrice = $this->exam($laboratoryA, $areaA, $sampleA, ['name' => 'Hemograma inactivo']);
        $inactiveExam = $this->exam($laboratoryA, $areaA, $sampleA, ['name' => 'Hemograma archivado', 'status' => LaboratoryExam::STATUS_INACTIVE]);
        $missingPrice = $this->exam($laboratoryA, $areaA, $sampleA, ['name' => 'Hemograma sin precio']);
        $this->price($laboratoryA, $listA, $codeMatch);
        $this->price($laboratoryA, $listA, $nameMatch);
        $this->price($laboratoryA, $listA, $inactivePrice, ['status' => PriceListExam::STATUS_INACTIVE]);
        $this->price($laboratoryA, $listA, $inactiveExam);
        [$foreign] = $this->exams($laboratoryB, 1, ['name' => 'Hemograma foráneo']);
        $this->price($laboratoryB, $listB, $foreign);

        $ids = collect($this->catalog($user, $laboratoryA, $listA, ['search' => 'hEmO'])->assertOk()->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$codeMatch->id, $nameMatch->id], $ids);
        $this->assertNotContains($inactivePrice->id, $ids);
        $this->assertNotContains($inactiveExam->id, $ids);
        $this->assertNotContains($missingPrice->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
    }

    public function test_empty_search_is_trimmed_and_applies_no_filter(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $list = PriceList::factory()->for($laboratory)->create();
        foreach ($this->exams($laboratory, 2) as $exam) {
            $this->price($laboratory, $list, $exam);
        }

        $this->catalog($user, $laboratory, $list, ['search' => '   '])
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_area_sample_and_search_filters_are_combined_with_and_semantics(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $list = PriceList::factory()->for($laboratory)->create();
        $areaA = LaboratoryArea::factory()->for($laboratory)->create();
        $areaB = LaboratoryArea::factory()->for($laboratory)->create();
        $sampleA = SampleType::factory()->for($laboratory)->create();
        $sampleB = SampleType::factory()->for($laboratory)->create();
        $expected = $this->exam($laboratory, $areaA, $sampleA, ['name' => 'Hemograma esperado']);
        $wrongArea = $this->exam($laboratory, $areaB, $sampleA, ['name' => 'Hemograma área']);
        $wrongSample = $this->exam($laboratory, $areaA, $sampleB, ['name' => 'Hemograma muestra']);
        $wrongSearch = $this->exam($laboratory, $areaA, $sampleA, ['name' => 'Glucosa']);
        foreach ([$expected, $wrongArea, $wrongSample, $wrongSearch] as $exam) {
            $this->price($laboratory, $list, $exam);
        }

        $response = $this->catalog($user, $laboratory, $list, [
            'search' => 'hemo',
            'laboratory_area_id' => $areaA->id,
            'sample_type_id' => $sampleA->id,
        ])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);

        $this->assertSame($expected->id, $response->json('data.0.id'));
    }

    public function test_code_name_and_direction_sorting_use_a_stable_exam_id_tie_breaker(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $list = PriceList::factory()->for($laboratory)->create();
        $area = LaboratoryArea::factory()->for($laboratory)->create();
        $sample = SampleType::factory()->for($laboratory)->create();
        $b = $this->exam($laboratory, $area, $sample, ['code' => 'B', 'name' => 'Same']);
        $a1 = $this->exam($laboratory, $area, $sample, ['code' => 'A2', 'name' => 'Same']);
        $a2 = $this->exam($laboratory, $area, $sample, ['code' => 'A1', 'name' => 'Alpha']);
        foreach ([$b, $a1, $a2] as $exam) {
            $this->price($laboratory, $list, $exam);
        }

        $this->assertSame([$a2->id, $a1->id, $b->id], $this->ids($this->catalog($user, $laboratory, $list, ['sort' => 'code'])));
        $this->assertSame([$b->id, $a1->id, $a2->id], $this->ids($this->catalog($user, $laboratory, $list, ['sort' => 'code', 'direction' => 'desc'])));
        $this->assertSame([$a2->id, $b->id, $a1->id], $this->ids($this->catalog($user, $laboratory, $list)));
    }

    public function test_price_sort_is_numeric_in_both_directions(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $list = PriceList::factory()->for($laboratory)->create();
        $prices = ['100.00', '9.00', '0.00', '75.00'];
        $byPrice = [];
        foreach ($this->exams($laboratory, 4) as $index => $exam) {
            $this->price($laboratory, $list, $exam, ['price' => $prices[$index]]);
            $byPrice[$prices[$index]] = $exam->id;
        }

        $this->assertSame([$byPrice['0.00'], $byPrice['9.00'], $byPrice['75.00'], $byPrice['100.00']], $this->ids($this->catalog($user, $laboratory, $list, ['sort' => 'price'])));
        $this->assertSame([$byPrice['100.00'], $byPrice['75.00'], $byPrice['9.00'], $byPrice['0.00']], $this->ids($this->catalog($user, $laboratory, $list, ['sort' => 'price', 'direction' => 'desc'])));
    }

    public function test_default_pagination_is_stable_and_filtered_total_is_correct(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $list = PriceList::factory()->for($laboratory)->create();
        $exams = $this->exams($laboratory, 5, ['name' => 'Same name']);
        foreach ($exams as $exam) {
            $this->price($laboratory, $list, $exam);
        }

        $page1 = $this->catalog($user, $laboratory, $list, ['per_page' => 2, 'page' => 1])->assertOk();
        $page2 = $this->catalog($user, $laboratory, $list, ['per_page' => 2, 'page' => 2])->assertOk();

        $this->assertSame(array_slice(array_column($exams, 'id'), 0, 2), $this->ids($page1));
        $this->assertSame(array_slice(array_column($exams, 'id'), 2, 2), $this->ids($page2));
        $this->assertSame(5, $page1->json('meta.total'));
        $this->assertSame(2, $page1->json('meta.per_page'));
    }

    public function test_per_page_one_hundred_is_supported(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $list = PriceList::factory()->for($laboratory)->create();
        foreach ($this->exams($laboratory, 100) as $exam) {
            $this->price($laboratory, $list, $exam);
        }

        $this->catalog($user, $laboratory, $list, ['per_page' => 100])
            ->assertOk()->assertJsonCount(100, 'data')->assertJsonPath('meta.total', 100);
    }

    #[DataProvider('invalidQueryProvider')]
    public function test_query_contract_is_strict(array $query, string $error): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $list = PriceList::factory()->for($laboratory)->create();

        $this->catalog($user, $laboratory, $list, $query)
            ->assertUnprocessable()->assertJsonValidationErrors([$error]);
    }

    public static function invalidQueryProvider(): array
    {
        return [
            'unknown' => [['foo' => 'bar'], 'foo'],
            'area zero' => [['laboratory_area_id' => 0], 'laboratory_area_id'],
            'area text' => [['laboratory_area_id' => 'abc'], 'laboratory_area_id'],
            'area decimal' => [['laboratory_area_id' => '1.5'], 'laboratory_area_id'],
            'sample negative' => [['sample_type_id' => -1], 'sample_type_id'],
            'sample text' => [['sample_type_id' => 'abc'], 'sample_type_id'],
            'sort' => [['sort' => 'created_at'], 'sort'],
            'sort injection' => [['sort' => 'name desc; drop table price_lists'], 'sort'],
            'direction uppercase' => [['direction' => 'ASC'], 'direction'],
            'per page zero' => [['per_page' => 0], 'per_page'],
            'per page 101' => [['per_page' => 101], 'per_page'],
            'per page text' => [['per_page' => 'abc'], 'per_page'],
            'page zero' => [['page' => 0], 'page'],
            'page negative' => [['page' => -1], 'page'],
            'page text' => [['page' => 'abc'], 'page'],
        ];
    }

    public function test_same_exam_has_isolated_prices_in_different_price_lists(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $listA = PriceList::factory()->for($laboratory)->create();
        $listB = PriceList::factory()->for($laboratory)->create();
        [$exam] = $this->exams($laboratory, 1);
        $this->price($laboratory, $listA, $exam, ['price' => '75.00']);
        $this->price($laboratory, $listB, $exam, ['price' => '50.00']);

        $this->catalog($user, $laboratory, $listA)->assertJsonPath('data.0.price', '75.00');
        $this->catalog($user, $laboratory, $listB)->assertJsonPath('data.0.price', '50.00');
    }

    public function test_tenants_and_repeated_context_switching_never_leak_catalog_state(): void
    {
        $user = User::factory()->create();
        [, $laboratoryA] = $this->activeTenant($user);
        [, $laboratoryB] = $this->activeTenant($user);
        $listA = PriceList::factory()->for($laboratoryA)->create();
        $listB = PriceList::factory()->for($laboratoryB)->create();
        [$examA] = $this->exams($laboratoryA, 1, ['name' => 'Tenant A']);
        [$examB] = $this->exams($laboratoryB, 1, ['name' => 'Tenant B']);
        $this->price($laboratoryA, $listA, $examA, ['price' => '75.00']);
        $this->price($laboratoryB, $listB, $examB, ['price' => '50.00']);

        foreach ([[$laboratoryA, $listA, $examA], [$laboratoryB, $listB, $examB], [$laboratoryA, $listA, $examA], [$laboratoryB, $listB, $examB]] as [$laboratory, $list, $exam]) {
            $response = $this->catalog($user, $laboratory, $list)->assertOk()->assertJsonCount(1, 'data');
            $this->assertSame($exam->id, $response->json('data.0.id'));
        }
    }

    public function test_saas_pipeline_precedes_target_lookup_and_query_validation(): void
    {
        $uri = '/api/v1/price-lists/999/available-exams?sort=invalid';
        $this->getJson($uri)->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'web')->getJson($uri)
            ->assertBadRequest()->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');
        $this->actingAs(User::factory()->create(), 'web')->withHeader('X-Laboratory-ID', 'abc')->getJson($uri)
            ->assertBadRequest()->assertJsonPath('code', 'INVALID_LABORATORY_CONTEXT');
        $this->actingAs(User::factory()->create(), 'web')->withHeader('X-Laboratory-ID', '999999')->getJson($uri)
            ->assertNotFound()->assertJsonPath('code', 'LABORATORY_NOT_FOUND');

        $user = User::factory()->create();
        $inactive = Laboratory::factory()->create(['is_active' => false]);
        $user->laboratories()->attach($inactive, ['is_active' => true]);
        Subscription::factory()->for($inactive)->create();
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', (string) $inactive->id)->getJson($uri)
            ->assertForbidden()->assertJsonPath('code', 'LABORATORY_INACTIVE');

        $denied = Laboratory::factory()->create();
        Subscription::factory()->for($denied)->create();
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', (string) $denied->id)->getJson($uri)
            ->assertForbidden()->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

    }

    public function test_subscription_failure_precedes_target_and_query_validation(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson('/api/v1/price-lists/999/available-exams?sort=invalid')
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_success_and_all_failure_classes_execute_no_writes(): void
    {
        $user = User::factory()->create();
        [, $laboratoryA] = $this->activeTenant($user);
        [, $laboratoryB] = $this->activeTenant($user);
        $active = PriceList::factory()->for($laboratoryA)->create();
        $inactive = PriceList::factory()->inactive()->for($laboratoryA)->create();
        $foreign = PriceList::factory()->for($laboratoryB)->create();
        [$exam] = $this->exams($laboratoryA, 1);
        $this->price($laboratoryA, $active, $exam);
        $queries = $this->captureQueries(function () use ($user, $laboratoryA, $active, $inactive, $foreign): void {
            $this->catalog($user, $laboratoryA, $active)->assertOk();
            $this->catalog($user, $laboratoryA, $inactive)->assertUnprocessable();
            $this->catalog($user, $laboratoryA, $active, ['foo' => 'bar'])->assertUnprocessable();
            $this->catalog($user, $laboratoryA, $foreign)->assertNotFound();
        });

        $writes = array_filter($queries, fn (array $query): bool => preg_match('/^(insert|update|delete)\b/i', ltrim($query['query'])) === 1);
        $this->assertCount(0, $writes);
        $this->assertDatabaseCount('price_list_exams', 1);
    }

    public function test_domain_select_count_is_constant_for_one_fifteen_and_one_hundred_results(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $list = PriceList::factory()->for($laboratory)->create();
        foreach ($this->exams($laboratory, 100) as $exam) {
            $this->price($laboratory, $list, $exam);
        }
        $counts = [];

        foreach ([1, 15, 100] as $size) {
            $queries = $this->captureQueries(fn () => $this->catalog($user, $laboratory, $list, ['per_page' => $size])->assertOk());
            $counts[$size] = count(array_filter($queries, fn (array $query): bool => str_starts_with(strtolower(ltrim($query['query'])), 'select') && $this->isDomainQuery($query['query'])));
        }

        $this->assertSame($counts[1], $counts[15]);
        $this->assertSame($counts[15], $counts[100]);
        $this->assertSame(6, $counts[100]);
    }

    public function test_route_and_openapi_match_the_commercial_catalog_contract(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(fn ($route) => $route->uri() === 'api/v1/price-lists/{priceList}/available-exams');
        $this->assertNotNull($route);
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertSame(['priceList' => '[0-9]+'], $route->wheres);
        $this->assertContains('saas', $route->middleware());

        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $operation = $document['paths']['/api/v1/price-lists/{priceList}/available-exams']['get'];
        $queryParameters = collect($operation['parameters'])->where('in', 'query');
        $operations = collect($document['paths'])->flatMap(fn (array $path): array => array_values(array_intersect_key($path, array_flip(['get', 'post', 'put', 'patch', 'delete']))));
        $item = $document['components']['schemas']['AvailablePriceListExamItem'];
        $meta = $document['components']['schemas']['AvailablePriceListExamMetadata'];

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertCount(5, $operations->filter(fn (array $operation): bool => in_array('Exam Prices', $operation['tags'] ?? [], true)));
        $this->assertEqualsCanonicalizing(['search', 'laboratory_area_id', 'sample_type_id', 'sort', 'direction', 'per_page', 'page'], $queryParameters->pluck('name')->all());
        $this->assertSame(['code', 'name', 'price'], $queryParameters->firstWhere('name', 'sort')['schema']['enum']);
        $this->assertSame(['asc', 'desc'], $queryParameters->firstWhere('name', 'direction')['schema']['enum']);
        $this->assertSame(['id', 'code', 'name', 'price', 'laboratory_area', 'sample_type'], $item['required']);
        $this->assertFalse($item['additionalProperties']);
        $this->assertContains('currency', $meta['required']);
        $this->assertSame('#/components/schemas/AvailablePriceListExamListResponse', $operation['responses']['200']['content']['application/json']['schema']['$ref']);
        $this->assertSame([200, 400, 401, 403, 404, 422], array_keys($operation['responses']));
        $this->assertArrayNotHasKey('/api/v1/price-lists/{priceList}/exams/resolve', $document['paths']);
    }

    /** @return array{User, Laboratory} */
    private function activeTenant(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        Subscription::factory()->for($laboratory)->create(['starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'trial_ends_at' => null]);

        $this->assignDirectLaboratoryPermission($user, $laboratory, 'exam_prices.view');

        return [$user, $laboratory];
    }

    /** @return list<LaboratoryExam> */
    private function exams(Laboratory $laboratory, int $count, array $attributes = []): array
    {
        $area = LaboratoryArea::factory()->for($laboratory)->create();
        $sample = SampleType::factory()->for($laboratory)->create();

        return LaboratoryExam::factory()->count($count)->for($laboratory)->create([
            'laboratory_area_id' => $area->id,
            'sample_type_id' => $sample->id,
            ...$attributes,
        ])->all();
    }

    private function exam(Laboratory $laboratory, LaboratoryArea $area, SampleType $sample, array $attributes = []): LaboratoryExam
    {
        return LaboratoryExam::factory()->for($laboratory)->create([
            'laboratory_area_id' => $area->id,
            'sample_type_id' => $sample->id,
            ...$attributes,
        ]);
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

    private function catalog(User $user, Laboratory $laboratory, PriceList|int $list, array $query = []): TestResponse
    {
        $listId = $list instanceof PriceList ? $list->id : $list;
        $uri = "/api/v1/price-lists/{$listId}/available-exams";
        if ($query !== []) {
            $uri .= '?'.http_build_query($query);
        }

        return $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', (string) $laboratory->id)->getJson($uri);
    }

    /** @return list<int> */
    private function ids(TestResponse $response): array
    {
        return collect($response->assertOk()->json('data'))->pluck('id')->all();
    }

    /** @return list<array{query: string, bindings: array, time: float}> */
    private function captureQueries(callable $callback): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = ['query' => $query->sql, 'bindings' => $query->bindings, 'time' => $query->time];
        });
        $callback();

        return $queries;
    }

    private function isDomainQuery(string $query): bool
    {
        return str_contains($query, 'price_lists')
            || str_contains($query, 'price_list_exams')
            || str_contains($query, 'laboratory_exams')
            || str_contains($query, 'laboratory_areas')
            || str_contains($query, 'sample_types');
    }
}

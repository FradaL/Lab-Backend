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

class PriceListExamIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-29 12:00:00', 'UTC'));
    }

    public function test_empty_listing_has_standard_pagination_and_price_list_metadata(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create([
            'name' => 'Lista General',
            'currency' => 'GTQ',
        ]);

        $this->indexRequest($user, $laboratory, $priceList)
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.price_list', [
                'id' => $priceList->id,
                'name' => 'Lista General',
                'currency' => 'GTQ',
            ])
            ->assertJsonStructure([
                'data',
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'last_page', 'per_page', 'total', 'price_list'],
            ]);
    }

    public function test_resource_exposes_exact_fields_and_only_configured_exams(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create();
        $area = LaboratoryArea::factory()->for($laboratory)->create([
            'code' => 'HEM',
            'name' => 'Hematología',
            'status' => LaboratoryArea::STATUS_INACTIVE,
        ]);
        $sample = SampleType::factory()->inactive()->for($laboratory)->create(['name' => 'Sangre']);
        $exam = $this->exam($laboratory, $area, $sample, [
            'code' => 'HEM-001',
            'name' => 'Hematología completa',
            'status' => LaboratoryExam::STATUS_INACTIVE,
        ]);
        $unconfigured = $this->exam($laboratory, $area, $sample, ['name' => 'Sin precio']);
        $configured = $this->price($laboratory, $priceList, $exam, [
            'price' => '125.50',
            'status' => PriceListExam::STATUS_INACTIVE,
        ]);

        $response = $this->indexRequest($user, $laboratory, $priceList)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $configured->id)
            ->assertJsonPath('data.0.laboratory_exam.id', $exam->id)
            ->assertJsonPath('data.0.laboratory_exam.code', 'HEM-001')
            ->assertJsonPath('data.0.laboratory_exam.name', 'Hematología completa')
            ->assertJsonPath('data.0.laboratory_exam.laboratory_area', [
                'id' => $area->id, 'code' => 'HEM', 'name' => 'Hematología',
            ])
            ->assertJsonPath('data.0.laboratory_exam.sample_type', [
                'id' => $sample->id, 'name' => 'Sangre',
            ])
            ->assertJsonPath('data.0.price', '125.50')
            ->assertJsonPath('data.0.status', 'inactive')
            ->assertJsonPath('data.0.created_at', '2026-09-29T12:00:00.000000Z')
            ->assertJsonPath('data.0.updated_at', '2026-09-29T12:00:00.000000Z');

        $payload = $response->json();
        $this->assertSame([
            'id', 'laboratory_exam', 'price', 'status', 'created_at', 'updated_at',
        ], array_keys($payload['data'][0]));
        $this->assertSame([
            'id', 'code', 'name', 'laboratory_area', 'sample_type',
        ], array_keys($payload['data'][0]['laboratory_exam']));
        $this->assertNotContains($unconfigured->id, data_get($payload, 'data.*.laboratory_exam.id'));
        $this->assertArrayNotHasKey('laboratory_id', $payload['data'][0]);
        $this->assertArrayNotHasKey('currency', $payload['data'][0]);
    }

    public function test_rows_are_isolated_by_tenant_and_price_list(): void
    {
        config(['app.debug' => false]);
        [$user, $laboratory] = $this->activeTenant();
        $target = PriceList::factory()->for($laboratory)->create();
        $otherList = PriceList::factory()->for($laboratory)->create();
        $area = LaboratoryArea::factory()->for($laboratory)->create();
        $sample = SampleType::factory()->for($laboratory)->create();
        $includedExam = $this->exam($laboratory, $area, $sample, ['name' => 'Included']);
        $otherListExam = $this->exam($laboratory, $area, $sample, ['name' => 'Other list']);
        $included = $this->price($laboratory, $target, $includedExam);
        $this->price($laboratory, $otherList, $otherListExam);

        [, $foreignLaboratory] = $this->activeTenant($user);
        $foreignList = PriceList::factory()->for($foreignLaboratory)->create();
        $foreignArea = LaboratoryArea::factory()->for($foreignLaboratory)->create();
        $foreignSample = SampleType::factory()->for($foreignLaboratory)->create();
        $foreignExam = $this->exam($foreignLaboratory, $foreignArea, $foreignSample);
        $this->price($foreignLaboratory, $foreignList, $foreignExam);

        $this->indexRequest($user, $laboratory, $target)
            ->assertOk()
            ->assertJsonPath('data.*.id', [$included->id])
            ->assertJsonPath('meta.total', 1);

        $this->indexRequest($user, $laboratory, $foreignList)
            ->assertNotFound()
            ->assertExactJson(['message' => 'Resource not found.']);
    }

    public function test_search_is_trimmed_partial_case_insensitive_and_grouped_with_filters(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $list = PriceList::factory()->for($laboratory)->create();
        $area = LaboratoryArea::factory()->for($laboratory)->create();
        $sample = SampleType::factory()->for($laboratory)->create();
        $codeMatch = $this->exam($laboratory, $area, $sample, ['code' => 'GLU-FAST', 'name' => 'Glucosa']);
        $nameMatch = $this->exam($laboratory, $area, $sample, ['code' => 'ABC', 'name' => 'Perfil Glucémico']);
        $inactiveMatch = $this->exam($laboratory, $area, $sample, ['code' => 'GLU-OLD', 'name' => 'Descartado']);
        $this->price($laboratory, $list, $codeMatch);
        $this->price($laboratory, $list, $nameMatch);
        $this->price($laboratory, $list, $inactiveMatch, ['status' => PriceListExam::STATUS_INACTIVE]);

        $this->indexRequest($user, $laboratory, $list, ['search' => '  gLu  ', 'status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.*.laboratory_exam.id', [$codeMatch->id, $nameMatch->id]);

        $this->indexRequest($user, $laboratory, $list, ['search' => '   '])
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    }

    public function test_area_sample_and_price_status_filters_are_exact_and_composable(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $list = PriceList::factory()->for($laboratory)->create();
        $areaA = LaboratoryArea::factory()->for($laboratory)->create();
        $areaB = LaboratoryArea::factory()->for($laboratory)->create();
        $sampleA = SampleType::factory()->for($laboratory)->create();
        $sampleB = SampleType::factory()->for($laboratory)->create();
        $wantedExam = $this->exam($laboratory, $areaA, $sampleA, ['name' => 'Wanted']);
        $otherAreaExam = $this->exam($laboratory, $areaB, $sampleA, ['name' => 'Other area']);
        $otherSampleExam = $this->exam($laboratory, $areaA, $sampleB, ['name' => 'Other sample']);
        $inactive = $this->price($laboratory, $list, $wantedExam, ['status' => PriceListExam::STATUS_INACTIVE]);
        $this->price($laboratory, $list, $otherAreaExam, ['status' => PriceListExam::STATUS_INACTIVE]);
        $this->price($laboratory, $list, $otherSampleExam);

        $this->indexRequest($user, $laboratory, $list, [
            'status' => 'inactive',
            'laboratory_area_id' => $areaA->id,
            'sample_type_id' => $sampleA->id,
        ])->assertOk()->assertJsonPath('data.*.id', [$inactive->id]);

        $this->indexRequest($user, $laboratory, $list, ['laboratory_area_id' => 999999])
            ->assertOk()->assertJsonCount(0, 'data');
        $this->indexRequest($user, $laboratory, $list, ['sample_type_id' => 999999])
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_default_and_explicit_sorting_are_stable_and_price_is_numeric(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $list = PriceList::factory()->for($laboratory)->create();
        $area = LaboratoryArea::factory()->for($laboratory)->create();
        $sample = SampleType::factory()->for($laboratory)->create();
        $examB = $this->exam($laboratory, $area, $sample, ['code' => 'B', 'name' => 'Same']);
        $examA = $this->exam($laboratory, $area, $sample, ['code' => 'A', 'name' => 'Same']);
        $examC = $this->exam($laboratory, $area, $sample, ['code' => 'C', 'name' => 'Zulu']);
        $priceB = $this->price($laboratory, $list, $examB, ['price' => '10.00']);
        $priceA = $this->price($laboratory, $list, $examA, ['price' => '2.00']);
        $priceC = $this->price($laboratory, $list, $examC, ['price' => '100.00']);

        $this->indexRequest($user, $laboratory, $list)
            ->assertOk()->assertJsonPath('data.*.id', [$priceB->id, $priceA->id, $priceC->id]);
        $this->indexRequest($user, $laboratory, $list, ['sort' => 'exam_code', 'direction' => 'desc'])
            ->assertOk()->assertJsonPath('data.*.id', [$priceC->id, $priceB->id, $priceA->id]);
        $this->indexRequest($user, $laboratory, $list, ['sort' => 'price'])
            ->assertOk()->assertJsonPath('data.*.id', [$priceA->id, $priceB->id, $priceC->id]);
    }

    public function test_pagination_honors_page_and_per_page_and_preserves_query_string(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $list = PriceList::factory()->for($laboratory)->create();
        $area = LaboratoryArea::factory()->for($laboratory)->create();
        $sample = SampleType::factory()->for($laboratory)->create();
        foreach (range(1, 18) as $number) {
            $exam = $this->exam($laboratory, $area, $sample, ['name' => sprintf('Exam %02d', $number)]);
            $this->price($laboratory, $list, $exam);
        }

        $response = $this->indexRequest($user, $laboratory, $list, ['per_page' => 5, 'page' => 2])
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 4)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 18);

        $this->assertStringContainsString('per_page=5', $response->json('links.next'));
        $this->assertStringContainsString('page=3', $response->json('links.next'));
    }

    #[DataProvider('invalidQueryProvider')]
    public function test_invalid_or_unknown_query_parameters_return_422(array $query, string $field): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $list = PriceList::factory()->for($laboratory)->create();

        $this->indexRequest($user, $laboratory, $list, $query)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidQueryProvider(): array
    {
        return [
            'unknown' => [['foo' => 'bar'], 'foo'],
            'tenant injection' => [['laboratory_id' => 1], 'laboratory_id'],
            'invalid status' => [['status' => 'ACTIVE'], 'status'],
            'invalid sort' => [['sort' => 'name'], 'sort'],
            'invalid direction' => [['direction' => 'ASC'], 'direction'],
            'per page zero' => [['per_page' => 0], 'per_page'],
            'per page too large' => [['per_page' => 101], 'per_page'],
            'page zero' => [['page' => 0], 'page'],
            'area text' => [['laboratory_area_id' => 'abc'], 'laboratory_area_id'],
            'sample decimal' => [['sample_type_id' => '1.5'], 'sample_type_id'],
            'search array' => [['search' => ['x']], 'search'],
        ];
    }

    public function test_target_lookup_precedes_query_validation_and_uses_neutral_404(): void
    {
        config(['app.debug' => false]);
        [$user, $laboratory] = $this->activeTenant();
        [, $foreignLaboratory] = $this->activeTenant($user);
        $foreignList = PriceList::factory()->for($foreignLaboratory)->create();

        foreach ([999999, $foreignList->id] as $target) {
            $this->indexRequest($user, $laboratory, $target, ['foo' => 'bar'])
                ->assertNotFound()
                ->assertExactJson(['message' => 'Resource not found.']);
        }

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson('/api/v1/price-lists/not-a-number/exams')
            ->assertNotFound();
    }

    public function test_saas_pipeline_rejects_guest_and_missing_context_before_target_lookup(): void
    {
        $this->getJson('/api/v1/price-lists/999/exams?foo=bar')->assertUnauthorized();

        $this->actingAs(User::factory()->create(), 'web')
            ->getJson('/api/v1/price-lists/999/exams?foo=bar')
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');
    }

    public function test_query_is_read_only_tenant_scoped_uses_bound_search_and_has_no_n_plus_one(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $list = PriceList::factory()->for($laboratory)->create();
        $area = LaboratoryArea::factory()->for($laboratory)->create();
        $sample = SampleType::factory()->for($laboratory)->create();
        foreach (range(1, 8) as $number) {
            $exam = $this->exam($laboratory, $area, $sample, [
                'code' => "TEST-{$number}", 'name' => "Test {$number}",
            ]);
            $this->price($laboratory, $list, $exam);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->indexRequest($user, $laboratory, $list, ['search' => 'test'])
            ->assertOk()->assertJsonCount(8, 'data');
        $queries = collect(DB::getQueryLog());
        DB::disableQueryLog();

        $writes = $queries->filter(fn (array $query): bool => preg_match('/^(insert|update|delete)/i', ltrim($query['query'])) === 1);
        $catalogSelects = $queries->filter(fn (array $query): bool => str_starts_with(strtolower(ltrim($query['query'])), 'select') && (
            str_contains($query['query'], 'price_list_exams')
            || str_contains($query['query'], 'laboratory_exams')
            || str_contains($query['query'], 'laboratory_areas')
            || str_contains($query['query'], 'sample_types')
        ));
        $pageQuery = $catalogSelects->first(fn (array $query): bool => str_contains($query['query'], 'inner join'));

        $this->assertCount(0, $writes);
        $this->assertLessThanOrEqual(6, $catalogSelects->count());
        $this->assertNotNull($pageQuery);
        $this->assertStringContainsString('"price_list_exams"."laboratory_id" = ?', $pageQuery['query']);
        $this->assertMatchesRegularExpression('/\("laboratory_exams"\."code"(?:::text)? (?:i?like) \? or "laboratory_exams"\."name"(?:::text)? (?:i?like) \?\)/', $pageQuery['query']);
        $this->assertContains('%test%', $pageQuery['bindings']);
    }

    public function test_route_and_openapi_describe_exactly_one_exam_prices_operation(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => $route->uri() === 'api/v1/price-lists/{priceList}/exams')
            ->values();

        $this->assertCount(1, $routes);
        $this->assertSame(['GET', 'HEAD'], $routes->first()->methods());
        $this->assertContains('saas', $routes->first()->middleware());
        $this->assertSame(['priceList' => '[0-9]+'], $routes->first()->wheres);

        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $operation = $document['paths']['/api/v1/price-lists/{priceList}/exams']['get'];
        $queryParameters = collect($operation['parameters'])->where('in', 'query');
        $operations = collect($document['paths'])->flatMap(fn (array $path): array => array_values(array_intersect_key($path, array_flip(['get', 'post', 'patch', 'delete']))));

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertCount(3, $operations->filter(fn (array $operation): bool => in_array('Exam Prices', $operation['tags'] ?? [], true)));
        $this->assertCount(7, $operations->filter(fn (array $operation): bool => in_array('Price Lists', $operation['tags'] ?? [], true)));
        $this->assertCount(8, $queryParameters);
        $this->assertEqualsCanonicalizing([
            'search', 'status', 'laboratory_area_id', 'sample_type_id', 'sort', 'direction', 'per_page', 'page',
        ], $queryParameters->pluck('name')->all());
        $this->assertSame('#/components/schemas/PriceListExamListResponse', $operation['responses']['200']['content']['application/json']['schema']['$ref']);
        $this->assertSame([200, 400, 401, 403, 404, 422], array_keys($operation['responses']));
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

    /** @param array<string, mixed> $query */
    private function indexRequest(User $user, Laboratory $laboratory, PriceList|int $list, array $query = []): TestResponse
    {
        $id = $list instanceof PriceList ? $list->id : $list;
        $uri = "/api/v1/price-lists/{$id}/exams";
        if ($query !== []) {
            $uri .= '?'.http_build_query($query);
        }

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson($uri);
    }
}

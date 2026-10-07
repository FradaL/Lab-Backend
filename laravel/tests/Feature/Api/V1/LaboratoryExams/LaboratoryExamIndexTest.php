<?php

namespace Tests\Feature\Api\V1\LaboratoryExams;

use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use App\Models\LaboratoryExam;
use App\Models\SampleType;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LaboratoryExamIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-26 12:00:00', 'UTC'));
    }

    public function test_authenticated_empty_listing_returns_standard_pagination(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->examRequest($user, $laboratory)
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonStructure([
                'data',
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_resource_exposes_the_exact_exam_and_nested_contracts(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = LaboratoryArea::factory()->for($laboratory)->create([
            'code' => 'HEM',
            'name' => 'Hematología',
        ]);
        $sampleType = SampleType::factory()->for($laboratory)->create([
            'name' => 'Sangre',
        ]);
        $exam = $this->createExam($laboratory, [
            'code' => 'HEM-001',
            'name' => 'Hematología completa',
            'description' => str_repeat('Descripción extensa. ', 20),
            'turnaround_time_minutes' => 120,
        ], $area, $sampleType);

        $response = $this->examRequest($user, $laboratory)
            ->assertOk()
            ->assertJsonPath('data.0.id', $exam->id)
            ->assertJsonPath('data.0.code', 'HEM-001')
            ->assertJsonPath('data.0.name', 'Hematología completa')
            ->assertJsonPath('data.0.description', $exam->description)
            ->assertJsonPath('data.0.turnaround_time_minutes', 120)
            ->assertJsonPath('data.0.status', LaboratoryExam::STATUS_ACTIVE)
            ->assertJsonPath('data.0.laboratory_area', [
                'id' => $area->id,
                'code' => 'HEM',
                'name' => 'Hematología',
            ])
            ->assertJsonPath('data.0.sample_type', [
                'id' => $sampleType->id,
                'name' => 'Sangre',
            ])
            ->assertJsonPath('data.0.created_at', '2026-09-26T12:00:00.000000Z')
            ->assertJsonPath('data.0.updated_at', '2026-09-26T12:00:00.000000Z');

        $this->assertEqualsCanonicalizing([
            'id',
            'code',
            'name',
            'description',
            'turnaround_time_minutes',
            'status',
            'laboratory_area',
            'sample_type',
            'created_at',
            'updated_at',
        ], array_keys($response->json('data.0')));
        $this->assertEqualsCanonicalizing(
            ['id', 'code', 'name'],
            array_keys($response->json('data.0.laboratory_area')),
        );
        $this->assertEqualsCanonicalizing(
            ['id', 'name'],
            array_keys($response->json('data.0.sample_type')),
        );
        $response
            ->assertJsonMissingPath('data.0.laboratory_id')
            ->assertJsonMissingPath('data.0.laboratory_area_id')
            ->assertJsonMissingPath('data.0.sample_type_id')
            ->assertJsonMissingPath('data.0.laboratory')
            ->assertJsonMissingPath('data.0.prices')
            ->assertJsonMissingPath('data.0.results')
            ->assertJsonMissingPath('data.0.orders');
    }

    public function test_collection_is_symmetrically_isolated_during_context_switching(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $examA = $this->createExam($labA, ['code' => 'SHARED', 'name' => 'Shared Exam']);
        $examB = $this->createExam($labB, ['code' => 'SHARED', 'name' => 'Shared Exam']);

        $this->examRequest($user, $labA)
            ->assertJsonPath('data.*.id', [$examA->id]);
        $this->examRequest($user, $labB)
            ->assertJsonPath('data.*.id', [$examB->id]);
        $this->examRequest($user, $labA)
            ->assertJsonPath('data.*.id', [$examA->id]);
    }

    public function test_search_supports_code_name_partial_case_insensitivity_unicode_trim_and_empty(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $hematology = $this->createExam($laboratory, [
            'code' => 'HEM-001',
            'name' => 'Hematología completa',
        ]);
        $this->createExam($laboratory, [
            'code' => 'GLU',
            'name' => 'Glucosa',
        ]);

        foreach (['HEM-001', 'hem-001', 'M-00', 'Hematología', 'matología', '  hema  '] as $search) {
            $this->examRequest($user, $laboratory, ['search' => $search])
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $hematology->id);
        }

        foreach (['', '   '] as $search) {
            $this->examRequest($user, $laboratory, ['search' => $search])
                ->assertOk()
                ->assertJsonCount(2, 'data')
                ->assertJsonPath('meta.total', 2);
        }
    }

    public function test_grouped_search_never_escapes_the_tenant_scope(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $this->createExam($labA, ['code' => 'LOCAL', 'name' => 'Local']);
        $this->createExam($labB, ['code' => 'SECRET-999', 'name' => 'Examen ultrasecreto']);

        foreach (['SECRET-999', 'ultrasecreto'] as $search) {
            $this->examRequest($user, $labA, ['search' => $search])
                ->assertOk()
                ->assertJsonCount(0, 'data')
                ->assertJsonPath('meta.total', 0);
        }
    }

    public function test_wildcards_and_sql_injection_like_search_are_safely_bound(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $this->createExam($laboratory, ['code' => 'HEM_001', 'name' => 'Hematología 100%']);

        foreach (['%', '_', "%' OR 1=1 --"] as $search) {
            $this->examRequest($user, $laboratory, ['search' => $search])
                ->assertOk();
        }

        $this->assertDatabaseCount('laboratory_exams', 1);
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('laboratory_exams'));
    }

    public function test_status_filters_both_states_and_defaults_to_all(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $active = $this->createExam($laboratory, [
            'code' => 'ACTIVE',
            'name' => 'A Active',
            'status' => LaboratoryExam::STATUS_ACTIVE,
        ]);
        $inactive = $this->createExam($laboratory, [
            'code' => 'INACTIVE',
            'name' => 'B Inactive',
            'status' => LaboratoryExam::STATUS_INACTIVE,
        ]);

        $this->examRequest($user, $laboratory)
            ->assertJsonPath('data.*.id', [$active->id, $inactive->id]);
        $this->examRequest($user, $laboratory, ['status' => 'active'])
            ->assertJsonPath('data.*.id', [$active->id]);
        $this->examRequest($user, $laboratory, ['status' => 'inactive'])
            ->assertJsonPath('data.*.id', [$inactive->id]);
    }

    public function test_area_filter_is_tenant_scoped_and_cross_tenant_id_returns_empty(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $areaA = LaboratoryArea::factory()->for($labA)->create();
        $otherAreaA = LaboratoryArea::factory()->for($labA)->create();
        $areaB = LaboratoryArea::factory()->for($labB)->create();
        $expected = $this->createExam($labA, [], $areaA);
        $this->createExam($labA, [], $otherAreaA);

        $this->examRequest($user, $labA, ['laboratory_area_id' => $areaA->id])
            ->assertOk()
            ->assertJsonPath('data.*.id', [$expected->id]);
        $this->examRequest($user, $labA, ['laboratory_area_id' => $areaB->id])
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_sample_type_filter_is_tenant_scoped_and_cross_tenant_id_returns_empty(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $sampleA = SampleType::factory()->for($labA)->create();
        $otherSampleA = SampleType::factory()->for($labA)->create();
        $sampleB = SampleType::factory()->for($labB)->create();
        $expected = $this->createExam($labA, [], null, $sampleA);
        $this->createExam($labA, [], null, $otherSampleA);

        $this->examRequest($user, $labA, ['sample_type_id' => $sampleA->id])
            ->assertOk()
            ->assertJsonPath('data.*.id', [$expected->id]);
        $this->examRequest($user, $labA, ['sample_type_id' => $sampleB->id])
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_all_filters_are_combined_with_and_inside_the_tenant(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $area = LaboratoryArea::factory()->for($labA)->create();
        $otherArea = LaboratoryArea::factory()->for($labA)->create();
        $sampleType = SampleType::factory()->for($labA)->create();
        $otherSample = SampleType::factory()->for($labA)->create();
        $expected = $this->createExam($labA, [
            'code' => 'HEM-001',
            'name' => 'Hematología',
            'status' => LaboratoryExam::STATUS_ACTIVE,
        ], $area, $sampleType);
        $this->createExam($labA, ['code' => 'HEM-002'], $otherArea, $sampleType);
        $this->createExam($labA, ['code' => 'HEM-003'], $area, $otherSample);
        $this->createExam($labA, [
            'code' => 'HEM-004',
            'status' => LaboratoryExam::STATUS_INACTIVE,
        ], $area, $sampleType);
        $this->createExam($labB, ['code' => 'HEM-005']);

        $this->examRequest($user, $labA, [
            'search' => 'HEM',
            'status' => 'active',
            'laboratory_area_id' => $area->id,
            'sample_type_id' => $sampleType->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.*.id', [$expected->id])
            ->assertJsonPath('meta.total', 1);
    }

    public function test_sorting_supports_all_whitelisted_columns_directions_and_tiebreakers(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $first = $this->createExam($laboratory, [
            'code' => 'B',
            'name' => 'Same',
            'created_at' => '2026-09-20 12:00:00',
        ]);
        $second = $this->createExam($laboratory, [
            'code' => 'A',
            'name' => 'Same',
            'created_at' => '2026-09-20 12:00:00',
        ]);
        $third = $this->createExam($laboratory, [
            'code' => 'C',
            'name' => 'Alpha',
            'created_at' => '2026-09-22 12:00:00',
        ]);

        $this->examRequest($user, $laboratory)
            ->assertJsonPath('data.*.id', [$third->id, $first->id, $second->id]);
        $this->examRequest($user, $laboratory, ['sort' => 'code', 'direction' => 'asc'])
            ->assertJsonPath('data.*.id', [$second->id, $first->id, $third->id]);
        $this->examRequest($user, $laboratory, ['sort' => 'code', 'direction' => 'desc'])
            ->assertJsonPath('data.*.id', [$third->id, $first->id, $second->id]);
        $this->examRequest($user, $laboratory, ['sort' => 'name', 'direction' => 'asc'])
            ->assertJsonPath('data.*.id', [$third->id, $first->id, $second->id]);
        $this->examRequest($user, $laboratory, ['sort' => 'name', 'direction' => 'desc'])
            ->assertJsonPath('data.*.id', [$second->id, $first->id, $third->id]);
        $this->examRequest($user, $laboratory, ['sort' => 'created_at', 'direction' => 'asc'])
            ->assertJsonPath('data.*.id', [$first->id, $second->id, $third->id]);
        $this->examRequest($user, $laboratory, ['sort' => 'created_at', 'direction' => 'desc'])
            ->assertJsonPath('data.*.id', [$third->id, $second->id, $first->id]);
    }

    public function test_pagination_supports_defaults_custom_pages_and_maximum(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = LaboratoryArea::factory()->for($laboratory)->create();
        $sampleType = SampleType::factory()->for($laboratory)->create();

        foreach (range(1, 18) as $number) {
            $this->createExam($laboratory, [
                'code' => sprintf('E%02d', $number),
                'name' => sprintf('Exam %02d', $number),
            ], $area, $sampleType);
        }

        $this->examRequest($user, $laboratory)
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 18);
        $this->examRequest($user, $laboratory, ['page' => 2])
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.current_page', 2);
        $this->examRequest($user, $laboratory, ['per_page' => 5, 'page' => 2])
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 5);
        $this->examRequest($user, $laboratory, ['per_page' => 1])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 1);
        $this->examRequest($user, $laboratory, ['per_page' => 100])
            ->assertJsonCount(18, 'data')
            ->assertJsonPath('meta.per_page', 100);
    }

    #[DataProvider('invalidQueryProvider')]
    public function test_invalid_prohibited_and_unknown_query_parameters_are_rejected(
        array $query,
        string $field,
    ): void {
        [$user, $laboratory] = $this->activeTenant();

        $this->examRequest($user, $laboratory, $query)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidQueryProvider(): array
    {
        return [
            'invalid status' => [['status' => 'pending'], 'status'],
            'uppercase status' => [['status' => 'ACTIVE'], 'status'],
            'status whitespace' => [['status' => ' active '], 'status'],
            'invalid sort id' => [['sort' => 'id'], 'sort'],
            'invalid sort status' => [['sort' => 'status'], 'sort'],
            'invalid sort updated' => [['sort' => 'updated_at'], 'sort'],
            'invalid sort area' => [['sort' => 'laboratory_area_id'], 'sort'],
            'invalid sort sample' => [['sort' => 'sample_type_id'], 'sort'],
            'invalid direction' => [['direction' => 'ascending'], 'direction'],
            'uppercase direction' => [['direction' => 'DESC'], 'direction'],
            'per page above max' => [['per_page' => 101], 'per_page'],
            'per page zero' => [['per_page' => 0], 'per_page'],
            'per page negative' => [['per_page' => -1], 'per_page'],
            'per page text' => [['per_page' => 'abc'], 'per_page'],
            'page zero' => [['page' => 0], 'page'],
            'page negative' => [['page' => -1], 'page'],
            'page text' => [['page' => 'abc'], 'page'],
            'area zero' => [['laboratory_area_id' => 0], 'laboratory_area_id'],
            'area negative' => [['laboratory_area_id' => -1], 'laboratory_area_id'],
            'area text' => [['laboratory_area_id' => 'abc'], 'laboratory_area_id'],
            'area decimal' => [['laboratory_area_id' => '1.5'], 'laboratory_area_id'],
            'area array' => [['laboratory_area_id' => [1]], 'laboratory_area_id'],
            'sample zero' => [['sample_type_id' => 0], 'sample_type_id'],
            'sample negative' => [['sample_type_id' => -1], 'sample_type_id'],
            'sample text' => [['sample_type_id' => 'abc'], 'sample_type_id'],
            'sample decimal' => [['sample_type_id' => '1.5'], 'sample_type_id'],
            'sample array' => [['sample_type_id' => [1]], 'sample_type_id'],
            'unknown foo' => [['foo' => 'bar'], 'foo'],
            'laboratory injection' => [['laboratory_id' => 1], 'laboratory_id'],
            'branch injection' => [['branch_id' => 1], 'branch_id'],
            'area alias' => [['area_id' => 1], 'area_id'],
            'sample alias' => [['tipo_muestra_id' => 1], 'tipo_muestra_id'],
            'include injection' => [['include' => 'anything'], 'include'],
            'with injection' => [['with' => 'anything'], 'with'],
        ];
    }

    public function test_inactive_related_catalogs_do_not_hide_the_exam(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = LaboratoryArea::factory()->for($laboratory)->create([
            'code' => 'OLD',
            'name' => 'Inactive Area',
            'status' => LaboratoryArea::STATUS_INACTIVE,
        ]);
        $sampleType = SampleType::factory()->inactive()->for($laboratory)->create([
            'name' => 'Inactive Sample',
        ]);
        $exam = $this->createExam($laboratory, [], $area, $sampleType);

        $this->examRequest($user, $laboratory)
            ->assertOk()
            ->assertJsonPath('data.0.id', $exam->id)
            ->assertJsonPath('data.0.laboratory_area.name', 'Inactive Area')
            ->assertJsonPath('data.0.sample_type.name', 'Inactive Sample');
    }

    public function test_get_does_not_write_or_change_any_timestamps_or_statuses(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = LaboratoryArea::factory()->for($laboratory)->create();
        $sampleType = SampleType::factory()->for($laboratory)->create();
        $exam = $this->createExam($laboratory, [], $area, $sampleType);
        $originalExam = $exam->getAttributes();
        $originalArea = $area->getAttributes();
        $originalSampleType = $sampleType->getAttributes();

        $this->examRequest($user, $laboratory)->assertOk();

        $this->assertEquals($originalExam, $exam->fresh()->getAttributes());
        $this->assertEquals($originalArea, $area->fresh()->getAttributes());
        $this->assertEquals($originalSampleType, $sampleType->fresh()->getAttributes());
    }

    public function test_eager_loading_has_a_stable_query_count_without_n_plus_one(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = LaboratoryArea::factory()->for($laboratory)->create();
        $sampleType = SampleType::factory()->for($laboratory)->create();
        $this->createExam($laboratory, [], $area, $sampleType);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->examRequest($user, $laboratory)->assertOk();
        $singleCount = $this->catalogQueryCount(DB::getQueryLog());

        LaboratoryExam::factory()->count(4)->create([
            'laboratory_id' => $laboratory->id,
            'laboratory_area_id' => $area->id,
            'sample_type_id' => $sampleType->id,
        ]);

        DB::flushQueryLog();
        $this->examRequest($user, $laboratory)->assertOk()->assertJsonCount(5, 'data');
        $multipleCount = $this->catalogQueryCount(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(4, $singleCount);
        $this->assertSame($singleCount, $multipleCount);
    }

    public function test_guest_and_missing_context_are_rejected_by_the_saas_pipeline(): void
    {
        $this->getJson('/api/v1/laboratory-exams')
            ->assertUnauthorized()
            ->assertJsonMissingPath('data');

        $this->actingAs(User::factory()->create(), 'web')
            ->getJson('/api/v1/laboratory-exams')
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED')
            ->assertJsonMissingPath('data');
    }

    public function test_invalid_nonexistent_and_inactive_laboratory_contexts_keep_existing_errors(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', 'abc')
            ->getJson('/api/v1/laboratory-exams')
            ->assertBadRequest()
            ->assertJsonPath('code', 'INVALID_LABORATORY_CONTEXT');

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', '999999')
            ->getJson('/api/v1/laboratory-exams')
            ->assertNotFound()
            ->assertJsonPath('code', 'LABORATORY_NOT_FOUND');

        $inactive = Laboratory::factory()->create(['is_active' => false]);
        $user->laboratories()->attach($inactive, ['is_active' => true]);

        $this->examRequest($user, $inactive)
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_INACTIVE');
    }

    public function test_membership_and_subscription_protections_are_inherited(): void
    {
        $user = User::factory()->create();
        $withoutMembership = Laboratory::factory()->create();
        $this->createCurrentSubscription($withoutMembership);

        $this->examRequest($user, $withoutMembership)
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $inactiveMembership = Laboratory::factory()->create();
        $user->laboratories()->attach($inactiveMembership, ['is_active' => false]);
        $this->createCurrentSubscription($inactiveMembership);

        $this->examRequest($user, $inactiveMembership)
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $withoutSubscription = Laboratory::factory()->create();
        $user->laboratories()->attach($withoutSubscription, ['is_active' => true]);

        $this->examRequest($user, $withoutSubscription)
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_representative_errors_do_not_leak_debug_details_when_debug_is_false(): void
    {
        config(['app.debug' => false]);
        [$user, $laboratory] = $this->activeTenant();

        $responses = [
            $this->examRequest($user, $laboratory, ['foo' => 'bar'])->assertUnprocessable(),
            $this->actingAs($user, 'web')
                ->withHeader('X-Laboratory-ID', '999999')
                ->getJson('/api/v1/laboratory-exams')
                ->assertNotFound(),
        ];

        foreach ($responses as $response) {
            $payload = $response->getContent();

            $this->assertStringNotContainsString('SQLSTATE', $payload);
            $this->assertStringNotContainsString('bindings', $payload);
            $this->assertStringNotContainsString('/var/www', $payload);
            $this->assertStringNotContainsString('Illuminate\\', $payload);
            $this->assertStringNotContainsString('laboratory_exams_', $payload);
            $this->assertStringNotContainsString('trace', $payload);
        }
    }

    /**
     * @return array{User, Laboratory}
     */
    private function activeTenant(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $this->createCurrentSubscription($laboratory);

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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createExam(
        Laboratory $laboratory,
        array $attributes = [],
        ?LaboratoryArea $area = null,
        ?SampleType $sampleType = null,
    ): LaboratoryExam {
        $area ??= LaboratoryArea::factory()->for($laboratory)->create();
        $sampleType ??= SampleType::factory()->for($laboratory)->create();

        return LaboratoryExam::factory()->create(array_merge([
            'laboratory_id' => $laboratory->id,
            'laboratory_area_id' => $area->id,
            'sample_type_id' => $sampleType->id,
        ], $attributes));
    }

    /**
     * @param  array<int, array{query: string, bindings: array<int, mixed>, time: float}>  $queries
     */
    private function catalogQueryCount(array $queries): int
    {
        return collect($queries)
            ->filter(fn (array $query): bool => str_contains($query['query'], 'laboratory_exams')
                || str_contains($query['query'], 'laboratory_areas')
                || str_contains($query['query'], 'sample_types'))
            ->count();
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function examRequest(
        User $user,
        Laboratory $laboratory,
        array $query = [],
    ): TestResponse {
        $uri = '/api/v1/laboratory-exams';

        if ($query !== []) {
            $uri .= '?'.http_build_query($query);
        }

        $this->assignDirectLaboratoryPermission($user, $laboratory, 'laboratory_exams.view');

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson($uri);
    }
}

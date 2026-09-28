<?php

namespace Tests\Feature\Api\V1\LaboratoryExams;

use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use App\Models\LaboratoryExam;
use App\Models\SampleType;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LaboratoryExamActiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-27 14:00:00', 'UTC'));
    }

    public function test_active_endpoint_returns_exact_lightweight_mixed_catalog(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $activeArea = $this->area($laboratory, ['code' => 'ACT', 'name' => 'Activa']);
        $inactiveArea = $this->area($laboratory, ['code' => 'INA', 'name' => 'Inactiva', 'status' => 'inactive']);
        $activeSample = $this->sample($laboratory, ['name' => 'Sangre']);
        $inactiveSample = $this->sample($laboratory, ['name' => 'Orina', 'status' => 'inactive']);
        $first = $this->exam($laboratory, $activeArea, $activeSample, ['code' => 'A-1', 'name' => 'Alfa']);
        $this->exam($laboratory, $activeArea, $activeSample, ['code' => 'I-1', 'name' => 'Invisible', 'status' => 'inactive']);
        $areaInactive = $this->exam($laboratory, $inactiveArea, $activeSample, ['code' => 'B-1', 'name' => 'Beta']);
        $sampleInactive = $this->exam($laboratory, $activeArea, $inactiveSample, ['code' => 'D-1', 'name' => 'Delta']);
        $bothInactive = $this->exam($laboratory, $inactiveArea, $inactiveSample, ['code' => 'G-1', 'name' => 'Gamma']);

        $response = $this->activeRequest($user, $laboratory)
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    $this->expectedItem($first, $activeArea, $activeSample),
                    $this->expectedItem($areaInactive, $inactiveArea, $activeSample),
                    $this->expectedItem($sampleInactive, $activeArea, $inactiveSample),
                    $this->expectedItem($bothInactive, $inactiveArea, $inactiveSample),
                ],
            ])
            ->assertJsonMissingPath('links')
            ->assertJsonMissingPath('meta');

        foreach ($response->json('data') as $item) {
            $this->assertEqualsCanonicalizing(['id', 'code', 'name', 'laboratory_area', 'sample_type'], array_keys($item));
            $this->assertEqualsCanonicalizing(['id', 'code', 'name'], array_keys($item['laboratory_area']));
            $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($item['sample_type']));
            foreach (['description', 'turnaround_time_minutes', 'status', 'created_at', 'updated_at', 'laboratory_id', 'laboratory_area_id', 'sample_type_id'] as $field) {
                $this->assertArrayNotHasKey($field, $item);
            }
        }
    }

    #[DataProvider('emptyCatalogProvider')]
    public function test_empty_or_only_inactive_catalog_returns_data_array(bool $createInactive): void
    {
        [$user, $laboratory] = $this->activeTenant();
        if ($createInactive) {
            $this->exam($laboratory, attributes: ['status' => 'inactive']);
        }

        $this->activeRequest($user, $laboratory)
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    /** @return array<string, array{bool}> */
    public static function emptyCatalogProvider(): array
    {
        return ['empty' => [false], 'only inactive' => [true]];
    }

    public function test_ordering_uses_name_then_id_and_preserves_duplicate_names(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $zulu = $this->exam($laboratory, attributes: ['code' => 'Z', 'name' => 'Zulu']);
        $sameFirst = $this->exam($laboratory, attributes: ['code' => 'S1', 'name' => 'Mismo']);
        $sameSecond = $this->exam($laboratory, attributes: ['code' => 'S2', 'name' => 'Mismo']);
        $alfa = $this->exam($laboratory, attributes: ['code' => 'A', 'name' => 'Alfa']);

        $response = $this->activeRequest($user, $laboratory)->assertOk();

        $this->assertSame([$alfa->id, $sameFirst->id, $sameSecond->id, $zulu->id], $response->json('data.*.id'));
        $this->assertSame(['Alfa', 'Mismo', 'Mismo', 'Zulu'], $response->json('data.*.name'));
    }

    public function test_all_active_exams_are_returned_without_pagination_or_limit(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = $this->area($laboratory);
        $sample = $this->sample($laboratory);
        foreach (range(1, 105) as $number) {
            $this->exam($laboratory, $area, $sample, [
                'code' => sprintf('EX-%03d', $number),
                'name' => sprintf('Examen %03d', $number),
            ]);
        }

        $this->activeRequest($user, $laboratory)
            ->assertOk()
            ->assertJsonCount(105, 'data')
            ->assertJsonMissingPath('links')
            ->assertJsonMissingPath('meta');
    }

    public function test_tenant_isolation_is_symmetric_during_context_switching(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $examA = $this->exam($labA, attributes: ['code' => 'A', 'name' => 'Examen A']);
        $this->exam($labA, attributes: ['code' => 'AI', 'name' => 'Inactivo A', 'status' => 'inactive']);
        $examB = $this->exam($labB, attributes: ['code' => 'B', 'name' => 'Examen B']);

        foreach ([[$labA, $examA], [$labB, $examB], [$labA, $examA], [$labB, $examB]] as [$laboratory, $expected]) {
            $response = $this->activeRequest($user, $laboratory)->assertOk()->assertJsonCount(1, 'data');
            $this->assertSame([$expected->id], $response->json('data.*.id'));
        }
    }

    #[DataProvider('queryParameterProvider')]
    public function test_every_query_parameter_is_rejected_without_writes(string $parameter, string $value): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $exam = $this->exam($laboratory);
        $before = $exam->getRawOriginal();

        $this->activeRequest($user, $laboratory, [$parameter => $value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$parameter]);

        $this->assertRawExamUnchanged($before, $exam);
    }

    /** @return array<string, array{string, string}> */
    public static function queryParameterProvider(): array
    {
        return [
            'search' => ['search', 'hem'],
            'status' => ['status', 'active'],
            'page' => ['page', '1'],
            'per page' => ['per_page', '10'],
            'area filter' => ['laboratory_area_id', '1'],
            'sample filter' => ['sample_type_id', '1'],
            'sort' => ['sort', 'name'],
            'direction' => ['direction', 'asc'],
            'tenant injection' => ['laboratory_id', '1'],
            'unknown' => ['foo', 'bar'],
        ];
    }

    public function test_multiple_query_parameters_are_rejected_together(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $this->activeRequest($user, $laboratory, ['foo' => 'bar', 'status' => 'active'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['foo', 'status']);
    }

    #[DataProvider('catalogSizeProvider')]
    public function test_catalog_uses_three_stable_selects_without_count_or_lazy_loading(int $count): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = $this->area($laboratory);
        $sample = $this->sample($laboratory);
        foreach (range(1, $count) as $number) {
            $this->exam($laboratory, $area, $sample, [
                'code' => "Q-{$number}",
                'name' => sprintf('Consulta %03d', $number),
            ]);
        }
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'laboratory_exams') || str_contains($sql, 'laboratory_areas') || str_contains($sql, 'sample_types')) {
                $queries[] = $query;
            }
        });

        Model::preventLazyLoading();
        try {
            $this->activeRequest($user, $laboratory)->assertOk()->assertJsonCount($count, 'data');
        } finally {
            Model::preventLazyLoading(false);
        }

        $selects = array_values(array_filter($queries, fn (QueryExecuted $query): bool => str_starts_with(strtolower($query->sql), 'select')));
        $this->assertCount(3, $selects);
        $examSql = strtolower($selects[0]->sql);
        $this->assertStringContainsString('"laboratory_id" = ?', $examSql);
        $this->assertStringContainsString('"status" = ?', $examSql);
        $this->assertStringContainsString('order by "name" asc, "id" asc', $examSql);
        $this->assertStringNotContainsString('count(', $examSql);
        $this->assertStringNotContainsString(' join ', $examSql);
        $this->assertSame([$laboratory->id, LaboratoryExam::STATUS_ACTIVE], $selects[0]->bindings);
        $this->assertFalse(collect($queries)->contains(fn (QueryExecuted $query): bool => str_contains(strtolower($query->sql), '"status"') && ! str_contains(strtolower($query->sql), 'laboratory_exams')));
    }

    /** @return array<string, array{int}> */
    public static function catalogSizeProvider(): array
    {
        return ['one exam' => [1], 'multiple exams' => [25]];
    }

    public function test_get_is_read_only_and_preserves_all_timestamps(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = $this->area($laboratory);
        $sample = $this->sample($laboratory);
        $exam = $this->exam($laboratory, $area, $sample);
        $before = [$exam->getRawOriginal(), $area->getRawOriginal(), $sample->getRawOriginal()];
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^(insert|update|delete)/i', ltrim($query->sql)) === 1) {
                $writes[] = $query->sql;
            }
        });

        $this->activeRequest($user, $laboratory)->assertOk();

        $this->assertSame([], $writes);
        $this->assertRawExamUnchanged($before[0], $exam);
        $this->assertSame($this->sorted($before[1]), $this->sorted($area->fresh()->getRawOriginal()));
        $this->assertSame($this->sorted($before[2]), $this->sorted($sample->fresh()->getRawOriginal()));
    }

    public function test_guest_and_missing_context_are_rejected_before_catalog_query(): void
    {
        $exam = LaboratoryExam::factory()->create(['name' => 'Secret Pipeline Exam']);
        $before = $exam->getRawOriginal();
        $this->getJson('/api/v1/laboratory-exams/active')->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'web')
            ->getJson('/api/v1/laboratory-exams/active')
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');
        $this->assertRawExamUnchanged($before, $exam);
    }

    public function test_invalid_nonexistent_and_inactive_laboratory_contexts_are_rejected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', 'invalid')
            ->getJson('/api/v1/laboratory-exams/active')
            ->assertBadRequest()->assertJsonPath('code', 'INVALID_LABORATORY_CONTEXT');
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', '999999999')
            ->getJson('/api/v1/laboratory-exams/active')
            ->assertNotFound()->assertJsonPath('code', 'LABORATORY_NOT_FOUND');

        $laboratory = Laboratory::factory()->create(['is_active' => false]);
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $this->createCurrentSubscription($laboratory);
        $this->activeRequest($user, $laboratory)
            ->assertForbidden()->assertJsonPath('code', 'LABORATORY_INACTIVE');
    }

    public function test_membership_and_subscription_protections_precede_catalog_query(): void
    {
        $user = User::factory()->create();
        $deniedLab = Laboratory::factory()->create();
        $user->laboratories()->attach($deniedLab, ['is_active' => false]);
        $this->createCurrentSubscription($deniedLab);
        $deniedExam = $this->exam($deniedLab);
        $this->activeRequest($user, $deniedLab)
            ->assertForbidden()->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $unsubscribedLab = Laboratory::factory()->create();
        $user->laboratories()->attach($unsubscribedLab, ['is_active' => true]);
        $unsubscribedExam = $this->exam($unsubscribedLab);
        $this->activeRequest($user, $unsubscribedLab)
            ->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');

        $this->assertSame('active', $deniedExam->refresh()->status);
        $this->assertSame('active', $unsubscribedExam->refresh()->status);
    }

    public function test_debug_false_representative_responses_do_not_leak_internals(): void
    {
        config(['app.debug' => false]);
        [$user, $laboratory] = $this->activeTenant();
        $guest = $this->getJson('/api/v1/laboratory-exams/active')->assertUnauthorized();
        $missingContext = $this->actingAs($user, 'web')
            ->getJson('/api/v1/laboratory-exams/active')->assertBadRequest();
        $this->exam($laboratory, attributes: ['name' => 'Visible']);
        $responses = [
            $guest,
            $missingContext,
            $this->activeRequest($user, $laboratory)->assertOk(),
            $this->activeRequest($user, $laboratory, ['foo' => 'bar'])->assertUnprocessable(),
        ];

        [, $emptyLab] = $this->activeTenant($user);
        $responses[] = $this->activeRequest($user, $emptyLab)->assertOk()->assertExactJson(['data' => []]);
        $deniedLab = Laboratory::factory()->create();
        $user->laboratories()->attach($deniedLab, ['is_active' => false]);
        $this->createCurrentSubscription($deniedLab);
        $responses[] = $this->activeRequest($user, $deniedLab)
            ->assertForbidden()->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');
        foreach ($responses as $response) {
            $this->assertNoLeakage($response);
        }
    }

    public function test_active_route_precedes_numeric_show_and_inventory_is_exact(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/laboratory-exams'))
            ->values();
        $this->assertCount(6, $routes);
        $this->assertSame([
            'api/v1/laboratory-exams',
            'api/v1/laboratory-exams',
            'api/v1/laboratory-exams/active',
            'api/v1/laboratory-exams/{laboratoryExam}',
            'api/v1/laboratory-exams/{laboratoryExam}',
            'api/v1/laboratory-exams/{laboratoryExam}/status',
        ], $routes->map(fn ($route): string => $route->uri())->all());

        $active = $routes->first(fn ($route): bool => str_ends_with($route->getActionName(), '@active'));
        $show = $routes->first(fn ($route): bool => str_ends_with($route->getActionName(), '@show'));
        $this->assertNotNull($active);
        $this->assertNotNull($show);
        $this->assertLessThan($routes->search($show), $routes->search($active));
        $this->assertContains('saas', $active->middleware());
        $this->assertSame('[0-9]+', $show->wheres['laboratoryExam']);

        [$user, $laboratory] = $this->activeTenant();
        $exam = $this->exam($laboratory);
        $this->activeRequest($user, $laboratory)->assertOk()->assertJsonPath('data.0.id', $exam->id);
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson("/api/v1/laboratory-exams/{$exam->id}")
            ->assertOk()->assertJsonPath('data.id', $exam->id);
    }

    /** @return array{User, Laboratory} */
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

    /** @param array<string, mixed> $attributes */
    private function area(Laboratory $laboratory, array $attributes = []): LaboratoryArea
    {
        return LaboratoryArea::factory()->for($laboratory)->create($attributes);
    }

    /** @param array<string, mixed> $attributes */
    private function sample(Laboratory $laboratory, array $attributes = []): SampleType
    {
        return SampleType::factory()->for($laboratory)->create($attributes);
    }

    /** @param array<string, mixed> $attributes */
    private function exam(
        Laboratory $laboratory,
        ?LaboratoryArea $area = null,
        ?SampleType $sample = null,
        array $attributes = [],
    ): LaboratoryExam {
        $area ??= $this->area($laboratory);
        $sample ??= $this->sample($laboratory);

        return LaboratoryExam::factory()->create(array_merge([
            'laboratory_id' => $laboratory->id,
            'laboratory_area_id' => $area->id,
            'sample_type_id' => $sample->id,
        ], $attributes));
    }

    /** @param array<string, string> $query */
    private function activeRequest(User $user, Laboratory $laboratory, array $query = []): TestResponse
    {
        $uri = '/api/v1/laboratory-exams/active';
        if ($query !== []) {
            $uri .= '?'.http_build_query($query);
        }

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson($uri);
    }

    /** @return array<string, mixed> */
    private function expectedItem(LaboratoryExam $exam, LaboratoryArea $area, SampleType $sample): array
    {
        return [
            'id' => $exam->id,
            'code' => $exam->code,
            'name' => $exam->name,
            'laboratory_area' => ['id' => $area->id, 'code' => $area->code, 'name' => $area->name],
            'sample_type' => ['id' => $sample->id, 'name' => $sample->name],
        ];
    }

    /** @param array<string, mixed> $before */
    private function assertRawExamUnchanged(array $before, LaboratoryExam $exam): void
    {
        $after = $exam->fresh()->getRawOriginal();
        ksort($before);
        ksort($after);
        $this->assertSame($before, $after);
    }

    /** @param array<string, mixed> $attributes */
    private function sorted(array $attributes): array
    {
        ksort($attributes);

        return $attributes;
    }

    private function assertNoLeakage(TestResponse $response): void
    {
        $body = strtolower($response->getContent());
        foreach (['sqlstate', 'bindings', '/var/www', 'app\\models', 'illuminate\\', 'laboratory_id', 'stack trace', 'constraint'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }
}

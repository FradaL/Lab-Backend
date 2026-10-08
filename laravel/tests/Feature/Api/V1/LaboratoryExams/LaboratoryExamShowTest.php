<?php

namespace Tests\Feature\Api\V1\LaboratoryExams;

use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use App\Models\LaboratoryExam;
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

class LaboratoryExamShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-26 12:00:00', 'UTC'));
    }

    public function test_detail_returns_the_exact_resource_contract(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = LaboratoryArea::factory()->for($laboratory)->create([
            'code' => 'HEM',
            'name' => 'Hematología',
        ]);
        $sampleType = SampleType::factory()->for($laboratory)->create([
            'name' => 'Sangre',
        ]);
        $exam = $this->createExam($laboratory, $area, $sampleType, [
            'code' => 'HEM-001',
            'name' => 'Hematología completa',
            'description' => 'Hemograma automatizado.',
            'turnaround_time_minutes' => 120,
        ]);

        $this->examRequest($user, $laboratory, $exam->id)
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $exam->id,
                    'code' => 'HEM-001',
                    'name' => 'Hematología completa',
                    'description' => 'Hemograma automatizado.',
                    'turnaround_time_minutes' => 120,
                    'status' => LaboratoryExam::STATUS_ACTIVE,
                    'laboratory_area' => [
                        'id' => $area->id,
                        'code' => 'HEM',
                        'name' => 'Hematología',
                    ],
                    'sample_type' => [
                        'id' => $sampleType->id,
                        'name' => 'Sangre',
                    ],
                    'created_at' => '2026-09-26T12:00:00.000000Z',
                    'updated_at' => '2026-09-26T12:00:00.000000Z',
                ],
            ])
            ->assertJsonMissingPath('data.laboratory_id')
            ->assertJsonMissingPath('data.laboratory_area_id')
            ->assertJsonMissingPath('data.sample_type_id')
            ->assertJsonMissingPath('data.laboratory')
            ->assertJsonMissingPath('data.prices')
            ->assertJsonMissingPath('data.results')
            ->assertJsonMissingPath('data.orders')
            ->assertJsonMissingPath('data.branch')
            ->assertJsonMissingPath('data.created_by')
            ->assertJsonMissingPath('data.updated_by');
    }

    #[DataProvider('inactiveVisibilityProvider')]
    public function test_inactive_exam_and_related_catalogs_remain_visible(
        string $examStatus,
        string $areaStatus,
        string $sampleStatus,
    ): void {
        [$user, $laboratory] = $this->activeTenant();
        $area = LaboratoryArea::factory()->for($laboratory)->create([
            'code' => 'CHEM',
            'name' => 'Química',
            'status' => $areaStatus,
        ]);
        $sampleType = SampleType::factory()->for($laboratory)->create([
            'name' => 'Suero',
            'status' => $sampleStatus,
        ]);
        $exam = $this->createExam($laboratory, $area, $sampleType, [
            'status' => $examStatus,
        ]);

        $this->examRequest($user, $laboratory, $exam->id)
            ->assertOk()
            ->assertJsonPath('data.status', $examStatus)
            ->assertJsonPath('data.laboratory_area', [
                'id' => $area->id,
                'code' => 'CHEM',
                'name' => 'Química',
            ])
            ->assertJsonPath('data.sample_type', [
                'id' => $sampleType->id,
                'name' => 'Suero',
            ]);
    }

    /** @return array<string, array{string, string, string}> */
    public static function inactiveVisibilityProvider(): array
    {
        return [
            'inactive exam' => [
                LaboratoryExam::STATUS_INACTIVE,
                LaboratoryArea::STATUS_ACTIVE,
                SampleType::STATUS_ACTIVE,
            ],
            'inactive area' => [
                LaboratoryExam::STATUS_ACTIVE,
                LaboratoryArea::STATUS_INACTIVE,
                SampleType::STATUS_ACTIVE,
            ],
            'inactive sample type' => [
                LaboratoryExam::STATUS_ACTIVE,
                LaboratoryArea::STATUS_ACTIVE,
                SampleType::STATUS_INACTIVE,
            ],
            'both related catalogs inactive' => [
                LaboratoryExam::STATUS_ACTIVE,
                LaboratoryArea::STATUS_INACTIVE,
                SampleType::STATUS_INACTIVE,
            ],
        ];
    }

    public function test_cross_tenant_and_nonexistent_exams_have_identical_neutral_404_contracts(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $examB = $this->createExam($labB, attributes: [
            'code' => 'SECRET-CROSS',
            'name' => 'Secret cross-tenant exam',
        ]);

        $crossTenant = $this->examRequest($user, $labA, $examB->id)
            ->assertNotFound();
        $nonexistent = $this->examRequest($user, $labA, 999999999)
            ->assertNotFound();

        $this->assertSame($crossTenant->getStatusCode(), $nonexistent->getStatusCode());
        $this->assertSame($crossTenant->headers->all(), $nonexistent->headers->all());
        $this->assertSame($crossTenant->json(), $nonexistent->json());
        $this->assertSame(['message' => 'Resource not found.'], $crossTenant->json());

        foreach ([$crossTenant, $nonexistent] as $response) {
            $this->assertResponseDoesNotLeak($response, [
                'SECRET-CROSS',
                'Secret cross-tenant exam',
                (string) $labB->id,
                '999999999',
            ]);
        }
    }

    public function test_tenant_isolation_is_symmetric_across_context_switches(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $examA = $this->createExam($labA, attributes: ['code' => 'A-ONLY']);
        $examB = $this->createExam($labB, attributes: ['code' => 'B-ONLY']);

        $this->examRequest($user, $labA, $examA->id)
            ->assertOk()
            ->assertJsonPath('data.id', $examA->id);
        $this->examRequest($user, $labB, $examB->id)
            ->assertOk()
            ->assertJsonPath('data.id', $examB->id);
        $this->examRequest($user, $labA, $examB->id)->assertNotFound();
        $this->examRequest($user, $labB, $examA->id)->assertNotFound();
        $this->examRequest($user, $labA, $examA->id)->assertOk();
        $this->examRequest($user, $labB, $examB->id)->assertOk();
    }

    #[DataProvider('invalidRouteIdentifierProvider')]
    public function test_non_numeric_route_segments_are_not_captured_or_looked_up(string $identifier): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $examQueries = [];

        DB::listen(function (QueryExecuted $query) use (&$examQueries): void {
            if (str_contains($query->sql, 'laboratory_exams')) {
                $examQueries[] = $query;
            }
        });

        $this->examRequest($user, $laboratory, $identifier)->assertNotFound();

        $this->assertCount(0, $examQueries);
    }

    /** @return array<string, array{string}> */
    public static function invalidRouteIdentifierProvider(): array
    {
        return [
            'alphabetic' => ['abc'],
            'reserved nonnumeric segment' => ['pending'],
            'decimal' => ['1.5'],
            'negative' => ['-1'],
        ];
    }

    public function test_lookup_is_tenant_scoped_and_eager_loads_exactly_two_relations_without_lazy_queries(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = LaboratoryArea::factory()->for($laboratory)->create();
        $sampleType = SampleType::factory()->for($laboratory)->create();
        $exam = $this->createExam($laboratory, $area, $sampleType);
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (
                str_contains($query->sql, 'laboratory_exams')
                || str_contains($query->sql, 'laboratory_areas')
                || str_contains($query->sql, 'sample_types')
            ) {
                $queries[] = $query;
            }
        });

        $this->examRequest($user, $laboratory, $exam->id)
            ->assertOk()
            ->assertJsonPath('data.laboratory_area.id', $area->id)
            ->assertJsonPath('data.sample_type.id', $sampleType->id);

        $examQueries = array_values(array_filter(
            $queries,
            fn (QueryExecuted $query): bool => str_contains($query->sql, 'from "laboratory_exams"'),
        ));
        $areaQueries = array_values(array_filter(
            $queries,
            fn (QueryExecuted $query): bool => str_contains($query->sql, 'from "laboratory_areas"'),
        ));
        $sampleQueries = array_values(array_filter(
            $queries,
            fn (QueryExecuted $query): bool => str_contains($query->sql, 'from "sample_types"'),
        ));

        $this->assertCount(3, $queries);
        $this->assertCount(1, $examQueries);
        $this->assertCount(1, $areaQueries);
        $this->assertCount(1, $sampleQueries);
        $this->assertStringContainsString('"laboratory_exams"."laboratory_id"', $examQueries[0]->sql);
        $this->assertStringContainsString('"laboratory_exams"."id"', $examQueries[0]->sql);
        $this->assertContains($laboratory->id, $examQueries[0]->bindings);
        $this->assertContains($exam->id, $examQueries[0]->bindings);
        $this->assertStringContainsString('select "id", "code", "name"', $areaQueries[0]->sql);
        $this->assertStringContainsString('select "id", "name"', $sampleQueries[0]->sql);
    }

    public function test_detail_is_read_only_and_preserves_all_relevant_timestamps(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = LaboratoryArea::factory()->for($laboratory)->create();
        $sampleType = SampleType::factory()->for($laboratory)->create();
        $exam = $this->createExam($laboratory, $area, $sampleType);
        $before = [
            'exam' => DB::table('laboratory_exams')->where('id', $exam->id)->first(),
            'area' => DB::table('laboratory_areas')->where('id', $area->id)->first(),
            'sample' => DB::table('sample_types')->where('id', $sampleType->id)->first(),
        ];
        $writes = [];

        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^(insert|update|delete)\b/i', ltrim($query->sql)) === 1) {
                $writes[] = $query->sql;
            }
        });

        $this->travel(10)->minutes();
        $this->examRequest($user, $laboratory, $exam->id)->assertOk();

        $after = [
            'exam' => DB::table('laboratory_exams')->where('id', $exam->id)->first(),
            'area' => DB::table('laboratory_areas')->where('id', $area->id)->first(),
            'sample' => DB::table('sample_types')->where('id', $sampleType->id)->first(),
        ];

        $this->assertSame([], $writes);
        $this->assertEquals($before, $after);
        $this->assertDatabaseCount('laboratory_exams', 1);
        $this->assertDatabaseCount('laboratory_areas', 1);
        $this->assertDatabaseCount('sample_types', 1);
    }

    public function test_authentication_and_missing_context_precede_exam_lookup(): void
    {
        $exam = LaboratoryExam::factory()->create([
            'code' => 'SECRET-PIPELINE',
            'name' => 'Secret pipeline exam',
        ]);
        $examQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$examQueries): void {
            if (str_contains($query->sql, 'laboratory_exams')) {
                $examQueries[] = $query;
            }
        });

        $guest = $this->getJson("/api/v1/laboratory-exams/{$exam->id}")
            ->assertUnauthorized();
        $missingContext = $this->actingAs(User::factory()->create(), 'web')
            ->getJson("/api/v1/laboratory-exams/{$exam->id}")
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');

        $this->assertCount(0, $examQueries);
        $this->assertResponseDoesNotLeak($guest, [$exam->code, $exam->name]);
        $this->assertResponseDoesNotLeak($missingContext, [$exam->code, $exam->name]);
    }

    public function test_invalid_nonexistent_and_inactive_laboratory_contexts_precede_exam_lookup(): void
    {
        $user = User::factory()->create();
        $exam = LaboratoryExam::factory()->create([
            'code' => 'SECRET-CONTEXT',
            'name' => 'Secret context exam',
        ]);
        $inactiveLab = Laboratory::factory()->create(['is_active' => false]);
        $user->laboratories()->attach($inactiveLab, ['is_active' => true]);

        $invalid = $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', 'abc')
            ->getJson("/api/v1/laboratory-exams/{$exam->id}")
            ->assertBadRequest()
            ->assertJsonPath('code', 'INVALID_LABORATORY_CONTEXT');
        $nonexistent = $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', '999999')
            ->getJson("/api/v1/laboratory-exams/{$exam->id}")
            ->assertNotFound()
            ->assertJsonPath('code', 'LABORATORY_NOT_FOUND');
        $inactive = $this->examRequest($user, $inactiveLab, $exam->id)
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_INACTIVE');

        foreach ([$invalid, $nonexistent, $inactive] as $response) {
            $this->assertResponseDoesNotLeak($response, [$exam->code, $exam->name]);
        }
    }

    public function test_membership_subscription_and_pipeline_precedence_block_before_exam_lookup(): void
    {
        $user = User::factory()->create();
        $withoutMembership = Laboratory::factory()->create();
        $this->createCurrentSubscription($withoutMembership);
        $exam = $this->createExam($withoutMembership, attributes: [
            'code' => 'SECRET-ACCESS',
            'name' => 'Secret access exam',
        ]);
        $examQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$examQueries): void {
            if (str_contains($query->sql, 'laboratory_exams')) {
                $examQueries[] = $query;
            }
        });

        $denied = $this->examRequest($user, $withoutMembership, $exam->id)
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $withoutSubscription = Laboratory::factory()->create();
        $user->laboratories()->attach($withoutSubscription, ['is_active' => true]);
        $subscriptionProtected = $this->examRequest($user, $withoutSubscription, $exam->id)
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');

        $this->assertCount(0, $examQueries);
        $this->assertResponseDoesNotLeak($denied, [$exam->code, $exam->name]);
        $this->assertResponseDoesNotLeak($subscriptionProtected, [$exam->code, $exam->name]);
    }

    public function test_debug_false_success_404_and_pipeline_errors_do_not_leak_internals(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $examA = $this->createExam($labA);
        $examB = $this->createExam($labB, attributes: [
            'code' => 'SECRET-DEBUG',
            'name' => 'Secret debug exam',
        ]);

        $responses = [
            $this->examRequest($user, $labA, $examA->id)->assertOk(),
            $this->examRequest($user, $labA, 999999999)->assertNotFound(),
            $this->examRequest($user, $labA, $examB->id)->assertNotFound(),
            $this->actingAs($user, 'web')
                ->withHeader('X-Laboratory-ID', 'invalid')
                ->getJson("/api/v1/laboratory-exams/{$examA->id}")
                ->assertBadRequest(),
        ];

        foreach ($responses as $response) {
            $this->assertResponseDoesNotLeak($response);
        }

        $this->assertResponseDoesNotLeak($responses[2], ['SECRET-DEBUG', 'Secret debug exam']);
    }

    public function test_route_registration_is_exact_numeric_and_saas_protected(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/laboratory-exams'))
            ->values();

        $this->assertCount(6, $routes);
        $this->assertSame([
            ['GET', 'HEAD'],
            ['POST'],
            ['GET', 'HEAD'],
            ['GET', 'HEAD'],
            ['PATCH'],
            ['PATCH'],
        ], $routes->map(fn ($route): array => $route->methods())->all());
        $this->assertSame([
            'api/v1/laboratory-exams',
            'api/v1/laboratory-exams',
            'api/v1/laboratory-exams/active',
            'api/v1/laboratory-exams/{laboratoryExam}',
            'api/v1/laboratory-exams/{laboratoryExam}',
            'api/v1/laboratory-exams/{laboratoryExam}/status',
        ], $routes->map(fn ($route): string => $route->uri())->all());

        $show = $routes->first(
            fn ($route): bool => str_ends_with($route->getActionName(), '@show'),
        );
        $this->assertNotNull($show);
        $this->assertSame('[0-9]+', $show->wheres['laboratoryExam']);
        $this->assertContains('saas', $show->middleware());
        $this->assertSame(
            'App\\Http\\Controllers\\Api\\V1\\LaboratoryExamController@show',
            $show->getActionName(),
        );
    }

    /** @return array{User, Laboratory} */
    private function activeTenant(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $this->createCurrentSubscription($laboratory);

        $this->assignDirectLaboratoryPermission($user, $laboratory, 'laboratory_exams.view');

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
        ?LaboratoryArea $area = null,
        ?SampleType $sampleType = null,
        array $attributes = [],
    ): LaboratoryExam {
        $area ??= LaboratoryArea::factory()->for($laboratory)->create();
        $sampleType ??= SampleType::factory()->for($laboratory)->create();

        return LaboratoryExam::factory()->create(array_merge([
            'laboratory_id' => $laboratory->id,
            'laboratory_area_id' => $area->id,
            'sample_type_id' => $sampleType->id,
        ], $attributes));
    }

    private function examRequest(
        User $user,
        Laboratory $laboratory,
        int|string $laboratoryExam,
    ): TestResponse {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson("/api/v1/laboratory-exams/{$laboratoryExam}");
    }

    /** @param array<int, string> $secrets */
    private function assertResponseDoesNotLeak(
        TestResponse $response,
        array $secrets = [],
    ): void {
        $payload = $response->getContent();

        foreach ([
            ...$secrets,
            'SQLSTATE',
            'bindings',
            '/var/www',
            'App\\Models',
            'Illuminate\\',
            'laboratory_id',
            'stack trace',
            'constraint',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $payload);
        }
    }
}

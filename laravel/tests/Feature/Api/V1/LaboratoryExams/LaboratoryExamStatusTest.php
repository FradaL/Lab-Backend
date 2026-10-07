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

class LaboratoryExamStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-27 12:00:00', 'UTC'));
    }

    #[DataProvider('transitionProvider')]
    public function test_transitions_are_persisted_and_naturally_idempotent(
        string $initial,
        string $requested,
        bool $changes,
    ): void {
        [$user, $laboratory] = $this->activeTenant();
        $exam = $this->createExam($laboratory, attributes: ['status' => $initial]);
        $createdAt = $exam->created_at?->toISOString();
        $updatedAt = $exam->updated_at?->toISOString();
        $this->travel(5)->minutes();

        $this->statusRequest($user, $laboratory, $exam->id, ['status' => $requested])
            ->assertOk()
            ->assertJsonPath('data.status', $requested);

        $exam->refresh();
        $this->assertSame($requested, $exam->status);
        $this->assertSame($createdAt, $exam->created_at?->toISOString());
        $this->assertSame(
            $changes ? '2026-09-27T12:05:00.000000Z' : $updatedAt,
            $exam->updated_at?->toISOString(),
        );
    }

    /** @return array<string, array{string, string, bool}> */
    public static function transitionProvider(): array
    {
        return [
            'active to inactive' => ['active', 'inactive', true],
            'inactive to active' => ['inactive', 'active', true],
            'active to active' => ['active', 'active', false],
            'inactive to inactive' => ['inactive', 'inactive', false],
        ];
    }

    public function test_success_returns_exact_resource_and_preserves_all_other_exam_fields(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = LaboratoryArea::factory()->for($laboratory)->create([
            'code' => 'HEM',
            'name' => 'Hematología',
        ]);
        $sample = SampleType::factory()->for($laboratory)->create(['name' => 'Sangre']);
        $exam = $this->createExam($laboratory, $area, $sample, [
            'code' => 'HEM-001',
            'name' => 'Hemograma',
            'description' => 'Descripción original',
            'turnaround_time_minutes' => 120,
        ]);
        $before = $exam->getRawOriginal();

        $response = $this->statusRequest($user, $laboratory, $exam->id, ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonMissingPath('data.laboratory_id')
            ->assertJsonMissingPath('data.laboratory_area_id')
            ->assertJsonMissingPath('data.sample_type_id')
            ->assertJsonMissingPath('data.laboratory');

        $this->assertEqualsCanonicalizing([
            'id', 'code', 'name', 'description', 'turnaround_time_minutes', 'status',
            'laboratory_area', 'sample_type', 'created_at', 'updated_at',
        ], array_keys($response->json('data')));
        $this->assertEqualsCanonicalizing(['id', 'code', 'name'], array_keys($response->json('data.laboratory_area')));
        $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($response->json('data.sample_type')));

        $after = $exam->refresh()->getRawOriginal();
        foreach (['laboratory_id', 'laboratory_area_id', 'sample_type_id', 'code', 'name', 'description', 'turnaround_time_minutes', 'created_at'] as $field) {
            $this->assertEquals($before[$field], $after[$field], "The {$field} field changed.");
        }
    }

    #[DataProvider('invalidStatusProvider')]
    public function test_invalid_status_values_are_rejected_without_writes(mixed $status): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $exam = $this->createExam($laboratory);
        $before = $exam->getRawOriginal();

        $this->statusRequest($user, $laboratory, $exam->id, ['status' => $status])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertExamUnchanged($before, $exam);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidStatusProvider(): array
    {
        return [
            'uppercase' => ['ACTIVE'],
            'mixed case' => ['Inactive'],
            'leading whitespace' => [' active'],
            'trailing whitespace' => ['active '],
            'surrounding whitespace' => [' active '],
            'null' => [null],
            'empty string' => [''],
            'boolean' => [true],
            'integer' => [1],
            'unknown value' => ['enabled'],
        ];
    }

    public function test_empty_payload_is_rejected_without_writes(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $exam = $this->createExam($laboratory);
        $before = $exam->getRawOriginal();

        $this->statusRequest($user, $laboratory, $exam->id, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertExamUnchanged($before, $exam);
    }

    #[DataProvider('forbiddenFieldProvider')]
    public function test_unknown_fields_reject_the_whole_payload_atomically(string $field, mixed $value): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $exam = $this->createExam($laboratory);
        $before = $exam->getRawOriginal();

        $this->statusRequest($user, $laboratory, $exam->id, [
            'status' => 'inactive',
            $field => $value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertExamUnchanged($before, $exam);
    }

    /** @return array<string, array{string, mixed}> */
    public static function forbiddenFieldProvider(): array
    {
        return [
            'id' => ['id', 9],
            'code' => ['code', 'HACKED'],
            'name' => ['name', 'HACKED'],
            'description' => ['description', 'HACKED'],
            'turnaround' => ['turnaround_time_minutes', 1],
            'area' => ['laboratory_area_id', 1],
            'sample type' => ['sample_type_id', 1],
            'laboratory' => ['laboratory_id', 1],
            'created at' => ['created_at', '2020-01-01'],
            'updated at' => ['updated_at', '2020-01-01'],
            'branch' => ['branch_id', 1],
            'price' => ['price', 10],
            'cost' => ['cost', 5],
            'unknown' => ['foo', 'bar'],
        ];
    }

    #[DataProvider('relationIndependenceProvider')]
    public function test_status_is_independent_from_inactive_relations_and_does_not_cascade(
        string $areaStatus,
        string $sampleStatus,
        string $initialStatus,
        string $requestedStatus,
    ): void {
        [$user, $laboratory] = $this->activeTenant();
        $area = LaboratoryArea::factory()->for($laboratory)->create(['status' => $areaStatus]);
        $sample = SampleType::factory()->for($laboratory)->create(['status' => $sampleStatus]);
        $exam = $this->createExam($laboratory, $area, $sample, ['status' => $initialStatus]);
        $other = $this->createExam($laboratory, $area, $sample, ['status' => $initialStatus]);

        $this->statusRequest($user, $laboratory, $exam->id, ['status' => $requestedStatus])
            ->assertOk()
            ->assertJsonPath('data.laboratory_area.id', $area->id)
            ->assertJsonPath('data.sample_type.id', $sample->id);

        $this->assertSame($requestedStatus, $exam->refresh()->status);
        $this->assertSame($areaStatus, $area->refresh()->status);
        $this->assertSame($sampleStatus, $sample->refresh()->status);
        $this->assertSame($initialStatus, $other->refresh()->status);
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function relationIndependenceProvider(): array
    {
        return [
            'reactivate with inactive area' => ['inactive', 'active', 'inactive', 'active'],
            'reactivate with inactive sample type' => ['active', 'inactive', 'inactive', 'active'],
            'reactivate with both inactive' => ['inactive', 'inactive', 'inactive', 'active'],
            'deactivate with both inactive' => ['inactive', 'inactive', 'active', 'inactive'],
        ];
    }

    public function test_cross_tenant_and_nonexistent_valid_or_invalid_payloads_share_neutral_404(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $examB = $this->createExam($labB);
        $before = $examB->getRawOriginal();
        $responses = [
            $this->statusRequest($user, $labA, $examB->id, ['status' => 'inactive']),
            $this->statusRequest($user, $labA, $examB->id, ['status' => 'INVALID', 'name' => 'Injected']),
            $this->statusRequest($user, $labA, 999999999, ['status' => 'inactive']),
            $this->statusRequest($user, $labA, 999999999, ['status' => 'INVALID']),
        ];

        foreach ($responses as $response) {
            $response->assertNotFound()->assertExactJson(['message' => 'Resource not found.']);
            $this->assertNoLeakage($response);
        }
        $this->assertSame($responses[0]->getContent(), $responses[1]->getContent());
        $this->assertSame($responses[0]->getContent(), $responses[2]->getContent());
        $this->assertSame($responses[0]->getContent(), $responses[3]->getContent());
        $this->assertExamUnchanged($before, $examB);
    }

    public function test_symmetric_isolation_and_context_switching(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $examA = $this->createExam($labA);
        $examB = $this->createExam($labB);

        $this->statusRequest($user, $labA, $examA->id, ['status' => 'inactive'])->assertOk();
        $this->statusRequest($user, $labB, $examA->id, ['status' => 'active'])->assertNotFound();
        $this->statusRequest($user, $labB, $examB->id, ['status' => 'inactive'])->assertOk();
        $this->statusRequest($user, $labA, $examB->id, ['status' => 'active'])->assertNotFound();
        $this->statusRequest($user, $labA, $examA->id, ['status' => 'active'])->assertOk();

        $this->assertSame('active', $examA->refresh()->status);
        $this->assertSame('inactive', $examB->refresh()->status);
    }

    #[DataProvider('invalidRouteProvider')]
    public function test_invalid_route_values_are_not_routable(string $id): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $this->statusRequest($user, $laboratory, $id, ['status' => 'inactive'])->assertNotFound();
    }

    /** @return array<string, array{string}> */
    public static function invalidRouteProvider(): array
    {
        return [
            'letters' => ['abc'],
            'future active endpoint' => ['active'],
            'decimal' => ['1.5'],
            'negative' => ['-1'],
        ];
    }

    public function test_guest_and_saas_pipeline_failures_do_not_write(): void
    {
        $exam = LaboratoryExam::factory()->create();
        $before = $exam->getRawOriginal();
        $this->patchJson("/api/v1/laboratory-exams/{$exam->id}/status", ['status' => 'inactive'])
            ->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user, 'web')
            ->patchJson("/api/v1/laboratory-exams/{$exam->id}/status", ['status' => 'inactive'])
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', 'invalid')
            ->patchJson("/api/v1/laboratory-exams/{$exam->id}/status", ['status' => 'inactive'])
            ->assertBadRequest()
            ->assertJsonPath('code', 'INVALID_LABORATORY_CONTEXT');
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', '999999999')
            ->patchJson("/api/v1/laboratory-exams/{$exam->id}/status", ['status' => 'inactive'])
            ->assertNotFound()
            ->assertJsonPath('code', 'LABORATORY_NOT_FOUND');

        $this->assertExamUnchanged($before, $exam);
    }

    public function test_inactive_laboratory_membership_and_subscription_failures_do_not_write(): void
    {
        $user = User::factory()->create();
        $inactiveLab = Laboratory::factory()->create(['is_active' => false]);
        $user->laboratories()->attach($inactiveLab, ['is_active' => true]);
        $this->createCurrentSubscription($inactiveLab);
        $inactiveExam = $this->createExam($inactiveLab);
        $this->statusRequest($user, $inactiveLab, $inactiveExam->id, ['status' => 'inactive'])
            ->assertForbidden()->assertJsonPath('code', 'LABORATORY_INACTIVE');

        $deniedLab = Laboratory::factory()->create();
        $user->laboratories()->attach($deniedLab, ['is_active' => false]);
        $this->createCurrentSubscription($deniedLab);
        $deniedExam = $this->createExam($deniedLab);
        $this->statusRequest($user, $deniedLab, $deniedExam->id, ['status' => 'inactive'])
            ->assertForbidden()->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $unsubscribedLab = Laboratory::factory()->create();
        $user->laboratories()->attach($unsubscribedLab, ['is_active' => true]);
        $unsubscribedExam = $this->createExam($unsubscribedLab);
        $this->statusRequest($user, $unsubscribedLab, $unsubscribedExam->id, ['status' => 'inactive'])
            ->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');

        $this->assertSame('active', $inactiveExam->refresh()->status);
        $this->assertSame('active', $deniedExam->refresh()->status);
        $this->assertSame('active', $unsubscribedExam->refresh()->status);
    }

    public function test_query_behavior_is_tenant_scoped_without_relation_validation_or_idempotent_update(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $exam = $this->createExam($laboratory);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->statusRequest($user, $laboratory, $exam->id, ['status' => 'active'])->assertOk();

        $examLookups = array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "laboratory_exams"')));
        $updates = array_values(array_filter($queries, fn (string $sql): bool => str_starts_with($sql, 'update "laboratory_exams"')));
        $this->assertNotEmpty($examLookups);
        $this->assertStringContainsString('"laboratory_id" = ?', $examLookups[0]);
        $this->assertStringContainsString('"laboratory_exams"."id" = ?', $examLookups[0]);
        $this->assertSame([], $updates);
        $this->assertFalse(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'exists') && (str_contains($sql, 'laboratory_areas') || str_contains($sql, 'sample_types'))));
    }

    public function test_real_change_performs_one_exam_update(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $exam = $this->createExam($laboratory);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->statusRequest($user, $laboratory, $exam->id, ['status' => 'inactive'])->assertOk();
        $updates = array_values(array_filter($queries, fn (string $sql): bool => str_starts_with($sql, 'update "laboratory_exams"')));
        $this->assertCount(1, $updates);
        $this->assertStringContainsString('"status" = ?', $updates[0]);
    }

    public function test_debug_false_representative_responses_do_not_leak_internals(): void
    {
        config(['app.debug' => false]);
        [$user, $laboratory] = $this->activeTenant();
        $exam = $this->createExam($laboratory);
        $responses = [
            $this->statusRequest($user, $laboratory, $exam->id, ['status' => 'inactive'])->assertOk(),
            $this->statusRequest($user, $laboratory, 999999999, ['status' => 'inactive'])->assertNotFound(),
            $this->statusRequest($user, $laboratory, $exam->id, ['status' => 'INVALID'])->assertUnprocessable(),
            $this->statusRequest($user, $laboratory, $exam->id, ['status' => 'active', 'name' => 'Injected'])->assertUnprocessable(),
        ];
        foreach ($responses as $response) {
            $this->assertNoLeakage($response);
        }
    }

    public function test_route_registration_is_exact_numeric_and_saas_protected(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/laboratory-exams'))
            ->values();
        $this->assertCount(6, $routes);
        $status = $routes->first(fn ($route): bool => str_ends_with($route->uri(), '/status'));
        $this->assertNotNull($status);
        $this->assertSame(['PATCH'], $status->methods());
        $this->assertSame('[0-9]+', $status->wheres['laboratoryExam']);
        $this->assertContains('saas', $status->middleware());
        $this->assertSame('App\\Http\\Controllers\\Api\\V1\\LaboratoryExamController@updateStatus', $status->getActionName());
        $this->assertTrue($routes->contains(fn ($route): bool => $route->uri() === 'api/v1/laboratory-exams/active'));
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
    private function createExam(
        Laboratory $laboratory,
        ?LaboratoryArea $area = null,
        ?SampleType $sample = null,
        array $attributes = [],
    ): LaboratoryExam {
        $area ??= LaboratoryArea::factory()->for($laboratory)->create();
        $sample ??= SampleType::factory()->for($laboratory)->create();

        return LaboratoryExam::factory()->create(array_merge([
            'laboratory_id' => $laboratory->id,
            'laboratory_area_id' => $area->id,
            'sample_type_id' => $sample->id,
        ], $attributes));
    }

    /** @param array<string, mixed> $payload */
    private function statusRequest(User $user, Laboratory $laboratory, int|string $exam, array $payload): TestResponse
    {
        $this->assignDirectLaboratoryPermission($user, $laboratory, 'laboratory_exams.change_status');

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson("/api/v1/laboratory-exams/{$exam}/status", $payload);
    }

    /** @param array<string, mixed> $before */
    private function assertExamUnchanged(array $before, LaboratoryExam $exam): void
    {
        $after = $exam->fresh()->getRawOriginal();
        ksort($before);
        ksort($after);
        $this->assertSame($before, $after);
    }

    private function assertNoLeakage(TestResponse $response): void
    {
        $body = strtolower($response->getContent());
        foreach (['sqlstate', 'bindings', '/var/www', 'app\\models', 'illuminate\\', 'laboratory_id', 'stack trace', 'constraint'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }
}

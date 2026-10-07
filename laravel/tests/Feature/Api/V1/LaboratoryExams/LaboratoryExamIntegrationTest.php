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
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LaboratoryExamIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-27 12:00:00', 'UTC'));
    }

    public function test_complete_lifecycle_stays_synchronized_across_all_six_endpoints(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        [$area, $sample] = $this->catalogs($laboratory);

        $created = $this->postExam($user, $laboratory, $area, $sample, [
            'code' => '  HEM-ÁCIDO  ',
            'name' => '  Hematología completa  ',
            'description' => '  Ácido úrico y química sanguínea  ',
        ])->assertCreated();
        $examId = $created->json('data.id');
        $createdAt = $created->json('data.created_at');

        $created
            ->assertJsonPath('data.code', 'HEM-ÁCIDO')
            ->assertJsonPath('data.name', 'Hematología completa')
            ->assertJsonPath('data.description', 'Ácido úrico y química sanguínea')
            ->assertJsonPath('data.status', LaboratoryExam::STATUS_ACTIVE);

        $admin = $this->getExam($user, $laboratory, '')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$examId]);
        $active = $this->getExam($user, $laboratory, '/active')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$examId]);
        $detail = $this->getExam($user, $laboratory, "/{$examId}")
            ->assertOk();

        $this->assertSame($created->json('data'), $detail->json('data'));
        $this->assertSame($detail->json('data'), $admin->json('data.0'));
        $this->assertSame(
            ['code', 'id', 'laboratory_area', 'name', 'sample_type'],
            $this->sortedKeys($active->json('data.0')),
        );

        $this->travel(5)->minutes();
        $updated = $this->patchExam($user, $laboratory, $examId, [
            'name' => '  Hematología integral  ',
            'description' => '   ',
            'turnaround_time_minutes' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Hematología integral')
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.turnaround_time_minutes', null);
        $this->assertSame($createdAt, $updated->json('data.created_at'));
        $this->assertNotSame($created->json('data.updated_at'), $updated->json('data.updated_at'));

        $this->patchStatus($user, $laboratory, $examId, LaboratoryExam::STATUS_INACTIVE)
            ->assertOk()
            ->assertJsonPath('data.status', LaboratoryExam::STATUS_INACTIVE);
        $this->getExam($user, $laboratory, '')
            ->assertJsonPath('data.*.id', [$examId])
            ->assertJsonPath('data.0.status', LaboratoryExam::STATUS_INACTIVE);
        $this->getExam($user, $laboratory, '/active')
            ->assertExactJson(['data' => []]);
        $this->getExam($user, $laboratory, "/{$examId}")
            ->assertOk()
            ->assertJsonPath('data.status', LaboratoryExam::STATUS_INACTIVE);

        $this->travel(5)->minutes();
        $this->patchExam($user, $laboratory, $examId, [
            'code' => 'HEM-FINAL',
            'name' => 'Hematología final',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', LaboratoryExam::STATUS_INACTIVE);

        $area->update(['status' => LaboratoryArea::STATUS_INACTIVE]);
        $sample->update(['status' => SampleType::STATUS_INACTIVE]);
        $this->getExam($user, $laboratory, "/{$examId}")
            ->assertOk()
            ->assertJsonPath('data.laboratory_area.id', $area->id)
            ->assertJsonPath('data.sample_type.id', $sample->id);

        $reactivated = $this->patchStatus($user, $laboratory, $examId, LaboratoryExam::STATUS_ACTIVE)
            ->assertOk()
            ->assertJsonPath('data.status', LaboratoryExam::STATUS_ACTIVE);
        $this->getExam($user, $laboratory, '/active')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$examId]);

        $final = $this->getExam($user, $laboratory, "/{$examId}")->assertOk();
        $final
            ->assertJsonPath('data.code', 'HEM-FINAL')
            ->assertJsonPath('data.name', 'Hematología final')
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.turnaround_time_minutes', null);
        $this->assertSame($createdAt, $final->json('data.created_at'));
        $this->assertSame($reactivated->json('data.updated_at'), $final->json('data.updated_at'));
    }

    public function test_two_tenant_lifecycles_remain_symmetric_during_context_switching(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        [$areaA, $sampleA] = $this->catalogs($labA);
        [$areaB, $sampleB] = $this->catalogs($labB);

        $examA = $this->postExam($user, $labA, $areaA, $sampleA, ['code' => 'SHARED'])
            ->assertCreated()->json('data.id');
        $examB = $this->postExam($user, $labB, $areaB, $sampleB, ['code' => 'SHARED'])
            ->assertCreated()->json('data.id');
        $this->postExam($user, $labA, $areaA, $sampleA, ['code' => 'shared'])
            ->assertCreated();
        $this->postExam($user, $labA, $areaA, $sampleA, ['code' => 'SHARED'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);

        foreach ([[$labA, $examA], [$labB, $examB], [$labA, $examA], [$labB, $examB]] as [$laboratory, $examId]) {
            $this->getExam($user, $laboratory, "/{$examId}")->assertOk();
            $this->getExam($user, $laboratory, '')
                ->assertOk()
                ->assertJsonFragment(['id' => $examId]);
            $this->getExam($user, $laboratory, '/active')
                ->assertOk()
                ->assertJsonFragment(['id' => $examId]);
        }

        foreach ([[$labA, $examB], [$labB, $examA]] as [$laboratory, $foreignExam]) {
            $missing = $this->getExam($user, $laboratory, '/999999999')->assertNotFound();
            $show = $this->getExam($user, $laboratory, "/{$foreignExam}")->assertNotFound();
            $update = $this->patchExam($user, $laboratory, $foreignExam, [
                'name' => '',
                'laboratory_id' => $laboratory->id,
            ])->assertNotFound();
            $status = $this->patchStatus($user, $laboratory, $foreignExam, 'invalid', [
                'laboratory_id' => $laboratory->id,
            ])->assertNotFound();

            $this->assertSame($missing->json(), $show->json());
            $this->assertSame($missing->json(), $update->json());
            $this->assertSame($missing->json(), $status->json());
        }

        $this->postExam($user, $labA, $areaB, $sampleA, ['code' => 'CROSS-AREA'])
            ->assertUnprocessable()->assertJsonValidationErrors(['laboratory_area_id']);
        $this->postExam($user, $labA, $areaA, $sampleB, ['code' => 'CROSS-SAMPLE'])
            ->assertUnprocessable()->assertJsonValidationErrors(['sample_type_id']);
        $this->patchExam($user, $labA, $examA, ['laboratory_area_id' => $areaB->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['laboratory_area_id']);

        $this->patchExam($user, $labA, $examA, ['name' => 'A final'])->assertOk();
        $this->patchStatus($user, $labB, $examB, LaboratoryExam::STATUS_INACTIVE)->assertOk();
        $this->getExam($user, $labA, '/active')->assertJsonFragment(['id' => $examA]);
        $this->getExam($user, $labB, '/active')->assertJsonMissing(['id' => $examB]);

        $this->assertDatabaseHas('laboratory_exams', [
            'id' => $examA,
            'laboratory_id' => $labA->id,
            'name' => 'A final',
            'status' => LaboratoryExam::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('laboratory_exams', [
            'id' => $examB,
            'laboratory_id' => $labB->id,
            'status' => LaboratoryExam::STATUS_INACTIVE,
        ]);
    }

    public function test_search_filters_sorting_and_pagination_compose_without_tenant_leaks(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        [$areaA, $sampleA] = $this->catalogs($labA);
        [$otherAreaA, $otherSampleA] = $this->catalogs($labA);
        [$areaB, $sampleB] = $this->catalogs($labB);

        $first = $this->exam($labA, $areaA, $sampleA, [
            'code' => 'MATCH-02',
            'name' => 'Química sanguínea',
        ]);
        $second = $this->exam($labA, $areaA, $sampleA, [
            'code' => 'MATCH-01',
            'name' => 'Química sanguínea',
        ]);
        $this->exam($labA, $otherAreaA, $sampleA, ['code' => 'MATCH-WRONG-AREA']);
        $this->exam($labA, $areaA, $otherSampleA, ['code' => 'MATCH-WRONG-SAMPLE']);
        $this->exam($labA, $areaA, $sampleA, [
            'code' => 'MATCH-INACTIVE',
            'status' => LaboratoryExam::STATUS_INACTIVE,
        ]);
        $this->exam($labB, $areaB, $sampleB, [
            'code' => 'MATCH-SECRET',
            'name' => 'Química sanguínea secreta',
        ]);

        $query = [
            'search' => '  QUÍMICA  ',
            'status' => 'active',
            'laboratory_area_id' => $areaA->id,
            'sample_type_id' => $sampleA->id,
            'sort' => 'name',
            'direction' => 'asc',
            'per_page' => 1,
        ];

        $pageOne = $this->getExam($user, $labA, '', $query + ['page' => 1])
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonCount(1, 'data');
        $pageTwo = $this->getExam($user, $labA, '', $query + ['page' => 2])
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonCount(1, 'data');

        $this->assertSame([$first->id, $second->id], [
            $pageOne->json('data.0.id'),
            $pageTwo->json('data.0.id'),
        ]);
        $this->assertNotContains('MATCH-SECRET', [
            $pageOne->json('data.0.code'),
            $pageTwo->json('data.0.code'),
        ], true);
    }

    public function test_pipeline_precedence_and_debug_false_errors_are_atomic_and_safe(): void
    {
        config(['app.debug' => false]);
        $guest = $this->getJson('/api/v1/laboratory-exams')->assertUnauthorized();
        $user = User::factory()->create();
        [, $allowed] = $this->activeTenant($user);
        [$area, $sample] = $this->catalogs($allowed);
        $exam = $this->exam($allowed, $area, $sample);
        $withoutSubscription = Laboratory::factory()->create();
        $user->laboratories()->attach($withoutSubscription, ['is_active' => true]);
        $foreign = $this->exam($withoutSubscription);
        $before = LaboratoryExam::query()->findOrFail($foreign->id)->getRawOriginal();

        $responses = [
            $guest,
            $this->actingAs($user, 'web')
                ->withHeader('X-Laboratory-ID', (string) $withoutSubscription->id)
                ->patchJson("/api/v1/laboratory-exams/{$foreign->id}", ['name' => ''])
                ->assertForbidden()
                ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED'),
            $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', 'invalid')
                ->getJson('/api/v1/laboratory-exams')->assertBadRequest()
                ->assertJsonPath('code', 'INVALID_LABORATORY_CONTEXT'),
            $this->patchExam($user, $allowed, 999999999, ['name' => ''])->assertNotFound(),
            $this->patchExam($user, $allowed, $exam->id, ['laboratory_id' => $allowed->id])
                ->assertUnprocessable(),
        ];

        foreach ($responses as $response) {
            $this->assertSafeJson($response);
        }

        $this->assertSame(
            $before,
            LaboratoryExam::query()->findOrFail($foreign->id)->getRawOriginal(),
        );
        $this->assertSame($allowed->id, LaboratoryExam::query()->findOrFail($exam->id)->laboratory_id);
    }

    public function test_runtime_and_openapi_inventories_match_the_six_endpoint_contract(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/laboratory-exams'))
            ->map(fn ($route): array => [
                'method' => collect($route->methods())->first(fn (string $method): bool => $method !== 'HEAD'),
                'path' => '/'.$route->uri(),
                'middleware' => $route->middleware(),
            ])
            ->values();

        $this->assertCount(6, $routes);
        foreach ($routes as $route) {
            $this->assertContains('saas', $route['middleware']);
        }

        $document = json_decode(
            file_get_contents(storage_path('api-docs/api-docs.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertSame('3.1.0', $document['openapi']);

        $operations = collect($document['paths'])
            ->filter(fn (array $path, string $name): bool => str_starts_with($name, '/api/v1/laboratory-exams'))
            ->flatMap(fn (array $path, string $name): array => collect($path)
                ->filter(fn (mixed $operation, string $method): bool => in_array($method, ['get', 'post', 'patch'], true))
                ->map(fn (array $operation, string $method): array => [
                    'method' => strtoupper($method),
                    'path' => $name,
                    'operation_id' => $operation['operationId'],
                ])->values()->all())
            ->values();

        $expected = [
            ['method' => 'GET', 'path' => '/api/v1/laboratory-exams'],
            ['method' => 'POST', 'path' => '/api/v1/laboratory-exams'],
            ['method' => 'GET', 'path' => '/api/v1/laboratory-exams/active'],
            ['method' => 'GET', 'path' => '/api/v1/laboratory-exams/{laboratoryExam}'],
            ['method' => 'PATCH', 'path' => '/api/v1/laboratory-exams/{laboratoryExam}'],
            ['method' => 'PATCH', 'path' => '/api/v1/laboratory-exams/{laboratoryExam}/status'],
        ];
        $runtimeInventory = $routes->map(fn (array $route): array => [
            'method' => $route['method'],
            'path' => $route['path'],
        ])->all();
        $openApiInventory = $operations->map(fn (array $operation): array => [
            'method' => $operation['method'],
            'path' => $operation['path'],
        ])->all();

        $this->assertEqualsCanonicalizing($expected, $runtimeInventory);
        $this->assertEqualsCanonicalizing($expected, $openApiInventory);
        $this->assertCount(6, $operations->pluck('operation_id')->unique());
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

    /** @return array{LaboratoryArea, SampleType} */
    private function catalogs(Laboratory $laboratory): array
    {
        return [
            LaboratoryArea::factory()->for($laboratory)->create(),
            SampleType::factory()->for($laboratory)->create(),
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function exam(
        Laboratory $laboratory,
        ?LaboratoryArea $area = null,
        ?SampleType $sample = null,
        array $attributes = [],
    ): LaboratoryExam {
        [$defaultArea, $defaultSample] = $this->catalogs($laboratory);

        return LaboratoryExam::factory()->create(array_replace([
            'laboratory_id' => $laboratory->id,
            'laboratory_area_id' => ($area ?? $defaultArea)->id,
            'sample_type_id' => ($sample ?? $defaultSample)->id,
        ], $attributes));
    }

    /** @param array<string, mixed> $overrides */
    private function postExam(
        User $user,
        Laboratory $laboratory,
        LaboratoryArea $area,
        SampleType $sample,
        array $overrides = [],
    ): TestResponse {
        return $this->request($user, $laboratory)
            ->postJson('/api/v1/laboratory-exams', array_replace([
                'laboratory_area_id' => $area->id,
                'sample_type_id' => $sample->id,
                'code' => 'EXAM-001',
                'name' => 'Examen integrado',
                'description' => 'Descripción integrada',
                'turnaround_time_minutes' => 60,
            ], $overrides));
    }

    /** @param array<string, mixed> $query */
    private function getExam(User $user, Laboratory $laboratory, string $suffix, array $query = []): TestResponse
    {
        return $this->request($user, $laboratory)
            ->getJson('/api/v1/laboratory-exams'.$suffix.($query === [] ? '' : '?'.http_build_query($query)));
    }

    /** @param array<string, mixed> $payload */
    private function patchExam(User $user, Laboratory $laboratory, int $exam, array $payload): TestResponse
    {
        return $this->request($user, $laboratory)
            ->patchJson("/api/v1/laboratory-exams/{$exam}", $payload);
    }

    /** @param array<string, mixed> $extra */
    private function patchStatus(
        User $user,
        Laboratory $laboratory,
        int $exam,
        string $status,
        array $extra = [],
    ): TestResponse {
        return $this->request($user, $laboratory)
            ->patchJson("/api/v1/laboratory-exams/{$exam}/status", ['status' => $status] + $extra);
    }

    private function request(User $user, Laboratory $laboratory): self
    {
        $this->assignDirectLaboratoryPermissions($user, $laboratory, ['laboratory_exams.view', 'laboratory_exams.create', 'laboratory_exams.update', 'laboratory_exams.change_status']);

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id);
    }

    /** @param array<string, mixed> $value */
    private function sortedKeys(array $value): array
    {
        $keys = array_keys($value);
        sort($keys);

        return $keys;
    }

    private function assertSafeJson(TestResponse $response): void
    {
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));

        foreach (['SQLSTATE', 'constraint', '/home/', '/var/www', 'App\\', 'Illuminate\\', 'trace', 'bindings'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }
}

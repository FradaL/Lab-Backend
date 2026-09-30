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

class PriceListExamStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-29 12:00:00', 'UTC'));
    }

    #[DataProvider('statusMatrixProvider')]
    public function test_status_matrix_changes_only_status(string $current, string $requested, bool $changes): void
    {
        [$user, $laboratory] = $this->activeTenant();
        [$list, $exam] = $this->catalogs($laboratory);
        $item = $this->price($laboratory, $list, $exam, ['price' => '0.00', 'status' => $current]);
        $before = $item->fresh()->getAttributes();
        $updates = 0;
        DB::listen(function (QueryExecuted $query) use (&$updates): void {
            if (preg_match('/^update\s+"?price_list_exams/i', ltrim($query->sql)) === 1) {
                $updates++;
            }
        });
        $this->travel(5)->minutes();

        $response = $this->patchStatus($user, $laboratory, $list, $exam, ['status' => $requested])
            ->assertOk()
            ->assertJsonPath('data.id', $item->id)
            ->assertJsonPath('data.price', '0.00')
            ->assertJsonPath('data.status', $requested)
            ->assertJsonPath('data.laboratory_exam.id', $exam->id);

        $this->assertSame(['id', 'laboratory_exam', 'price', 'status', 'created_at', 'updated_at'], array_keys($response->json('data')));
        $after = $item->fresh()->getAttributes();
        foreach (['id', 'laboratory_id', 'price_list_id', 'laboratory_exam_id', 'price', 'created_at'] as $field) {
            $this->assertSame($before[$field], $after[$field]);
        }
        $this->assertSame($requested, $after['status']);
        $this->assertSame($changes ? '2026-09-29 12:05:00' : $before['updated_at'], $after['updated_at']);
        $this->assertSame($changes ? 1 : 0, $updates);
        $this->assertDatabaseCount('price_list_exams', 1);
    }

    public static function statusMatrixProvider(): array
    {
        return [
            'active to inactive' => ['active', 'inactive', true],
            'inactive to active' => ['inactive', 'active', true],
            'active no-op' => ['active', 'active', false],
            'inactive no-op' => ['inactive', 'inactive', false],
        ];
    }

    public function test_reactivation_is_allowed_when_both_parents_are_inactive(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        [$list, $exam] = $this->catalogs($laboratory);
        $item = $this->price($laboratory, $list, $exam, ['status' => 'inactive']);
        $list->update(['status' => 'inactive']);
        $exam->update(['status' => 'inactive']);

        $this->patchStatus($user, $laboratory, $list, $exam, ['status' => 'active'])
            ->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertSame('active', $item->fresh()->status);
        $this->assertSame('inactive', $list->fresh()->status);
        $this->assertSame('inactive', $exam->fresh()->status);
    }

    #[DataProvider('invalidStatusProvider')]
    public function test_invalid_status_is_rejected_without_mutation(mixed $status): void
    {
        [$user, $laboratory] = $this->activeTenant();
        [$list, $exam] = $this->catalogs($laboratory);
        $item = $this->price($laboratory, $list, $exam);
        $before = $item->fresh()->getAttributes();

        $this->patchStatus($user, $laboratory, $list, $exam, ['status' => $status])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame($before, $item->fresh()->getAttributes());
    }

    public static function invalidStatusProvider(): array
    {
        return [
            'null' => [null], 'uppercase' => ['ACTIVE'], 'title case' => ['Inactive'],
            'leading whitespace' => [' active'], 'trailing whitespace' => ['inactive '],
            'empty' => [''], 'boolean' => [true], 'integer' => [1], 'array' => [[]],
            'object' => [(object) []], 'other' => ['pending'],
        ];
    }

    public function test_missing_status_and_unknown_fields_are_rejected_atomically(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        [$list, $exam] = $this->catalogs($laboratory);
        $item = $this->price($laboratory, $list, $exam);
        $before = $item->fresh()->getAttributes();

        $this->patchStatus($user, $laboratory, $list, $exam, [])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->patchStatus($user, $laboratory, $list, $exam, ['status' => 'inactive', 'price' => '1.00'])
            ->assertUnprocessable()->assertJsonValidationErrors('price');
        $this->assertSame($before, $item->fresh()->getAttributes());
    }

    public function test_target_resolution_precedes_validation_and_never_upserts(): void
    {
        config(['app.debug' => false]);
        [$user, $laboratory] = $this->activeTenant();
        [$list, $exam] = $this->catalogs($laboratory);
        [, $foreignLaboratory] = $this->activeTenant($user);
        [$foreignList, $foreignExam] = $this->catalogs($foreignLaboratory);
        $cases = [
            [$foreignList->id, $exam->id], [$list->id, $foreignExam->id],
            [999999, $exam->id], [$list->id, 999999], [$list->id, $exam->id],
        ];

        foreach ($cases as [$listId, $examId]) {
            $this->patchStatus($user, $laboratory, $listId, $examId, ['status' => null])
                ->assertNotFound()->assertExactJson(['message' => 'Resource not found.']);
        }
        $this->assertDatabaseCount('price_list_exams', 0);
    }

    public function test_wrong_list_assignment_is_neutral_404_and_unchanged(): void
    {
        config(['app.debug' => false]);
        [$user, $laboratory] = $this->activeTenant();
        [$list, $exam] = $this->catalogs($laboratory);
        $otherList = PriceList::factory()->for($laboratory)->create();
        $item = $this->price($laboratory, $otherList, $exam);

        $this->patchStatus($user, $laboratory, $list, $exam, ['status' => 'inactive'])
            ->assertNotFound()->assertExactJson(['message' => 'Resource not found.']);
        $this->assertSame('active', $item->fresh()->status);
    }

    public function test_tenants_are_isolated_and_get_filters_reflect_patch(): void
    {
        $user = User::factory()->create();
        [, $laboratoryA] = $this->activeTenant($user);
        [, $laboratoryB] = $this->activeTenant($user);
        [$listA, $examA] = $this->catalogs($laboratoryA);
        [$listB, $examB] = $this->catalogs($laboratoryB);
        $itemA = $this->price($laboratoryA, $listA, $examA);
        $itemB = $this->price($laboratoryB, $listB, $examB);

        $this->patchStatus($user, $laboratoryA, $listA, $examA, ['status' => 'inactive'])->assertOk();
        $this->assertSame('inactive', $itemA->fresh()->status);
        $this->assertSame('active', $itemB->fresh()->status);
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', (string) $laboratoryA->id)
            ->getJson("/api/v1/price-lists/{$listA->id}/exams?status=inactive")
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $itemA->id);
    }

    public function test_query_counts_are_stable_and_never_insert(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        [$list, $exam] = $this->catalogs($laboratory);
        $this->price($laboratory, $list, $exam);

        $dirty = $this->captureQueries(fn () => $this->patchStatus($user, $laboratory, $list, $exam, ['status' => 'inactive']));
        $noop = $this->captureQueries(fn () => $this->patchStatus($user, $laboratory, $list, $exam, ['status' => 'inactive']));
        $this->assertCount(6, $this->catalogQueries($dirty));
        $this->assertCount(5, $this->catalogQueries($noop));
        $sql = strtolower(implode(' ', array_column([...$dirty, ...$noop], 'query')));
        $this->assertStringNotContainsString('insert into "price_list_exams"', $sql);
        $this->assertStringNotContainsString('insert into `price_list_exams`', $sql);
    }

    public function test_saas_pipeline_precedes_targets_and_validation(): void
    {
        $this->patchJson('/api/v1/price-lists/999/exams/999/status', ['status' => null])->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'web')
            ->patchJson('/api/v1/price-lists/999/exams/999/status', ['status' => null])
            ->assertBadRequest()->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');
    }

    public function test_route_and_openapi_match_exact_status_contract(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(fn ($route) => $route->uri() === 'api/v1/price-lists/{priceList}/exams/{laboratoryExam}/status');
        $this->assertSame(['PATCH'], $route->methods());
        $this->assertSame(['priceList' => '[0-9]+', 'laboratoryExam' => '[0-9]+'], $route->wheres);
        $this->assertContains('saas', $route->middleware());

        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $operation = $document['paths']['/api/v1/price-lists/{priceList}/exams/{laboratoryExam}/status']['patch'];
        $schema = $document['components']['schemas']['UpdatePriceListExamStatusInput'];
        $operations = collect($document['paths'])->flatMap(fn (array $path) => array_values(array_intersect_key($path, array_flip(['get', 'post', 'put', 'patch', 'delete']))));
        $this->assertCount(5, $operations->filter(fn (array $operation) => in_array('Exam Prices', $operation['tags'] ?? [], true)));
        $this->assertCount(7, $operations->filter(fn (array $operation) => in_array('Price Lists', $operation['tags'] ?? [], true)));
        $this->assertCount(6, $operations->filter(fn (array $operation) => in_array('Laboratory Exams', $operation['tags'] ?? [], true)));
        $this->assertSame(['status'], $schema['required']);
        $this->assertSame(['status'], array_keys($schema['properties']));
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(['active', 'inactive'], $schema['properties']['status']['enum']);
        $this->assertSame('#/components/schemas/UpdatePriceListExamStatusInput', $operation['requestBody']['content']['application/json']['schema']['$ref']);
        $this->assertSame([200, 400, 401, 403, 404, 422], array_keys($operation['responses']));
        $this->assertArrayNotHasKey('201', $operation['responses']);
    }

    private function activeTenant(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        Subscription::factory()->for($laboratory)->create(['starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'trial_ends_at' => null]);

        return [$user, $laboratory];
    }

    private function catalogs(Laboratory $laboratory): array
    {
        $list = PriceList::factory()->for($laboratory)->create();
        $area = LaboratoryArea::factory()->for($laboratory)->create();
        $sample = SampleType::factory()->for($laboratory)->create();
        $exam = LaboratoryExam::factory()->for($laboratory)->create(['laboratory_area_id' => $area->id, 'sample_type_id' => $sample->id]);

        return [$list, $exam];
    }

    private function price(Laboratory $laboratory, PriceList $list, LaboratoryExam $exam, array $attributes = []): PriceListExam
    {
        return PriceListExam::factory()->create(['laboratory_id' => $laboratory->id, 'price_list_id' => $list->id, 'laboratory_exam_id' => $exam->id, ...$attributes]);
    }

    private function patchStatus(User $user, Laboratory $laboratory, PriceList|int $list, LaboratoryExam|int $exam, array $payload): TestResponse
    {
        $listId = $list instanceof PriceList ? $list->id : $list;
        $examId = $exam instanceof LaboratoryExam ? $exam->id : $exam;

        return $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson("/api/v1/price-lists/{$listId}/exams/{$examId}/status", $payload);
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

    private function catalogQueries(array $queries): array
    {
        return array_values(array_filter($queries, fn (array $query) => str_contains($query['query'], 'price_lists') || str_contains($query['query'], 'laboratory_exams') || str_contains($query['query'], 'price_list_exams') || str_contains($query['query'], 'laboratory_areas') || str_contains($query['query'], 'sample_types')));
    }
}

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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LaboratoryExamStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-26 12:00:00', 'UTC'));
    }

    public function test_exam_is_created_for_current_tenant_with_database_default_and_exact_resource(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        [$area, $sampleType] = $this->activeCatalogs($laboratory);
        $insertSql = null;

        DB::listen(function (QueryExecuted $query) use (&$insertSql): void {
            if (str_starts_with(strtolower($query->sql), 'insert into "laboratory_exams"')) {
                $insertSql = $query->sql;
            }
        });

        $response = $this->examRequest(
            $user,
            $laboratory,
            $this->validPayload($area, $sampleType),
        )
            ->assertCreated()
            ->assertJsonPath('data.code', 'HEM-001')
            ->assertJsonPath('data.name', 'Hematología completa')
            ->assertJsonPath('data.description', 'Hematología completa automatizada')
            ->assertJsonPath('data.turnaround_time_minutes', 120)
            ->assertJsonPath('data.status', LaboratoryExam::STATUS_ACTIVE)
            ->assertJsonPath('data.laboratory_area', [
                'id' => $area->id,
                'code' => $area->code,
                'name' => $area->name,
            ])
            ->assertJsonPath('data.sample_type', [
                'id' => $sampleType->id,
                'name' => $sampleType->name,
            ])
            ->assertJsonPath('data.created_at', '2026-09-26T12:00:00.000000Z')
            ->assertJsonPath('data.updated_at', '2026-09-26T12:00:00.000000Z');

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
        ], array_keys($response->json('data')));
        $this->assertEqualsCanonicalizing(
            ['id', 'code', 'name'],
            array_keys($response->json('data.laboratory_area')),
        );
        $this->assertEqualsCanonicalizing(
            ['id', 'name'],
            array_keys($response->json('data.sample_type')),
        );
        $response
            ->assertJsonMissingPath('data.laboratory_id')
            ->assertJsonMissingPath('data.laboratory_area_id')
            ->assertJsonMissingPath('data.sample_type_id')
            ->assertJsonMissingPath('data.laboratory');

        $exam = LaboratoryExam::query()->sole();

        $this->assertSame($laboratory->id, $exam->laboratory_id);
        $this->assertSame($area->id, $exam->laboratory_area_id);
        $this->assertSame($sampleType->id, $exam->sample_type_id);
        $this->assertSame(LaboratoryExam::STATUS_ACTIVE, $exam->status);
        $this->assertNotNull($insertSql);
        $this->assertStringNotContainsString('"status"', $insertSql);
    }

    public function test_code_name_and_description_are_normalized_without_changing_casing(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        [$area, $sampleType] = $this->activeCatalogs($laboratory);

        $this->examRequest($user, $laboratory, $this->validPayload($area, $sampleType, [
            'code' => '  hem-001  ',
            'name' => '  Hematología Mixta  ',
            'description' => '  Descripción completa  ',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.code', 'hem-001')
            ->assertJsonPath('data.name', 'Hematología Mixta')
            ->assertJsonPath('data.description', 'Descripción completa');

        $this->assertDatabaseHas('laboratory_exams', [
            'laboratory_id' => $laboratory->id,
            'code' => 'hem-001',
            'name' => 'Hematología Mixta',
            'description' => 'Descripción completa',
        ]);
    }

    #[DataProvider('optionalDescriptionProvider')]
    public function test_description_supports_omitted_null_empty_trimmed_and_long_values(
        string $case,
        mixed $input,
        ?string $expected,
    ): void {
        [$user, $laboratory] = $this->activeTenant();
        [$area, $sampleType] = $this->activeCatalogs($laboratory);
        $payload = $this->validPayload($area, $sampleType);

        if ($case === 'omitted') {
            unset($payload['description']);
        } else {
            $payload['description'] = $input;
        }

        $this->examRequest($user, $laboratory, $payload)
            ->assertCreated()
            ->assertJsonPath('data.description', $expected);

        $this->assertSame($expected, LaboratoryExam::query()->sole()->description);
    }

    /**
     * @return array<string, array{string, mixed, string|null}>
     */
    public static function optionalDescriptionProvider(): array
    {
        $long = str_repeat('Descripción extensa. ', 20);

        return [
            'omitted' => ['omitted', null, null],
            'null' => ['provided', null, null],
            'empty' => ['provided', '', null],
            'whitespace' => ['provided', '   ', null],
            'trimmed' => ['provided', '  Detalle  ', 'Detalle'],
            'long text' => ['provided', $long, trim($long)],
        ];
    }

    #[DataProvider('validTurnaroundProvider')]
    public function test_turnaround_accepts_omitted_null_zero_positive_and_numeric_strings(
        string $case,
        mixed $input,
        ?int $expected,
    ): void {
        [$user, $laboratory] = $this->activeTenant();
        [$area, $sampleType] = $this->activeCatalogs($laboratory);
        $payload = $this->validPayload($area, $sampleType);

        if ($case === 'omitted') {
            unset($payload['turnaround_time_minutes']);
        } else {
            $payload['turnaround_time_minutes'] = $input;
        }

        $this->examRequest($user, $laboratory, $payload)
            ->assertCreated()
            ->assertJsonPath('data.turnaround_time_minutes', $expected);
    }

    /**
     * @return array<string, array{string, mixed, int|null}>
     */
    public static function validTurnaroundProvider(): array
    {
        return [
            'omitted' => ['omitted', null, null],
            'null' => ['provided', null, null],
            'zero' => ['provided', 0, 0],
            'one' => ['provided', 1, 1],
            'one hour' => ['provided', 60, 60],
            'one day' => ['provided', 1440, 1440],
            'numeric string' => ['provided', '120', 120],
        ];
    }

    #[DataProvider('invalidPayloadProvider')]
    public function test_invalid_and_server_controlled_payloads_are_rejected_atomically(
        array $overrides,
        array $removed,
        string $field,
    ): void {
        [$user, $laboratory] = $this->activeTenant();
        [$area, $sampleType] = $this->activeCatalogs($laboratory);
        $payload = array_replace($this->validPayload($area, $sampleType), $overrides);

        foreach ($removed as $removedField) {
            unset($payload[$removedField]);
        }

        $response = $this->examRequest($user, $laboratory, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertDatabaseCount('laboratory_exams', 0);
        $this->assertNoDatabaseDetails($response);
    }

    /**
     * @return array<string, array{array<string, mixed>, list<string>, string}>
     */
    public static function invalidPayloadProvider(): array
    {
        return [
            'code missing' => [[], ['code'], 'code'],
            'code null' => [['code' => null], [], 'code'],
            'code empty' => [['code' => ''], [], 'code'],
            'code whitespace' => [['code' => '   '], [], 'code'],
            'code too long' => [['code' => str_repeat('A', 31)], [], 'code'],
            'code array' => [['code' => ['HEM']], [], 'code'],
            'code integer' => [['code' => 123], [], 'code'],
            'name missing' => [[], ['name'], 'name'],
            'name null' => [['name' => null], [], 'name'],
            'name empty' => [['name' => ''], [], 'name'],
            'name whitespace' => [['name' => '   '], [], 'name'],
            'name too long' => [['name' => str_repeat('A', 151)], [], 'name'],
            'name array' => [['name' => ['Exam']], [], 'name'],
            'name integer' => [['name' => 123], [], 'name'],
            'description array' => [['description' => ['text']], [], 'description'],
            'description object' => [['description' => (object) ['text' => 'value']], [], 'description'],
            'turnaround negative' => [['turnaround_time_minutes' => -1], [], 'turnaround_time_minutes'],
            'turnaround negative large' => [['turnaround_time_minutes' => -100], [], 'turnaround_time_minutes'],
            'turnaround text' => [['turnaround_time_minutes' => 'abc'], [], 'turnaround_time_minutes'],
            'turnaround float' => [['turnaround_time_minutes' => 1.5], [], 'turnaround_time_minutes'],
            'turnaround array' => [['turnaround_time_minutes' => [60]], [], 'turnaround_time_minutes'],
            'turnaround object' => [['turnaround_time_minutes' => (object) ['value' => 60]], [], 'turnaround_time_minutes'],
            'area missing' => [[], ['laboratory_area_id'], 'laboratory_area_id'],
            'area null' => [['laboratory_area_id' => null], [], 'laboratory_area_id'],
            'area zero' => [['laboratory_area_id' => 0], [], 'laboratory_area_id'],
            'area negative' => [['laboratory_area_id' => -1], [], 'laboratory_area_id'],
            'area text' => [['laboratory_area_id' => 'abc'], [], 'laboratory_area_id'],
            'area float' => [['laboratory_area_id' => 1.5], [], 'laboratory_area_id'],
            'area array' => [['laboratory_area_id' => [1]], [], 'laboratory_area_id'],
            'area nonexistent' => [['laboratory_area_id' => 999999], [], 'laboratory_area_id'],
            'sample missing' => [[], ['sample_type_id'], 'sample_type_id'],
            'sample null' => [['sample_type_id' => null], [], 'sample_type_id'],
            'sample zero' => [['sample_type_id' => 0], [], 'sample_type_id'],
            'sample negative' => [['sample_type_id' => -1], [], 'sample_type_id'],
            'sample text' => [['sample_type_id' => 'abc'], [], 'sample_type_id'],
            'sample float' => [['sample_type_id' => 1.5], [], 'sample_type_id'],
            'sample array' => [['sample_type_id' => [1]], [], 'sample_type_id'],
            'sample nonexistent' => [['sample_type_id' => 999999], [], 'sample_type_id'],
            'id injection' => [['id' => 99], [], 'id'],
            'laboratory injection' => [['laboratory_id' => 99], [], 'laboratory_id'],
            'status injection' => [['status' => 'inactive'], [], 'status'],
            'created at injection' => [['created_at' => '2020-01-01'], [], 'created_at'],
            'updated at injection' => [['updated_at' => '2020-01-01'], [], 'updated_at'],
            'branch injection' => [['branch_id' => 1], [], 'branch_id'],
            'price injection' => [['price' => 100], [], 'price'],
            'cost injection' => [['cost' => 50], [], 'cost'],
            'unit injection' => [['unit_of_measure' => 'g/dL'], [], 'unit_of_measure'],
            'result injection' => [['result' => 'normal'], [], 'result'],
            'order injection' => [['laboratory_order_id' => 1], [], 'laboratory_order_id'],
            'unknown field' => [['foo' => 'bar'], [], 'foo'],
        ];
    }

    public function test_cross_tenant_related_ids_are_indistinguishable_from_nonexistent_ids(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        [$areaA, $sampleA] = $this->activeCatalogs($labA);
        [$areaB, $sampleB] = $this->activeCatalogs($labB);

        $cases = [
            [$areaB->id, $sampleA->id, ['laboratory_area_id']],
            [$areaA->id, $sampleB->id, ['sample_type_id']],
            [$areaB->id, $sampleB->id, ['laboratory_area_id', 'sample_type_id']],
        ];

        foreach ($cases as [$areaId, $sampleId, $errors]) {
            $response = $this->examRequest($user, $labA, $this->validPayload($areaA, $sampleA, [
                'laboratory_area_id' => $areaId,
                'sample_type_id' => $sampleId,
            ]))->assertUnprocessable()->assertJsonValidationErrors($errors);

            $this->assertNoDatabaseDetails($response);
        }

        $this->assertSame(0, LaboratoryExam::forLaboratory($labA)->count());
        $this->assertSame(0, LaboratoryExam::forLaboratory($labB)->count());
    }

    public function test_inactive_related_records_are_rejected_without_mutation(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        [$activeArea, $activeSample] = $this->activeCatalogs($laboratory);
        $inactiveArea = LaboratoryArea::factory()->for($laboratory)->create([
            'status' => LaboratoryArea::STATUS_INACTIVE,
        ]);
        $inactiveSample = SampleType::factory()->inactive()->for($laboratory)->create();

        $cases = [
            [$inactiveArea, $activeSample, ['laboratory_area_id']],
            [$activeArea, $inactiveSample, ['sample_type_id']],
            [$inactiveArea, $inactiveSample, ['laboratory_area_id', 'sample_type_id']],
        ];

        foreach ($cases as [$area, $sampleType, $errors]) {
            $this->examRequest($user, $laboratory, $this->validPayload($area, $sampleType))
                ->assertUnprocessable()
                ->assertJsonValidationErrors($errors);
        }

        $this->assertDatabaseCount('laboratory_exams', 0);
        $this->assertSame(LaboratoryArea::STATUS_INACTIVE, $inactiveArea->fresh()->status);
        $this->assertSame(SampleType::STATUS_INACTIVE, $inactiveSample->fresh()->status);
    }

    public function test_code_uniqueness_is_tenant_scoped_case_sensitive_and_uses_trimmed_value(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        [$areaA, $sampleA] = $this->activeCatalogs($labA);
        [$areaB, $sampleB] = $this->activeCatalogs($labB);

        $this->examRequest($user, $labA, $this->validPayload($areaA, $sampleA))
            ->assertCreated();
        $this->examRequest($user, $labA, $this->validPayload($areaA, $sampleA, [
            'code' => '  HEM-001  ',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
        $this->examRequest($user, $labA, $this->validPayload($areaA, $sampleA, [
            'code' => 'hem-001',
        ]))->assertCreated();
        $this->examRequest($user, $labB, $this->validPayload($areaB, $sampleB))
            ->assertCreated();

        $this->assertSame(2, LaboratoryExam::forLaboratory($labA)->count());
        $this->assertSame(1, LaboratoryExam::forLaboratory($labB)->count());
    }

    public function test_duplicate_names_are_allowed_and_duplicate_code_failure_is_atomic(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        [$area, $sampleType] = $this->activeCatalogs($laboratory);

        $this->examRequest($user, $laboratory, $this->validPayload($area, $sampleType))
            ->assertCreated();
        $this->examRequest($user, $laboratory, $this->validPayload($area, $sampleType, [
            'code' => 'HEM-002',
            'name' => 'Hematología completa',
        ]))->assertCreated();

        $this->examRequest($user, $laboratory, $this->validPayload($area, $sampleType, [
            'code' => 'HEM-001',
            'name' => 'Should not persist',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);

        $this->assertDatabaseCount('laboratory_exams', 2);
        $this->assertSame(2, LaboratoryExam::query()->where('name', 'Hematología completa')->count());
    }

    public function test_tenant_isolation_and_context_switching_assign_ownership_without_residue(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        [$areaA, $sampleA] = $this->activeCatalogs($labA);
        [$areaB, $sampleB] = $this->activeCatalogs($labB);

        $idsA = [
            $this->examRequest($user, $labA, $this->validPayload($areaA, $sampleA, ['code' => 'A-1']))->assertCreated()->json('data.id'),
            $this->examRequest($user, $labA, $this->validPayload($areaA, $sampleA, ['code' => 'A-2']))->assertCreated()->json('data.id'),
        ];
        $idsB = [
            $this->examRequest($user, $labB, $this->validPayload($areaB, $sampleB, ['code' => 'B-1']))->assertCreated()->json('data.id'),
            $this->examRequest($user, $labB, $this->validPayload($areaB, $sampleB, ['code' => 'B-2']))->assertCreated()->json('data.id'),
        ];

        $this->assertEqualsCanonicalizing(
            $idsA,
            LaboratoryExam::forLaboratory($labA)->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            $idsB,
            LaboratoryExam::forLaboratory($labB)->pluck('id')->all(),
        );
    }

    public function test_unique_code_race_is_converted_to_safe_validation_error(): void
    {
        config(['app.debug' => false]);
        [$user, $laboratory] = $this->activeTenant();
        [$area, $sampleType] = $this->activeCatalogs($laboratory);
        $inserted = false;

        LaboratoryExam::creating(function (LaboratoryExam $exam) use (&$inserted): void {
            if ($inserted) {
                return;
            }

            $inserted = true;
            DB::table('laboratory_exams')->insert([
                'laboratory_id' => $exam->laboratory_id,
                'laboratory_area_id' => $exam->laboratory_area_id,
                'sample_type_id' => $exam->sample_type_id,
                'code' => $exam->code,
                'name' => 'Concurrent winner',
                'description' => null,
                'turnaround_time_minutes' => null,
                'status' => LaboratoryExam::STATUS_ACTIVE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            $response = $this->examRequest(
                $user,
                $laboratory,
                $this->validPayload($area, $sampleType),
            )
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['code']);

            $this->assertNoDatabaseDetails($response);
            $this->assertDatabaseCount('laboratory_exams', 1);
        } finally {
            LaboratoryExam::flushEventListeners();
        }
    }

    public function test_unrelated_query_exception_is_not_mapped_to_code_validation(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        [$area, $sampleType] = $this->activeCatalogs($laboratory);

        LaboratoryExam::creating(function (LaboratoryExam $exam): void {
            DB::table('laboratory_exams')->insert([
                'laboratory_id' => $exam->laboratory_id,
                'laboratory_area_id' => $exam->laboratory_area_id,
                'sample_type_id' => $exam->sample_type_id,
                'code' => 'BROKEN',
                'name' => 'Broken insert',
                'description' => null,
                'turnaround_time_minutes' => null,
                'status' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->withoutExceptionHandling();
        $this->expectException(QueryException::class);

        try {
            $this->examRequest($user, $laboratory, $this->validPayload($area, $sampleType));
        } finally {
            LaboratoryExam::flushEventListeners();
        }
    }

    public function test_guest_and_missing_context_fail_before_writes(): void
    {
        $this->postJson('/api/v1/laboratory-exams', [])
            ->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'web')
            ->postJson('/api/v1/laboratory-exams', [])
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');

        $this->assertDatabaseCount('laboratory_exams', 0);
    }

    public function test_invalid_nonexistent_and_inactive_contexts_fail_before_writes(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', 'abc')
            ->postJson('/api/v1/laboratory-exams', [])
            ->assertBadRequest()
            ->assertJsonPath('code', 'INVALID_LABORATORY_CONTEXT');
        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', '999999')
            ->postJson('/api/v1/laboratory-exams', [])
            ->assertNotFound()
            ->assertJsonPath('code', 'LABORATORY_NOT_FOUND');

        $inactive = Laboratory::factory()->create(['is_active' => false]);
        $user->laboratories()->attach($inactive, ['is_active' => true]);
        $this->examRequest($user, $inactive, [])
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_INACTIVE');

        $this->assertDatabaseCount('laboratory_exams', 0);
    }

    public function test_membership_and_subscription_failures_do_not_write(): void
    {
        $user = User::factory()->create();
        $withoutMembership = Laboratory::factory()->create();
        $this->createCurrentSubscription($withoutMembership);

        $this->examRequest($user, $withoutMembership, [])
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $inactiveMembership = Laboratory::factory()->create();
        $inactiveMembership->users()->attach($user, ['is_active' => false]);
        $this->createCurrentSubscription($inactiveMembership);
        $this->examRequest($user, $inactiveMembership, [])
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $withoutSubscription = Laboratory::factory()->create();
        $user->laboratories()->attach($withoutSubscription, ['is_active' => true]);
        $this->examRequest($user, $withoutSubscription, [])
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');

        $this->assertDatabaseCount('laboratory_exams', 0);
    }

    public function test_debug_false_success_and_representative_errors_do_not_leak_details(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        [$areaA, $sampleA] = $this->activeCatalogs($labA);
        [$areaB] = $this->activeCatalogs($labB);

        $responses = [
            $this->examRequest($user, $labA, $this->validPayload($areaA, $sampleA))->assertCreated(),
            $this->examRequest($user, $labA, $this->validPayload($areaA, $sampleA))->assertUnprocessable(),
            $this->examRequest($user, $labA, $this->validPayload($areaA, $sampleA, [
                'laboratory_area_id' => $areaB->id,
            ]))->assertUnprocessable(),
            $this->actingAs($user, 'web')
                ->withHeader('X-Laboratory-ID', '999999')
                ->postJson('/api/v1/laboratory-exams', [])
                ->assertNotFound(),
        ];

        foreach ($responses as $response) {
            $this->assertNoDatabaseDetails($response);
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
     * @return array{LaboratoryArea, SampleType}
     */
    private function activeCatalogs(Laboratory $laboratory): array
    {
        return [
            LaboratoryArea::factory()->for($laboratory)->create(),
            SampleType::factory()->for($laboratory)->create(),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(
        LaboratoryArea $area,
        SampleType $sampleType,
        array $overrides = [],
    ): array {
        return array_replace([
            'laboratory_area_id' => $area->id,
            'sample_type_id' => $sampleType->id,
            'code' => 'HEM-001',
            'name' => 'Hematología completa',
            'description' => 'Hematología completa automatizada',
            'turnaround_time_minutes' => 120,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function examRequest(
        User $user,
        Laboratory $laboratory,
        array $payload,
    ): TestResponse {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson('/api/v1/laboratory-exams', $payload);
    }

    private function assertNoDatabaseDetails(TestResponse $response): void
    {
        $payload = $response->getContent();

        foreach ([
            'SQLSTATE',
            'laboratory_exams_laboratory_code_unique',
            'select ',
            'insert into',
            'bindings',
            '/var/www',
            'App\\Models',
            'stack trace',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $payload);
        }
    }
}

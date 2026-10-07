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
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LaboratoryExamUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-26 12:00:00', 'UTC'));
    }

    public function test_partial_name_update_returns_exact_contract_and_preserves_omitted_fields(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = LaboratoryArea::factory()->for($laboratory)->create([
            'code' => 'HEM',
            'name' => 'Hematología',
        ]);
        $sampleType = SampleType::factory()->for($laboratory)->create(['name' => 'Sangre']);
        $exam = $this->createExam($laboratory, $area, $sampleType, [
            'code' => 'HEM-001',
            'name' => 'Hemograma',
            'description' => 'Descripción original',
            'turnaround_time_minutes' => 120,
        ]);

        $this->travel(5)->minutes();

        $this->updateRequest($user, $laboratory, $exam->id, [
            'name' => '  Hematología completa  ',
        ])
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $exam->id,
                    'code' => 'HEM-001',
                    'name' => 'Hematología completa',
                    'description' => 'Descripción original',
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
                    'updated_at' => '2026-09-26T12:05:00.000000Z',
                ],
            ])
            ->assertJsonMissingPath('data.laboratory_id')
            ->assertJsonMissingPath('data.laboratory_area_id')
            ->assertJsonMissingPath('data.sample_type_id')
            ->assertJsonMissingPath('data.laboratory');

        $this->assertDatabaseHas('laboratory_exams', [
            'id' => $exam->id,
            'laboratory_id' => $laboratory->id,
            'laboratory_area_id' => $area->id,
            'sample_type_id' => $sampleType->id,
            'code' => 'HEM-001',
            'name' => 'Hematología completa',
            'description' => 'Descripción original',
            'turnaround_time_minutes' => 120,
            'status' => LaboratoryExam::STATUS_ACTIVE,
            'created_at' => '2026-09-26 12:00:00',
            'updated_at' => '2026-09-26 12:05:00',
        ]);
    }

    public function test_all_editable_fields_can_be_updated_atomically_with_new_relations(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $oldArea = LaboratoryArea::factory()->for($laboratory)->create();
        $oldSample = SampleType::factory()->for($laboratory)->create();
        $newArea = LaboratoryArea::factory()->for($laboratory)->create([
            'code' => 'MIC',
            'name' => 'Microbiología',
        ]);
        $newSample = SampleType::factory()->for($laboratory)->create(['name' => 'Plasma']);
        $exam = $this->createExam($laboratory, $oldArea, $oldSample);

        $this->updateRequest($user, $laboratory, $exam->id, [
            'laboratory_area_id' => $newArea->id,
            'sample_type_id' => $newSample->id,
            'code' => 'MIC-002',
            'name' => 'Cultivo actualizado',
            'description' => '  Descripción extensa actualizada  ',
            'turnaround_time_minutes' => 1440,
        ])
            ->assertOk()
            ->assertJsonPath('data.code', 'MIC-002')
            ->assertJsonPath('data.name', 'Cultivo actualizado')
            ->assertJsonPath('data.description', 'Descripción extensa actualizada')
            ->assertJsonPath('data.turnaround_time_minutes', 1440)
            ->assertJsonPath('data.laboratory_area', [
                'id' => $newArea->id,
                'code' => 'MIC',
                'name' => 'Microbiología',
            ])
            ->assertJsonPath('data.sample_type', [
                'id' => $newSample->id,
                'name' => 'Plasma',
            ]);

        $this->assertDatabaseHas('laboratory_exams', [
            'id' => $exam->id,
            'laboratory_id' => $laboratory->id,
            'laboratory_area_id' => $newArea->id,
            'sample_type_id' => $newSample->id,
            'status' => LaboratoryExam::STATUS_ACTIVE,
        ]);
    }

    public function test_empty_payload_is_rejected_without_writes(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $exam = $this->createExam($laboratory);
        $before = $this->examRow($exam);

        $this->travel(5)->minutes();
        $this->updateRequest($user, $laboratory, $exam->id, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payload']);

        $this->assertEquals($before, $this->examRow($exam));
    }

    #[DataProvider('forbiddenFieldProvider')]
    public function test_unknown_and_server_controlled_fields_are_rejected_atomically(string $field, mixed $value): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $exam = $this->createExam($laboratory, attributes: ['name' => 'Original']);
        $before = $this->examRow($exam);

        $this->updateRequest($user, $laboratory, $exam->id, [
            'name' => 'Should not persist',
            $field => $value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertEquals($before, $this->examRow($exam));
    }

    /** @return array<string, array{string, mixed}> */
    public static function forbiddenFieldProvider(): array
    {
        return [
            'unknown' => ['foo', 'bar'],
            'laboratory ownership' => ['laboratory_id', 999],
            'status' => ['status', LaboratoryExam::STATUS_INACTIVE],
            'primary key' => ['id', 999],
            'created timestamp' => ['created_at', '2020-01-01'],
            'updated timestamp' => ['updated_at', '2020-01-01'],
            'branch' => ['branch_id', 1],
            'price' => ['price', 99.99],
            'cost' => ['cost', 10],
            'unit' => ['unit_of_measure', 'mg/dL'],
            'result' => ['result', 'positive'],
            'order' => ['laboratory_order_id', 1],
        ];
    }

    public function test_unknown_only_payload_reports_strict_and_nonempty_errors_without_writes(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $exam = $this->createExam($laboratory);
        $before = $this->examRow($exam);

        $this->updateRequest($user, $laboratory, $exam->id, ['foo' => 'bar'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['foo', 'payload']);

        $this->assertEquals($before, $this->examRow($exam));
    }

    #[DataProvider('invalidTextProvider')]
    public function test_code_and_name_validation_is_strict(string $field, mixed $value): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $exam = $this->createExam($laboratory);
        $before = $this->examRow($exam);

        $this->updateRequest($user, $laboratory, $exam->id, [$field => $value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertEquals($before, $this->examRow($exam));
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidTextProvider(): array
    {
        return [
            'code null' => ['code', null],
            'code empty' => ['code', ''],
            'code whitespace' => ['code', '   '],
            'code non-string' => ['code', ['HEM']],
            'code max' => ['code', str_repeat('C', 31)],
            'name null' => ['name', null],
            'name empty' => ['name', ''],
            'name whitespace' => ['name', '   '],
            'name non-string' => ['name', ['Exam']],
            'name max' => ['name', str_repeat('N', 151)],
        ];
    }

    #[DataProvider('descriptionProvider')]
    public function test_description_supports_null_trim_whitespace_and_long_text(mixed $input, ?string $expected): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $exam = $this->createExam($laboratory, attributes: ['description' => 'Original']);

        $this->updateRequest($user, $laboratory, $exam->id, ['description' => $input])
            ->assertOk()
            ->assertJsonPath('data.description', $expected);

        $this->assertSame($expected, DB::table('laboratory_exams')->where('id', $exam->id)->value('description'));
    }

    /** @return array<string, array{mixed, string|null}> */
    public static function descriptionProvider(): array
    {
        $long = str_repeat('Descripción extensa. ', 20);

        return [
            'explicit null' => [null, null],
            'trim' => ['  Detalle  ', 'Detalle'],
            'whitespace to null' => ['   ', null],
            'long text' => [$long, trim($long)],
        ];
    }

    public function test_non_string_description_is_rejected_atomically(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $exam = $this->createExam($laboratory, attributes: ['description' => 'Original']);
        $before = $this->examRow($exam);

        $this->updateRequest($user, $laboratory, $exam->id, ['description' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['description']);

        $this->assertEquals($before, $this->examRow($exam));
    }

    #[DataProvider('validTurnaroundProvider')]
    public function test_turnaround_accepts_null_zero_positive_and_numeric_integer(mixed $input, ?int $expected): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $exam = $this->createExam($laboratory, attributes: ['turnaround_time_minutes' => 120]);

        $this->updateRequest($user, $laboratory, $exam->id, [
            'turnaround_time_minutes' => $input,
        ])
            ->assertOk()
            ->assertJsonPath('data.turnaround_time_minutes', $expected);

        $this->assertSame(
            $expected,
            DB::table('laboratory_exams')->where('id', $exam->id)->value('turnaround_time_minutes'),
        );
    }

    /** @return array<string, array{mixed, int|null}> */
    public static function validTurnaroundProvider(): array
    {
        return [
            'null' => [null, null],
            'zero' => [0, 0],
            'one' => [1, 1],
            'positive' => [60, 60],
            'large' => [1440, 1440],
            'numeric integer string' => ['60', 60],
        ];
    }

    #[DataProvider('invalidTurnaroundProvider')]
    public function test_invalid_turnaround_is_rejected_atomically(mixed $value): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $exam = $this->createExam($laboratory, attributes: ['turnaround_time_minutes' => 120]);
        $before = $this->examRow($exam);

        $this->updateRequest($user, $laboratory, $exam->id, [
            'turnaround_time_minutes' => $value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['turnaround_time_minutes']);

        $this->assertEquals($before, $this->examRow($exam));
    }

    /** @return array<string, array{mixed}> */
    public static function invalidTurnaroundProvider(): array
    {
        return [
            'negative' => [-1],
            'float' => [1.5],
            'decimal string' => ['1.5'],
            'text' => ['abc'],
            'array' => [[]],
            'object' => [(object) []],
        ];
    }

    public function test_code_uniqueness_is_scoped_trimmed_case_sensitive_and_ignores_only_current_exam(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $examA = $this->createExam($labA, attributes: ['code' => 'HEM-001']);
        $otherA = $this->createExam($labA, attributes: ['code' => 'HEM-002']);
        $this->createExam($labB, attributes: ['code' => 'SHARED']);

        $this->updateRequest($user, $labA, $examA->id, ['code' => '  HEM-001  '])
            ->assertOk()
            ->assertJsonPath('data.code', 'HEM-001');
        $this->updateRequest($user, $labA, $otherA->id, ['code' => '  HEM-001  '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
        $this->updateRequest($user, $labA, $otherA->id, ['code' => 'hem-001'])
            ->assertOk()
            ->assertJsonPath('data.code', 'hem-001');
        $this->updateRequest($user, $labA, $examA->id, ['code' => 'SHARED'])
            ->assertOk()
            ->assertJsonPath('data.code', 'SHARED');

        $this->assertSame($labA->id, DB::table('laboratory_exams')->where('id', $examA->id)->value('laboratory_id'));
        $this->assertSame($labA->id, DB::table('laboratory_exams')->where('id', $otherA->id)->value('laboratory_id'));
    }

    public function test_duplicate_code_and_duplicate_name_behave_atomically_as_specified(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $taken = $this->createExam($laboratory, attributes: [
            'code' => 'TAKEN',
            'name' => 'Duplicable',
        ]);
        $exam = $this->createExam($laboratory, attributes: [
            'code' => 'FREE',
            'name' => 'Original',
        ]);
        $before = $this->examRow($exam);

        $this->updateRequest($user, $laboratory, $exam->id, [
            'code' => $taken->code,
            'name' => 'Should not persist',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
        $this->assertEquals($before, $this->examRow($exam));

        $this->updateRequest($user, $laboratory, $exam->id, [
            'name' => $taken->name,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Duplicable');
    }

    public function test_area_selection_requires_an_active_record_in_current_tenant(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $exam = $this->createExam($labA, attributes: ['name' => 'Original']);
        $active = LaboratoryArea::factory()->for($labA)->create();
        $inactive = LaboratoryArea::factory()->for($labA)->create([
            'status' => LaboratoryArea::STATUS_INACTIVE,
        ]);
        $cross = LaboratoryArea::factory()->for($labB)->create();

        $this->updateRequest($user, $labA, $exam->id, ['laboratory_area_id' => $active->id])
            ->assertOk()
            ->assertJsonPath('data.laboratory_area.id', $active->id);

        foreach ([$inactive->id, $cross->id, 999999999] as $areaId) {
            $before = $this->examRow($exam);
            $this->updateRequest($user, $labA, $exam->id, [
                'laboratory_area_id' => $areaId,
                'name' => 'Should not persist',
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['laboratory_area_id']);
            $this->assertEquals($before, $this->examRow($exam));
        }
    }

    public function test_sample_selection_requires_an_active_record_in_current_tenant(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $exam = $this->createExam($labA, attributes: ['name' => 'Original']);
        $active = SampleType::factory()->for($labA)->create();
        $inactive = SampleType::factory()->inactive()->for($labA)->create();
        $cross = SampleType::factory()->for($labB)->create();

        $this->updateRequest($user, $labA, $exam->id, ['sample_type_id' => $active->id])
            ->assertOk()
            ->assertJsonPath('data.sample_type.id', $active->id);

        foreach ([$inactive->id, $cross->id, 999999999] as $sampleId) {
            $before = $this->examRow($exam);
            $this->updateRequest($user, $labA, $exam->id, [
                'sample_type_id' => $sampleId,
                'name' => 'Should not persist',
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['sample_type_id']);
            $this->assertEquals($before, $this->examRow($exam));
        }
    }

    public function test_existing_inactive_relations_are_allowed_when_omitted_but_rejected_when_explicit(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $inactiveArea = LaboratoryArea::factory()->for($laboratory)->create([
            'status' => LaboratoryArea::STATUS_INACTIVE,
        ]);
        $inactiveSample = SampleType::factory()->inactive()->for($laboratory)->create();
        $activeArea = LaboratoryArea::factory()->for($laboratory)->create();
        $activeSample = SampleType::factory()->for($laboratory)->create();
        $exam = $this->createExam($laboratory, $inactiveArea, $inactiveSample);
        $examWithInactiveSample = $this->createExam($laboratory, $inactiveArea, $inactiveSample);

        $this->updateRequest($user, $laboratory, $exam->id, ['name' => 'Metadata fixed'])
            ->assertOk()
            ->assertJsonPath('data.laboratory_area.id', $inactiveArea->id)
            ->assertJsonPath('data.sample_type.id', $inactiveSample->id);

        $this->updateRequest($user, $laboratory, $exam->id, [
            'laboratory_area_id' => $inactiveArea->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['laboratory_area_id']);
        $this->updateRequest($user, $laboratory, $exam->id, [
            'sample_type_id' => $inactiveSample->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sample_type_id']);

        $this->updateRequest($user, $laboratory, $examWithInactiveSample->id, [
            'laboratory_area_id' => $activeArea->id,
        ])->assertOk();
        $this->assertSame($inactiveSample->id, DB::table('laboratory_exams')->where('id', $examWithInactiveSample->id)->value('sample_type_id'));

        $this->updateRequest($user, $laboratory, $exam->id, [
            'sample_type_id' => $activeSample->id,
        ])->assertOk();
        $this->assertSame($inactiveArea->id, DB::table('laboratory_exams')->where('id', $exam->id)->value('laboratory_area_id'));

        $this->updateRequest($user, $laboratory, $exam->id, [
            'laboratory_area_id' => $activeArea->id,
        ])->assertOk();
        $this->assertSame($activeSample->id, DB::table('laboratory_exams')->where('id', $exam->id)->value('sample_type_id'));
    }

    public function test_both_invalid_relations_are_reported_and_update_is_atomic(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $exam = $this->createExam($labA);
        $areaB = LaboratoryArea::factory()->for($labB)->create();
        $sampleB = SampleType::factory()->for($labB)->create();
        $before = $this->examRow($exam);

        $this->updateRequest($user, $labA, $exam->id, [
            'laboratory_area_id' => $areaB->id,
            'sample_type_id' => $sampleB->id,
            'name' => 'Should not persist',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['laboratory_area_id', 'sample_type_id']);

        $this->assertEquals($before, $this->examRow($exam));
    }

    public function test_inactive_exam_can_be_updated_without_changing_status_or_ownership(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $exam = $this->createExam($laboratory, attributes: [
            'name' => 'Inactive original',
            'status' => LaboratoryExam::STATUS_INACTIVE,
        ]);

        $this->updateRequest($user, $laboratory, $exam->id, [
            'name' => 'Inactive corrected',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Inactive corrected')
            ->assertJsonPath('data.status', LaboratoryExam::STATUS_INACTIVE);

        $this->assertDatabaseHas('laboratory_exams', [
            'id' => $exam->id,
            'laboratory_id' => $laboratory->id,
            'status' => LaboratoryExam::STATUS_INACTIVE,
        ]);
    }

    public function test_same_value_update_is_successful_and_follows_eloquent_timestamp_behavior(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $exam = $this->createExam($laboratory, attributes: ['name' => 'Same value']);
        $createdAt = $exam->created_at?->toISOString();
        $beforeUpdatedAt = $exam->updated_at?->toISOString();

        $this->travel(5)->minutes();
        $this->updateRequest($user, $laboratory, $exam->id, ['name' => 'Same value'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Same value')
            ->assertJsonPath('data.created_at', $createdAt)
            ->assertJsonPath('data.updated_at', $beforeUpdatedAt);

        $row = $this->examRow($exam);
        $this->assertSame('2026-09-26 12:00:00', $row->created_at);
        $this->assertSame('2026-09-26 12:00:00', $row->updated_at);
        $this->assertSame($beforeUpdatedAt, CarbonImmutable::parse($row->updated_at, 'UTC')->toISOString());
    }

    public function test_lookup_precedes_validation_for_nonexistent_and_cross_tenant_exams(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $this->createExam($labA, attributes: ['code' => 'TAKEN-A']);
        $examB = $this->createExam($labB, attributes: ['code' => 'SECRET-B']);
        $areaB = LaboratoryArea::factory()->for($labB)->create();
        $sampleB = SampleType::factory()->for($labB)->create();
        $before = $this->examRow($examB);

        $responses = [
            $this->updateRequest($user, $labA, 999999999, ['name' => 'Valid'])->assertNotFound(),
            $this->updateRequest($user, $labA, 999999999, [
                'code' => '',
                'laboratory_area_id' => 'abc',
            ])->assertNotFound(),
            $this->updateRequest($user, $labA, $examB->id, ['name' => 'Valid'])->assertNotFound(),
            $this->updateRequest($user, $labA, $examB->id, [
                'code' => '',
                'turnaround_time_minutes' => -1,
            ])->assertNotFound(),
            $this->updateRequest($user, $labA, $examB->id, [
                'laboratory_area_id' => $areaB->id,
                'sample_type_id' => $sampleB->id,
            ])->assertNotFound(),
            $this->updateRequest($user, $labA, $examB->id, ['code' => 'TAKEN-A'])->assertNotFound(),
        ];

        foreach ($responses as $response) {
            $this->assertSame(['message' => 'Resource not found.'], $response->json());
            $this->assertArrayNotHasKey('errors', $response->json());
            $this->assertResponseDoesNotLeak($response, ['SECRET-B', (string) $labB->id]);
        }

        $this->assertEquals($before, $this->examRow($examB));
    }

    public function test_tenant_isolation_is_symmetric_during_repeated_context_switching(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $examA = $this->createExam($labA, attributes: ['name' => 'A']);
        $examB = $this->createExam($labB, attributes: ['name' => 'B']);

        $this->updateRequest($user, $labA, $examA->id, ['name' => 'A1'])->assertOk();
        $this->updateRequest($user, $labB, $examB->id, ['name' => 'B1'])->assertOk();
        $this->updateRequest($user, $labA, $examB->id, ['name' => 'HACK'])->assertNotFound();
        $this->updateRequest($user, $labB, $examA->id, ['name' => 'HACK'])->assertNotFound();
        $this->updateRequest($user, $labA, $examA->id, ['name' => 'A2'])->assertOk();
        $this->updateRequest($user, $labB, $examB->id, ['name' => 'B2'])->assertOk();

        $this->assertDatabaseHas('laboratory_exams', [
            'id' => $examA->id,
            'laboratory_id' => $labA->id,
            'name' => 'A2',
        ]);
        $this->assertDatabaseHas('laboratory_exams', [
            'id' => $examB->id,
            'laboratory_id' => $labB->id,
            'name' => 'B2',
        ]);
    }

    #[DataProvider('invalidRouteIdentifierProvider')]
    public function test_invalid_route_identifiers_return_404_without_exam_lookup(string $identifier): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'laboratory_exams')) {
                $queries[] = $query;
            }
        });

        $this->updateRequest($user, $laboratory, $identifier, ['name' => 'Nope'])
            ->assertNotFound();

        $this->assertCount(0, $queries);
    }

    /** @return array<string, array{string}> */
    public static function invalidRouteIdentifierProvider(): array
    {
        return [
            'letters' => ['abc'],
            'reserved nonnumeric route' => ['pending'],
            'decimal' => ['1.5'],
            'negative' => ['-1'],
        ];
    }

    public function test_authentication_and_context_pipeline_errors_do_not_write(): void
    {
        $exam = LaboratoryExam::factory()->create(['name' => 'Original']);
        $before = $this->examRow($exam);
        $user = User::factory()->create();

        $this->patchJson("/api/v1/laboratory-exams/{$exam->id}", ['name' => 'Nope'])
            ->assertUnauthorized();
        $this->actingAs($user, 'web')
            ->patchJson("/api/v1/laboratory-exams/{$exam->id}", ['name' => 'Nope'])
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');
        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', 'abc')
            ->patchJson("/api/v1/laboratory-exams/{$exam->id}", ['name' => 'Nope'])
            ->assertBadRequest()
            ->assertJsonPath('code', 'INVALID_LABORATORY_CONTEXT');
        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', '999999')
            ->patchJson("/api/v1/laboratory-exams/{$exam->id}", ['name' => 'Nope'])
            ->assertNotFound()
            ->assertJsonPath('code', 'LABORATORY_NOT_FOUND');

        $inactive = Laboratory::factory()->create(['is_active' => false]);
        $user->laboratories()->attach($inactive, ['is_active' => true]);
        $this->updateRequest($user, $inactive, $exam->id, ['name' => 'Nope'])
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_INACTIVE');

        $this->assertEquals($before, $this->examRow($exam));
    }

    public function test_membership_and_subscription_pipeline_precede_lookup_and_do_not_write(): void
    {
        $user = User::factory()->create();
        $withoutMembership = Laboratory::factory()->create();
        $this->createCurrentSubscription($withoutMembership);
        $exam = $this->createExam($withoutMembership, attributes: ['name' => 'Original']);
        $before = $this->examRow($exam);
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'laboratory_exams')) {
                $queries[] = $query;
            }
        });

        $this->updateRequest($user, $withoutMembership, $exam->id, ['name' => 'Nope'])
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $withoutSubscription = Laboratory::factory()->create();
        $user->laboratories()->attach($withoutSubscription, ['is_active' => true]);
        $this->updateRequest($user, $withoutSubscription, $exam->id, ['name' => 'Nope'])
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');

        $this->assertCount(0, $queries);
        $this->assertEquals($before, $this->examRow($exam));
    }

    public function test_unique_race_is_mapped_to_code_validation_without_partial_update(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = LaboratoryArea::factory()->for($laboratory)->create();
        $sample = SampleType::factory()->for($laboratory)->create();
        $exam = $this->createExam($laboratory, $area, $sample, [
            'code' => 'ORIGINAL',
            'name' => 'Original',
        ]);

        LaboratoryExam::updating(function (LaboratoryExam $updating) use ($area, $sample): void {
            DB::table('laboratory_exams')->insert([
                'laboratory_id' => $updating->laboratory_id,
                'laboratory_area_id' => $area->id,
                'sample_type_id' => $sample->id,
                'code' => 'RACE-CODE',
                'name' => 'Concurrent winner',
                'description' => null,
                'turnaround_time_minutes' => null,
                'status' => LaboratoryExam::STATUS_ACTIVE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            $this->updateRequest($user, $laboratory, $exam->id, [
                'code' => 'RACE-CODE',
                'name' => 'Should not persist',
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['code']);

            $this->assertDatabaseHas('laboratory_exams', [
                'id' => $exam->id,
                'code' => 'ORIGINAL',
                'name' => 'Original',
            ]);
            // The simulated concurrent write shares this transaction in tests.
            $this->assertDatabaseCount('laboratory_exams', 1);
        } finally {
            LaboratoryExam::flushEventListeners();
        }
    }

    public function test_unrelated_query_exception_is_not_mapped_to_code_validation(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $exam = $this->createExam($laboratory);

        LaboratoryExam::updating(function (LaboratoryExam $updating): void {
            DB::table('laboratory_exams')
                ->where('id', $updating->id)
                ->update(['status' => null]);
        });

        $this->withoutExceptionHandling();
        $this->expectException(QueryException::class);

        try {
            $this->updateRequest($user, $laboratory, $exam->id, ['name' => 'Changed']);
        } finally {
            LaboratoryExam::flushEventListeners();
        }
    }

    public function test_debug_false_representative_responses_do_not_leak_internals(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $exam = $this->createExam($labA, attributes: ['code' => 'ORIGINAL']);
        $taken = $this->createExam($labA, attributes: ['code' => 'TAKEN']);
        $cross = $this->createExam($labB, attributes: ['code' => 'SECRET-CROSS']);

        $responses = [
            $this->updateRequest($user, $labA, $exam->id, ['name' => 'Valid'])->assertOk(),
            $this->updateRequest($user, $labA, 999999999, ['name' => 'Valid'])->assertNotFound(),
            $this->updateRequest($user, $labA, $cross->id, ['code' => ''])->assertNotFound(),
            $this->updateRequest($user, $labA, $exam->id, ['name' => ''])->assertUnprocessable(),
            $this->updateRequest($user, $labA, $exam->id, ['code' => $taken->code])->assertUnprocessable(),
            $this->actingAs($user, 'web')
                ->withHeader('X-Laboratory-ID', 'invalid')
                ->patchJson("/api/v1/laboratory-exams/{$exam->id}", ['name' => 'Nope'])
                ->assertBadRequest(),
        ];

        foreach ($responses as $response) {
            $this->assertResponseDoesNotLeak($response);
        }

        $this->assertResponseDoesNotLeak($responses[2], ['SECRET-CROSS', (string) $labB->id]);
    }

    public function test_patch_route_is_exact_numeric_and_no_future_routes_exist(): void
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

        $patch = $routes->first(
            fn ($route): bool => str_ends_with($route->getActionName(), '@update'),
        );
        $this->assertNotNull($patch);
        $this->assertSame('[0-9]+', $patch->wheres['laboratoryExam']);
        $this->assertContains('saas', $patch->middleware());
        $this->assertSame(
            'App\\Http\\Controllers\\Api\\V1\\LaboratoryExamController@update',
            $patch->getActionName(),
        );
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

    private function updateRequest(
        User $user,
        Laboratory $laboratory,
        int|string $laboratoryExam,
        array $payload,
    ): TestResponse {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson("/api/v1/laboratory-exams/{$laboratoryExam}", $payload);
    }

    private function examRow(LaboratoryExam $exam): object
    {
        return DB::table('laboratory_exams')->where('id', $exam->id)->firstOrFail();
    }

    /** @param  array<int, string>  $secrets */
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

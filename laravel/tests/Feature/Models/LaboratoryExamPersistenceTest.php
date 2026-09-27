<?php

namespace Tests\Feature\Models;

use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use App\Models\LaboratoryExam;
use App\Models\SampleType;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LaboratoryExamPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_laboratory_exam_can_be_persisted(): void
    {
        [$laboratory, $area, $sampleType] = $this->ownedContext();

        $exam = LaboratoryExam::query()->create($this->validAttributes(
            $laboratory,
            $area,
            $sampleType,
            [
                'code' => 'HEM-001',
                'name' => 'Hematología',
                'description' => 'Hemograma completo.',
                'turnaround_time_minutes' => 60,
                'status' => LaboratoryExam::STATUS_INACTIVE,
            ],
        ))->fresh();

        $this->assertDatabaseHas('laboratory_exams', [
            'id' => $exam->id,
            'laboratory_id' => $laboratory->id,
            'laboratory_area_id' => $area->id,
            'sample_type_id' => $sampleType->id,
            'code' => 'HEM-001',
            'name' => 'Hematología',
            'description' => 'Hemograma completo.',
            'turnaround_time_minutes' => 60,
            'status' => LaboratoryExam::STATUS_INACTIVE,
        ]);
        $this->assertNotNull($exam->created_at);
        $this->assertNotNull($exam->updated_at);
    }

    public function test_database_defaults_status_to_active(): void
    {
        [$laboratory, $area, $sampleType] = $this->ownedContext();
        $attributes = $this->validAttributes($laboratory, $area, $sampleType);
        unset($attributes['status']);

        $exam = LaboratoryExam::query()->create($attributes)->fresh();

        $this->assertSame(LaboratoryExam::STATUS_ACTIVE, $exam->status);
    }

    public function test_inactive_factory_state_can_be_persisted(): void
    {
        $exam = LaboratoryExam::factory()->inactive()->create()->fresh();

        $this->assertSame(LaboratoryExam::STATUS_INACTIVE, $exam->status);
    }

    public function test_laboratory_relation_returns_the_owner(): void
    {
        [$laboratory, $area, $sampleType] = $this->ownedContext();
        $exam = LaboratoryExam::factory()->create($this->ownershipAttributes(
            $laboratory,
            $area,
            $sampleType,
        ));

        $this->assertTrue($exam->laboratory->is($laboratory));
    }

    public function test_laboratory_area_relation_returns_the_owned_area(): void
    {
        [$laboratory, $area, $sampleType] = $this->ownedContext();
        $exam = LaboratoryExam::factory()->create($this->ownershipAttributes(
            $laboratory,
            $area,
            $sampleType,
        ));

        $this->assertTrue($exam->laboratoryArea->is($area));
    }

    public function test_sample_type_relation_returns_the_owned_sample_type(): void
    {
        [$laboratory, $area, $sampleType] = $this->ownedContext();
        $exam = LaboratoryExam::factory()->create($this->ownershipAttributes(
            $laboratory,
            $area,
            $sampleType,
        ));

        $this->assertTrue($exam->sampleType->is($sampleType));
    }

    public function test_laboratory_can_obtain_its_laboratory_exams(): void
    {
        [$laboratory, $area, $sampleType] = $this->ownedContext();
        $exams = LaboratoryExam::factory()->count(2)->create(
            $this->ownershipAttributes($laboratory, $area, $sampleType),
        );

        $this->assertCount(2, $laboratory->laboratoryExams);
        $this->assertTrue($exams->every(
            fn (LaboratoryExam $exam): bool => $laboratory->laboratoryExams->contains($exam)
        ));
    }

    public function test_for_laboratory_scope_is_explicit_and_isolates_tenants(): void
    {
        [$labA, $areaA, $sampleA] = $this->ownedContext();
        [$labB, $areaB, $sampleB] = $this->ownedContext();
        $examsA = LaboratoryExam::factory()->count(2)->create(
            $this->ownershipAttributes($labA, $areaA, $sampleA),
        );
        $examB = LaboratoryExam::factory()->create(
            $this->ownershipAttributes($labB, $areaB, $sampleB),
        );

        $this->assertCount(3, LaboratoryExam::query()->get());
        $this->assertEqualsCanonicalizing(
            $examsA->modelKeys(),
            LaboratoryExam::forLaboratory($labA)->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$examB->id],
            LaboratoryExam::forLaboratory($labB)->pluck('id')->all(),
        );
    }

    public function test_duplicate_code_is_rejected_within_the_same_laboratory(): void
    {
        [$laboratory, $area, $sampleType] = $this->ownedContext();
        $attributes = $this->validAttributes($laboratory, $area, $sampleType, [
            'code' => 'HEM-001',
        ]);
        LaboratoryExam::query()->create($attributes);

        $this->expectException(QueryException::class);
        LaboratoryExam::query()->create($attributes);
    }

    public function test_same_code_is_allowed_across_laboratories(): void
    {
        [$labA, $areaA, $sampleA] = $this->ownedContext();
        [$labB, $areaB, $sampleB] = $this->ownedContext();

        LaboratoryExam::query()->create($this->validAttributes(
            $labA,
            $areaA,
            $sampleA,
            ['code' => 'HEM-001'],
        ));
        LaboratoryExam::query()->create($this->validAttributes(
            $labB,
            $areaB,
            $sampleB,
            ['code' => 'HEM-001'],
        ));

        $this->assertSame(2, LaboratoryExam::query()->where('code', 'HEM-001')->count());
    }

    public function test_codes_with_different_casing_are_allowed_within_the_same_laboratory(): void
    {
        [$laboratory, $area, $sampleType] = $this->ownedContext();

        LaboratoryExam::query()->create($this->validAttributes(
            $laboratory,
            $area,
            $sampleType,
            ['code' => 'HEM-001'],
        ));
        LaboratoryExam::query()->create($this->validAttributes(
            $laboratory,
            $area,
            $sampleType,
            ['code' => 'hem-001'],
        ));

        $this->assertSame(2, $laboratory->laboratoryExams()->count());
    }

    public function test_duplicate_names_are_allowed_within_the_same_laboratory(): void
    {
        [$laboratory, $area, $sampleType] = $this->ownedContext();

        LaboratoryExam::query()->create($this->validAttributes(
            $laboratory,
            $area,
            $sampleType,
            ['code' => 'HEM-001', 'name' => 'Hematología'],
        ));
        LaboratoryExam::query()->create($this->validAttributes(
            $laboratory,
            $area,
            $sampleType,
            ['code' => 'HEM-002', 'name' => 'Hematología'],
        ));

        $this->assertSame(2, $laboratory->laboratoryExams()->where('name', 'Hematología')->count());
    }

    public function test_database_rejects_a_cross_tenant_laboratory_area(): void
    {
        [$labA, $areaA, $sampleA] = $this->ownedContext();
        [, $areaB] = $this->ownedContext();

        $this->expectException(QueryException::class);
        LaboratoryExam::query()->create($this->validAttributes(
            $labA,
            $areaB,
            $sampleA,
        ));
    }

    public function test_database_rejects_a_cross_tenant_sample_type(): void
    {
        [$labA, $areaA, $sampleA] = $this->ownedContext();
        [, , $sampleB] = $this->ownedContext();

        $this->expectException(QueryException::class);
        LaboratoryExam::query()->create($this->validAttributes(
            $labA,
            $areaA,
            $sampleB,
        ));
    }

    public function test_database_rejects_both_dependencies_from_another_tenant(): void
    {
        [$labA] = $this->ownedContext();
        [, $areaB, $sampleB] = $this->ownedContext();

        $this->expectException(QueryException::class);
        LaboratoryExam::query()->create($this->validAttributes(
            $labA,
            $areaB,
            $sampleB,
        ));
    }

    public function test_database_rejects_a_nonexistent_laboratory_area(): void
    {
        [$laboratory, $area, $sampleType] = $this->ownedContext();

        $this->expectException(QueryException::class);
        LaboratoryExam::query()->create($this->validAttributes(
            $laboratory,
            $area,
            $sampleType,
            ['laboratory_area_id' => 999999],
        ));
    }

    public function test_database_rejects_a_nonexistent_sample_type(): void
    {
        [$laboratory, $area, $sampleType] = $this->ownedContext();

        $this->expectException(QueryException::class);
        LaboratoryExam::query()->create($this->validAttributes(
            $laboratory,
            $area,
            $sampleType,
            ['sample_type_id' => 999999],
        ));
    }

    public function test_database_rejects_a_nonexistent_laboratory(): void
    {
        [$laboratory, $area, $sampleType] = $this->ownedContext();

        $this->expectException(QueryException::class);
        LaboratoryExam::query()->create($this->validAttributes(
            $laboratory,
            $area,
            $sampleType,
            ['laboratory_id' => 999999],
        ));
    }

    public function test_laboratory_with_an_exam_cannot_be_deleted(): void
    {
        [$laboratory, $area, $sampleType] = $this->ownedContext();
        LaboratoryExam::factory()->create(
            $this->ownershipAttributes($laboratory, $area, $sampleType),
        );

        $this->expectException(QueryException::class);
        $laboratory->delete();
    }

    public function test_laboratory_area_with_an_exam_cannot_be_deleted(): void
    {
        [$laboratory, $area, $sampleType] = $this->ownedContext();
        LaboratoryExam::factory()->create(
            $this->ownershipAttributes($laboratory, $area, $sampleType),
        );

        $this->expectException(QueryException::class);
        $area->delete();
    }

    public function test_sample_type_with_an_exam_cannot_be_deleted(): void
    {
        [$laboratory, $area, $sampleType] = $this->ownedContext();
        LaboratoryExam::factory()->create(
            $this->ownershipAttributes($laboratory, $area, $sampleType),
        );

        $this->expectException(QueryException::class);
        $sampleType->delete();
    }

    public function test_turnaround_time_accepts_null(): void
    {
        [$laboratory, $area, $sampleType] = $this->ownedContext();
        $exam = LaboratoryExam::query()->create($this->validAttributes(
            $laboratory,
            $area,
            $sampleType,
            ['turnaround_time_minutes' => null],
        ))->fresh();

        $this->assertNull($exam->turnaround_time_minutes);
    }

    public function test_turnaround_time_accepts_zero(): void
    {
        [$laboratory, $area, $sampleType] = $this->ownedContext();
        $exam = LaboratoryExam::query()->create($this->validAttributes(
            $laboratory,
            $area,
            $sampleType,
            ['turnaround_time_minutes' => 0],
        ))->fresh();

        $this->assertSame(0, $exam->turnaround_time_minutes);
    }

    #[DataProvider('positiveTurnaroundProvider')]
    public function test_turnaround_time_accepts_positive_minutes(int $minutes): void
    {
        [$laboratory, $area, $sampleType] = $this->ownedContext();
        $exam = LaboratoryExam::query()->create($this->validAttributes(
            $laboratory,
            $area,
            $sampleType,
            ['turnaround_time_minutes' => $minutes],
        ))->fresh();

        $this->assertSame($minutes, $exam->turnaround_time_minutes);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function positiveTurnaroundProvider(): array
    {
        return [
            'one hour' => [60],
            'one day' => [1440],
        ];
    }

    public function test_database_rejects_a_negative_turnaround_time(): void
    {
        [$laboratory, $area, $sampleType] = $this->ownedContext();

        $this->expectException(QueryException::class);
        LaboratoryExam::query()->create($this->validAttributes(
            $laboratory,
            $area,
            $sampleType,
            ['turnaround_time_minutes' => -1],
        ));
    }

    public function test_description_accepts_null(): void
    {
        [$laboratory, $area, $sampleType] = $this->ownedContext();
        $exam = LaboratoryExam::query()->create($this->validAttributes(
            $laboratory,
            $area,
            $sampleType,
            ['description' => null],
        ))->fresh();

        $this->assertNull($exam->description);
    }

    public function test_description_accepts_more_than_255_characters(): void
    {
        [$laboratory, $area, $sampleType] = $this->ownedContext();
        $description = str_repeat('Descripción extensa. ', 20);
        $exam = LaboratoryExam::query()->create($this->validAttributes(
            $laboratory,
            $area,
            $sampleType,
            ['description' => $description],
        ))->fresh();

        $this->assertGreaterThan(255, mb_strlen($exam->description));
        $this->assertSame($description, $exam->description);
    }

    public function test_model_uses_explicit_mass_assignment_and_no_soft_deletes(): void
    {
        $exam = new LaboratoryExam;
        $exam->fill([
            'id' => 999999,
            'laboratory_id' => 1,
            'laboratory_area_id' => 2,
            'sample_type_id' => 3,
            'code' => 'MASS-001',
            'name' => 'Mass assignment',
            'description' => null,
            'turnaround_time_minutes' => '60',
            'status' => LaboratoryExam::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame([
            'laboratory_id',
            'laboratory_area_id',
            'sample_type_id',
            'code',
            'name',
            'description',
            'turnaround_time_minutes',
            'status',
        ], $exam->getFillable());
        $this->assertNull($exam->getAttribute('id'));
        $this->assertNull($exam->getAttribute('created_at'));
        $this->assertNull($exam->getAttribute('updated_at'));
        $this->assertSame(60, $exam->turnaround_time_minutes);
        $this->assertNotContains(SoftDeletes::class, class_uses_recursive(LaboratoryExam::class));
    }

    public function test_default_factory_always_creates_tenant_consistent_dependencies(): void
    {
        $exams = LaboratoryExam::factory()->count(5)->create();

        foreach ($exams as $exam) {
            $this->assertSame($exam->laboratory_id, $exam->laboratoryArea->laboratory_id);
            $this->assertSame($exam->laboratory_id, $exam->sampleType->laboratory_id);
        }
    }

    public function test_factory_preserves_explicit_ownership_ids(): void
    {
        [$laboratory, $area, $sampleType] = $this->ownedContext();

        $exam = LaboratoryExam::factory()->create(
            $this->ownershipAttributes($laboratory, $area, $sampleType),
        );

        $this->assertSame($laboratory->id, $exam->laboratory_id);
        $this->assertSame($area->id, $exam->laboratory_area_id);
        $this->assertSame($sampleType->id, $exam->sample_type_id);
    }

    /**
     * @return array{Laboratory, LaboratoryArea, SampleType}
     */
    private function ownedContext(): array
    {
        $laboratory = Laboratory::factory()->create();

        return [
            $laboratory,
            LaboratoryArea::factory()->for($laboratory)->create(),
            SampleType::factory()->for($laboratory)->create(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function ownershipAttributes(
        Laboratory $laboratory,
        LaboratoryArea $area,
        SampleType $sampleType,
    ): array {
        return [
            'laboratory_id' => $laboratory->id,
            'laboratory_area_id' => $area->id,
            'sample_type_id' => $sampleType->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validAttributes(
        Laboratory $laboratory,
        LaboratoryArea $area,
        SampleType $sampleType,
        array $overrides = [],
    ): array {
        return array_merge([
            ...$this->ownershipAttributes($laboratory, $area, $sampleType),
            'code' => fake()->unique()->bothify('TEST-####-????'),
            'name' => 'Laboratory Exam',
            'description' => null,
            'turnaround_time_minutes' => null,
            'status' => LaboratoryExam::STATUS_ACTIVE,
        ], $overrides);
    }
}

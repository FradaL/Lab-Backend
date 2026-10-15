<?php

namespace Database\Factories;

use App\ClinicalExams\ClinicalExamService;
use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryExamVersion;
use App\Models\SampleType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<LaboratoryExam>
 */
class LaboratoryExamFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'laboratory_id' => Laboratory::factory(),
            'laboratory_area_id' => fn (array $attributes): int => LaboratoryArea::factory()
                ->create(['laboratory_id' => $attributes['laboratory_id']])
                ->id,
            'sample_type_id' => fn (array $attributes): int => SampleType::factory()
                ->create(['laboratory_id' => $attributes['laboratory_id']])
                ->id,
            'code' => fake()->unique()->bothify('EXAM-########-????'),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->paragraph(),
            'turnaround_time_minutes' => fake()->optional()->numberBetween(0, 10080),
            'status' => LaboratoryExam::STATUS_ACTIVE,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => LaboratoryExam::STATUS_INACTIVE,
        ]);
    }

    /** Published test configuration; it does not infer clinical rules from a sample catalog. */
    public function orderable(): static
    {
        return $this->afterCreating(fn (LaboratoryExam $exam) => self::publishedVersion($exam));
    }

    public static function publishedVersion(LaboratoryExam $exam): int
    {
        $current = DB::table('laboratory_exam_clinical_states')->where('laboratory_exam_id', $exam->id)->value('current_version_id');
        if ($current !== null) {
            return (int) $current;
        }
        $configuration = [
            'schema_version' => 1, 'code' => $exam->code, 'name' => $exam->name, 'sample_mode' => 'none',
            'parameters' => [['code' => 'OBS', 'name' => 'Test observation', 'type' => 'text']],
        ];
        DB::table('laboratory_exam_clinical_states')->insertOrIgnore([
            'laboratory_id' => $exam->laboratory_id, 'laboratory_exam_id' => $exam->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $version = LaboratoryExamVersion::create([
            'laboratory_id' => $exam->laboratory_id, 'laboratory_exam_id' => $exam->id,
            'number' => (LaboratoryExamVersion::where('laboratory_exam_id', $exam->id)->max('number') ?? 0) + 1,
            'revision' => 1, 'status' => 'draft', 'configuration' => $configuration,
        ]);
        $version->update([
            'status' => 'published', 'revision' => 2, 'configuration_hash' => app(ClinicalExamService::class)->hash($configuration),
            'published_at' => now(), 'published_by' => User::factory()->create()->id,
        ]);
        DB::table('laboratory_exam_clinical_states')->where('laboratory_exam_id', $exam->id)->update(['current_version_id' => $version->id]);

        return $version->id;
    }
}

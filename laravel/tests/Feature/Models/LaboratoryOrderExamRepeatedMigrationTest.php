<?php

namespace Tests\Feature\Models;

use App\Models\LaboratoryOrderExam;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class LaboratoryOrderExamRepeatedMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_replaces_exam_unique_with_non_unique_order_lookup_index(): void
    {
        $indexes = $this->indexesByName();

        $this->assertArrayNotHasKey(
            'laboratory_order_exams_laboratory_order_exam_unique',
            $indexes,
        );
        $this->assertSame(
            ['laboratory_id', 'laboratory_order_id'],
            $indexes['laboratory_order_exams_laboratory_order_index']['columns'],
        );
        $this->assertFalse($indexes['laboratory_order_exams_laboratory_order_index']['unique']);
    }

    public function test_compatible_rollback_restores_unique_without_data_loss_and_reapplies(): void
    {
        $line = LaboratoryOrderExam::factory()->create();
        $before = $this->lineSnapshot($line);
        $migration = $this->migration();

        try {
            $migration->down();
            $indexes = $this->indexesByName();

            $this->assertArrayNotHasKey(
                'laboratory_order_exams_laboratory_order_index',
                $indexes,
            );
            $this->assertTrue(
                $indexes['laboratory_order_exams_laboratory_order_exam_unique']['unique'],
            );
            $this->assertSame($before, $this->lineSnapshot($line->fresh()));
        } finally {
            $migration->up();
        }

        $this->assertArrayHasKey(
            'laboratory_order_exams_laboratory_order_index',
            $this->indexesByName(),
        );
    }

    public function test_incompatible_rollback_fails_before_schema_or_data_changes(): void
    {
        $first = LaboratoryOrderExam::factory()->create([
            'unit_price' => '35.00',
            'exam_name' => 'Glucosa',
        ]);
        $second = LaboratoryOrderExam::factory()->for($first->laboratory)->create([
            'laboratory_order_id' => $first->laboratory_order_id,
            'laboratory_exam_id' => $first->laboratory_exam_id,
            'price_list_id' => $first->price_list_id,
            'unit_price' => '40.00',
            'exam_name' => 'Glucosa sérica',
        ]);
        $before = LaboratoryOrderExam::query()->orderBy('id')->get()
            ->map(fn (LaboratoryOrderExam $line): array => $this->lineSnapshot($line))
            ->all();
        $indexes = $this->indexesByName();
        $migration = $this->migration();

        try {
            $migration->down();
            $this->fail('The rollback should reject repeated exams.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Cannot restore the laboratory order exam unique constraint while repeated exams exist.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(
            $before,
            LaboratoryOrderExam::query()->orderBy('id')->get()
                ->map(fn (LaboratoryOrderExam $line): array => $this->lineSnapshot($line))
                ->all(),
        );
        $this->assertSame($indexes, $this->indexesByName());
        $this->assertSame('35.00', $first->fresh()->unit_price);
        $this->assertSame('Glucosa', $first->exam_name);
        $this->assertSame('40.00', $second->fresh()->unit_price);
        $this->assertSame('Glucosa sérica', $second->exam_name);
    }

    private function migration(): Migration
    {
        return require database_path(
            'migrations/2026_10_05_014819_allow_repeated_exams_per_laboratory_order.php',
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function indexesByName(): array
    {
        return collect(Schema::getIndexes('laboratory_order_exams'))
            ->keyBy('name')
            ->all();
    }

    /** @return array<string, mixed> */
    private function lineSnapshot(LaboratoryOrderExam $line): array
    {
        $snapshot = $line->only([
            'id',
            'laboratory_id',
            'laboratory_order_id',
            'laboratory_exam_id',
            'price_list_id',
            'unit_price',
            'exam_code',
            'exam_name',
            'price_list_name',
            'created_at',
            'updated_at',
        ]);
        $snapshot['created_at'] = $line->created_at->format('Y-m-d H:i:s.u');
        $snapshot['updated_at'] = $line->updated_at->format('Y-m-d H:i:s.u');

        return $snapshot;
    }
}

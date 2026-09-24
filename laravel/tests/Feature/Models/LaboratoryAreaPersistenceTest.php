<?php

namespace Tests\Feature\Models;

use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LaboratoryAreaPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_laboratory_area_can_be_persisted_for_a_laboratory(): void
    {
        $laboratory = Laboratory::factory()->create();

        $area = LaboratoryArea::factory()->for($laboratory)->create([
            'code' => 'HEM',
            'name' => 'Hematología',
            'description' => 'Área de análisis hematológicos.',
            'status' => LaboratoryArea::STATUS_INACTIVE,
        ])->fresh();

        $this->assertDatabaseHas('laboratory_areas', [
            'id' => $area->id,
            'laboratory_id' => $laboratory->id,
            'code' => 'HEM',
            'name' => 'Hematología',
            'description' => 'Área de análisis hematológicos.',
            'status' => LaboratoryArea::STATUS_INACTIVE,
        ]);
        $this->assertTrue($area->laboratory->is($laboratory));
        $this->assertNotNull($area->created_at);
        $this->assertNotNull($area->updated_at);
    }

    public function test_laboratory_can_obtain_its_laboratory_areas(): void
    {
        $laboratory = Laboratory::factory()->create();
        $areas = LaboratoryArea::factory()->count(2)->for($laboratory)->create();

        $this->assertCount(2, $laboratory->laboratoryAreas);
        $this->assertTrue($areas->every(
            fn (LaboratoryArea $area): bool => $laboratory->laboratoryAreas->contains($area)
        ));
    }

    public function test_database_defaults_status_to_active_and_description_accepts_null(): void
    {
        $area = LaboratoryArea::query()->create([
            'laboratory_id' => Laboratory::factory()->create()->id,
            'code' => 'DEF',
            'name' => 'Default Area',
            'description' => null,
        ])->fresh();

        $this->assertSame(LaboratoryArea::STATUS_ACTIVE, $area->status);
        $this->assertNull($area->description);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('missingRequiredAttributeProvider')]
    public function test_database_rejects_null_required_fields(array $attributes): void
    {
        $this->expectException(QueryException::class);

        LaboratoryArea::query()->create(array_merge([
            'laboratory_id' => Laboratory::factory()->create()->id,
            'code' => 'REQ',
            'name' => 'Required Area',
            'status' => LaboratoryArea::STATUS_ACTIVE,
        ], $attributes));
    }

    /**
     * @return array<string, array{array<string, null>}>
     */
    public static function missingRequiredAttributeProvider(): array
    {
        return [
            'laboratory_id' => [['laboratory_id' => null]],
            'code' => [['code' => null]],
            'name' => [['name' => null]],
            'status' => [['status' => null]],
        ];
    }

    public function test_inactive_status_can_be_persisted(): void
    {
        $area = LaboratoryArea::factory()->create([
            'status' => LaboratoryArea::STATUS_INACTIVE,
        ])->fresh();

        $this->assertSame(LaboratoryArea::STATUS_INACTIVE, $area->status);
    }

    public function test_laboratory_area_cannot_reference_a_nonexistent_laboratory(): void
    {
        $this->expectException(QueryException::class);
        LaboratoryArea::factory()->create(['laboratory_id' => 999999]);
    }

    public function test_laboratory_with_laboratory_areas_cannot_be_deleted(): void
    {
        $laboratory = Laboratory::factory()->create();
        LaboratoryArea::factory()->for($laboratory)->create();

        $this->expectException(QueryException::class);
        $laboratory->delete();
    }

    public function test_explicit_scope_isolates_laboratory_areas_symmetrically_without_implicit_filtering(): void
    {
        $labA = Laboratory::factory()->create();
        $labB = Laboratory::factory()->create();

        $areasA = LaboratoryArea::factory()->count(2)->for($labA)->create();
        $areaB = LaboratoryArea::factory()->for($labB)->create();

        $this->assertCount(3, LaboratoryArea::query()->get());
        $this->assertEqualsCanonicalizing(
            $areasA->modelKeys(),
            LaboratoryArea::forLaboratory($labA)->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$areaB->id],
            LaboratoryArea::forLaboratory($labB)->pluck('id')->all(),
        );
    }

    public function test_explicit_scope_and_factory_do_not_require_a_current_laboratory(): void
    {
        $laboratory = Laboratory::factory()->create();
        $currentLaboratory = $this->app->make(CurrentLaboratory::class);

        $this->assertFalse($currentLaboratory->has());
        $area = LaboratoryArea::factory()->for($laboratory)->create();
        $this->assertTrue(
            LaboratoryArea::forLaboratory($laboratory)->firstOrFail()->is($area)
        );
        $this->assertFalse($currentLaboratory->has());
    }

    public function test_duplicate_code_is_rejected_within_the_same_laboratory(): void
    {
        $laboratory = Laboratory::factory()->create();
        LaboratoryArea::factory()->for($laboratory)->create(['code' => 'HEM']);

        $this->expectException(QueryException::class);
        LaboratoryArea::factory()->for($laboratory)->create(['code' => 'HEM']);
    }

    public function test_duplicate_code_is_allowed_across_laboratories(): void
    {
        $labA = Laboratory::factory()->create();
        $labB = Laboratory::factory()->create();

        LaboratoryArea::factory()->for($labA)->create(['code' => 'HEM']);
        LaboratoryArea::factory()->for($labB)->create(['code' => 'HEM']);

        $this->assertSame(2, LaboratoryArea::query()->where('code', 'HEM')->count());
    }

    public function test_duplicate_name_is_rejected_within_the_same_laboratory(): void
    {
        $laboratory = Laboratory::factory()->create();
        LaboratoryArea::factory()->for($laboratory)->create(['name' => 'Hematología']);

        $this->expectException(QueryException::class);
        LaboratoryArea::factory()->for($laboratory)->create(['name' => 'Hematología']);
    }

    public function test_duplicate_name_is_allowed_across_laboratories(): void
    {
        $labA = Laboratory::factory()->create();
        $labB = Laboratory::factory()->create();

        LaboratoryArea::factory()->for($labA)->create(['name' => 'Hematología']);
        LaboratoryArea::factory()->for($labB)->create(['name' => 'Hematología']);

        $this->assertSame(2, LaboratoryArea::query()->where('name', 'Hematología')->count());
    }

    public function test_code_and_name_unique_constraints_are_independent(): void
    {
        $laboratory = Laboratory::factory()->create();

        LaboratoryArea::factory()->for($laboratory)->create([
            'code' => 'HEM',
            'name' => 'Hematología',
        ]);
        LaboratoryArea::factory()->for($laboratory)->create([
            'code' => 'HEM2',
            'name' => 'Hematología Especial',
        ]);
        LaboratoryArea::factory()->for($laboratory)->create([
            'code' => 'QUI',
            'name' => 'Química Clínica',
        ]);

        $this->assertSame(3, $laboratory->laboratoryAreas()->count());
    }
}

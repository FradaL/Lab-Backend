<?php

namespace Tests\Feature\Models;

use App\Models\Laboratory;
use App\Models\SampleType;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SampleTypePersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sample_type_can_be_persisted_for_a_laboratory(): void
    {
        $laboratory = Laboratory::factory()->create();

        $sampleType = SampleType::factory()->for($laboratory)->create([
            'name' => 'Sangre',
            'status' => SampleType::STATUS_INACTIVE,
        ])->fresh();

        $this->assertDatabaseHas('sample_types', [
            'id' => $sampleType->id,
            'laboratory_id' => $laboratory->id,
            'name' => 'Sangre',
            'status' => SampleType::STATUS_INACTIVE,
        ]);
        $this->assertTrue($sampleType->laboratory->is($laboratory));
        $this->assertNotNull($sampleType->created_at);
        $this->assertNotNull($sampleType->updated_at);
    }

    public function test_laboratory_can_obtain_its_sample_types(): void
    {
        $laboratory = Laboratory::factory()->create();
        $sampleTypes = SampleType::factory()->count(2)->for($laboratory)->create();

        $this->assertCount(2, $laboratory->sampleTypes);
        $this->assertTrue($sampleTypes->every(
            fn (SampleType $sampleType): bool => $laboratory->sampleTypes->contains($sampleType)
        ));
    }

    public function test_laboratory_relation_can_create_an_owned_sample_type_with_database_default_status(): void
    {
        $laboratory = Laboratory::factory()->create();

        $sampleType = $laboratory->sampleTypes()->create([
            'name' => 'Plasma',
        ])->fresh();

        $this->assertSame($laboratory->id, $sampleType->laboratory_id);
        $this->assertSame(SampleType::STATUS_ACTIVE, $sampleType->status);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('missingRequiredAttributeProvider')]
    public function test_database_rejects_null_required_fields(array $attributes): void
    {
        $this->expectException(QueryException::class);

        SampleType::query()->create(array_merge([
            'laboratory_id' => Laboratory::factory()->create()->id,
            'name' => 'Required Sample Type',
            'status' => SampleType::STATUS_ACTIVE,
        ], $attributes));
    }

    /**
     * @return array<string, array{array<string, null>}>
     */
    public static function missingRequiredAttributeProvider(): array
    {
        return [
            'laboratory_id' => [['laboratory_id' => null]],
            'name' => [['name' => null]],
        ];
    }

    public function test_inactive_factory_state_can_be_persisted(): void
    {
        $sampleType = SampleType::factory()->inactive()->create()->fresh();

        $this->assertSame(SampleType::STATUS_INACTIVE, $sampleType->status);
    }

    public function test_sample_type_cannot_reference_a_nonexistent_laboratory(): void
    {
        $this->expectException(QueryException::class);
        SampleType::factory()->create(['laboratory_id' => 999999]);
    }

    public function test_laboratory_with_sample_types_cannot_be_deleted(): void
    {
        $laboratory = Laboratory::factory()->create();
        SampleType::factory()->for($laboratory)->create();

        $this->expectException(QueryException::class);
        $laboratory->delete();
    }

    public function test_explicit_scope_isolates_sample_types_symmetrically_without_implicit_filtering(): void
    {
        $labA = Laboratory::factory()->create();
        $labB = Laboratory::factory()->create();

        $blood = SampleType::factory()->for($labA)->create(['name' => 'Sangre']);
        $urine = SampleType::factory()->for($labA)->create(['name' => 'Orina']);
        $serum = SampleType::factory()->for($labB)->create(['name' => 'Suero']);

        $this->assertCount(3, SampleType::query()->get());
        $this->assertEqualsCanonicalizing(
            [$blood->id, $urine->id],
            SampleType::forLaboratory($labA)->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$serum->id],
            SampleType::forLaboratory($labB)->pluck('id')->all(),
        );
    }

    public function test_explicit_scope_and_factory_do_not_require_a_current_laboratory(): void
    {
        $laboratory = Laboratory::factory()->create();
        $currentLaboratory = $this->app->make(CurrentLaboratory::class);

        $this->assertFalse($currentLaboratory->has());
        $sampleType = SampleType::factory()->for($laboratory)->create();
        $this->assertTrue(
            SampleType::forLaboratory($laboratory)->firstOrFail()->is($sampleType)
        );
        $this->assertFalse($currentLaboratory->has());
    }

    public function test_duplicate_name_is_rejected_within_the_same_laboratory(): void
    {
        $laboratory = Laboratory::factory()->create();
        SampleType::factory()->for($laboratory)->create(['name' => 'Sangre']);

        $this->expectException(QueryException::class);
        SampleType::factory()->for($laboratory)->create(['name' => 'Sangre']);
    }

    public function test_duplicate_name_is_allowed_across_laboratories(): void
    {
        $labA = Laboratory::factory()->create();
        $labB = Laboratory::factory()->create();

        SampleType::factory()->for($labA)->create(['name' => 'Sangre']);
        SampleType::factory()->for($labB)->create(['name' => 'Sangre']);

        $this->assertSame(2, SampleType::query()->where('name', 'Sangre')->count());
    }

    public function test_names_with_different_casing_are_allowed_within_the_same_laboratory(): void
    {
        $laboratory = Laboratory::factory()->create();

        SampleType::factory()->for($laboratory)->create(['name' => 'Sangre']);
        SampleType::factory()->for($laboratory)->create(['name' => 'sangre']);

        $this->assertSame(2, $laboratory->sampleTypes()->count());
    }

    public function test_model_has_expected_fillable_attributes_and_does_not_use_soft_deletes(): void
    {
        $sampleType = new SampleType;

        $this->assertSame(
            ['laboratory_id', 'name', 'status'],
            $sampleType->getFillable(),
        );
        $this->assertNotContains(SoftDeletes::class, class_uses_recursive(SampleType::class));
        $this->assertTrue($sampleType->usesTimestamps());
    }
}

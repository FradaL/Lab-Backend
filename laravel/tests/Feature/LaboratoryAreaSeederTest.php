<?php

namespace Tests\Feature;

use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LaboratoryAreaSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_laboratory_areas_are_seeded_idempotently(): void
    {
        $this->seed();
        $this->seed();

        $laboratory = Laboratory::query()
            ->where('nit', '000000000001')
            ->firstOrFail();

        $areas = LaboratoryArea::query()
            ->whereBelongsTo($laboratory)
            ->orderBy('code')
            ->get();

        $this->assertCount(8, $areas);
        $this->assertSame(
            ['BIO', 'COA', 'COP', 'HEM', 'INM', 'MIC', 'QUI', 'URO'],
            $areas->pluck('code')->all(),
        );
        $this->assertSame(
            [LaboratoryArea::STATUS_ACTIVE],
            $areas->pluck('status')->unique()->values()->all(),
        );
        $this->assertDatabaseHas('laboratory_areas', [
            'laboratory_id' => $laboratory->id,
            'code' => 'HEM',
            'name' => 'Hematología',
        ]);
        $this->assertDatabaseHas('laboratory_areas', [
            'laboratory_id' => $laboratory->id,
            'code' => 'BIO',
            'name' => 'Biología Molecular',
        ]);
    }
}

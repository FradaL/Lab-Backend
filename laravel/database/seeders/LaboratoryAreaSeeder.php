<?php

namespace Database\Seeders;

use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use Illuminate\Database\Seeder;

class LaboratoryAreaSeeder extends Seeder
{
    /**
     * Seed the demo laboratory areas.
     */
    public function run(): void
    {
        $laboratory = Laboratory::query()
            ->where('nit', '000000000001')
            ->firstOrFail();

        $areas = [
            ['code' => 'HEM', 'name' => 'Hematología', 'description' => 'Análisis hematológicos y de sangre.'],
            ['code' => 'QUI', 'name' => 'Química Clínica', 'description' => 'Pruebas bioquímicas en fluidos corporales.'],
            ['code' => 'MIC', 'name' => 'Microbiología', 'description' => 'Identificación de microorganismos y pruebas de susceptibilidad.'],
            ['code' => 'INM', 'name' => 'Inmunología', 'description' => 'Evaluación de la respuesta inmunitaria.'],
            ['code' => 'COA', 'name' => 'Coagulación', 'description' => 'Estudios de hemostasia y coagulación.'],
            ['code' => 'URO', 'name' => 'Uroanálisis', 'description' => 'Análisis físico, químico y microscópico de orina.'],
            ['code' => 'COP', 'name' => 'Coprología', 'description' => 'Análisis de muestras fecales.'],
            ['code' => 'BIO', 'name' => 'Biología Molecular', 'description' => 'Pruebas moleculares y detección de material genético.'],
        ];

        foreach ($areas as $area) {
            LaboratoryArea::query()->updateOrCreate(
                [
                    'laboratory_id' => $laboratory->id,
                    'code' => $area['code'],
                ],
                [
                    'name' => $area['name'],
                    'description' => $area['description'],
                    'status' => LaboratoryArea::STATUS_ACTIVE,
                ],
            );
        }
    }
}

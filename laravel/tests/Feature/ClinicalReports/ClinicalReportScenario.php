<?php

namespace Tests\Feature\ClinicalReports;

use App\ClinicalFinalization\ClinicalResolutionService;
use App\ClinicalResults\LaboratoryResultService;
use App\ClinicalValidation\LaboratoryValidationService;
use App\ClinicalValidation\ValidationAdministrationService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\ClinicalResults\ClinicalResultScenario;

trait ClinicalReportScenario
{
    use ClinicalResultScenario;

    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh');
        Storage::fake('clinical_reports');
        $this->beforeApplicationDestroyed(fn () => RefreshDatabaseState::$migrated = false);
    }

    protected function resolved(bool $close = false, array $overrides = []): array
    {
        [$lab,$actor,$order,$line] = $this->scenario(true, $overrides);
        app(ValidationAdministrationService::class)->setPolicy($lab, $actor, ['validation_policy' => 'permission_based']);
        app(LaboratoryResultService::class)->save($lab, $actor, $order->id, $line->id, ['revision' => 0, 'results' => [['parameter_code' => 'N', 'value' => '12345678901234567890.123456789'], ['parameter_code' => 'T', 'value' => 'FICTICIO'], ['parameter_code' => 'Q', 'value' => 'YES'], ['parameter_code' => 'O', 'value' => 'A']]]);
        app(LaboratoryValidationService::class)->validate($lab, $actor, $order->id, $line->id, ['revision' => 1, 'reference_context' => ['reference_date' => '2026-10-11']]);
        app(ClinicalResolutionService::class)->finalizeLine($lab, $actor, $order->id, $line->id, ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 2]);
        if ($close) {
            app(ClinicalResolutionService::class)->finalizeOrder($lab, $actor, $order->id, ['clinical_revision' => $order->fresh()->clinical_revision]);
        }

        return [$lab, $actor, $order, $line];
    }

    protected function body($order, $line, bool $complete = false): array
    {
        return ['type' => $complete ? 'complete' : 'partial', 'clinical_revision' => $order->fresh()->clinical_revision, 'identity_confirmation' => true]
            + ($complete ? [] : ['line_ids' => [$line->id]]);
    }
}

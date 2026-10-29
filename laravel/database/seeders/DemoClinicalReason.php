<?php

namespace Database\Seeders;

use App\Authorization\RbacCatalog;
use App\ClinicalExams\ClinicalReasonAdoptionManager;
use App\ClinicalExams\LocalClinicalReasonManager;
use App\ClinicalReplacements\ClinicalReplacementService;
use App\Models\ClinicalReasonAdoption;
use App\Models\GlobalClinicalReason;
use App\Models\Laboratory;
use App\Models\LaboratoryClinicalReason;
use App\Models\LaboratoryOrderExam;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;
use Spatie\Permission\Models\Role;

/** Demo consumers use structured tenant reasons; they never bootstrap SaaS privileges. */
final class DemoClinicalReason
{
    public static function reference(Laboratory $lab, User $actor): array
    {
        if (! app()->environment('local', 'testing') || DB::transactionLevel() === 0) {
            throw new LogicException('Demo catalog selection requires its local/testing dataset transaction.');
        }
        DemoDataset::assertOwned($lab);
        DemoDataset::assertOwned($actor);
        $configured = config('saas.demo_clinical_reason_id');
        if ($configured === null || $configured === '') {
            return self::localReference($lab, $actor);
        }
        if (! is_scalar($configured) || ! preg_match('/^[1-9][0-9]*$/', (string) $configured)) {
            throw new LogicException('SAAS_DEMO_CLINICAL_REASON_ID must be a positive integer identifying a provisioned FICTICIO global reason, or empty to use structured local demo reasons.');
        }
        $reason = GlobalClinicalReason::with('latestVersion')->findOrFail((int) $configured);
        if ($reason->latestVersion->classification !== 'terminal_candidate' || ! str_contains($reason->latestVersion->name, 'FICTICIO')) {
            throw new LogicException('The configured demo reason must be a synthetic terminal candidate.');
        }
        $adoption = ClinicalReasonAdoption::forLaboratory($lab)->where('reason_id', $reason->id)->with('latestVersion')->first();
        if ($adoption === null) {
            $actor->assignRole(Role::findOrCreate('owner', RbacCatalog::GUARD));
            $selection = app(ClinicalReasonAdoptionManager::class)->adopt($lab, $actor, ['reason_id' => $reason->id, 'version_id' => $reason->latestVersion->id, 'justification' => DemoDataset::DISCLAIMER.' Selección individual explícita del escenario.'])['data'];
            $adoption = $selection['adoption']->load('latestVersion');
        }
        $selection = $adoption->latestVersion;

        return ['source' => 'global', 'reason_id' => $reason->id, 'version_id' => $selection->version_id, 'adoption_id' => $adoption->id, 'adoption_version_id' => $selection->id];
    }

    public static function medicalEvidence(Laboratory $lab, User $actor, LaboratoryOrderExam $line): void
    {
        if (! app()->environment('local', 'testing') || DB::transactionLevel() === 0) {
            throw new LogicException('Synthetic clinical evidence requires the local/testing dataset transaction.');
        }
        DemoDataset::assertOwned($lab);
        DemoDataset::assertOwned($actor);
        $ownedOrder = DB::table('demo_dataset_records')->where('demo_dataset_id', DB::table('demo_datasets')->where('dataset_key', DemoDataset::KEY)->value('id'))
            ->whereIn('entity_kind', ['finalization_order', 'report_demo_order'])->where('entity_id', $line->laboratory_order_id)->exists();
        if ($line->laboratory_id !== $lab->id || ! $ownedOrder) {
            throw new LogicException('Clinical demo evidence requires its explicitly registered scenario order.');
        }
        $revision = (int) DB::table('clinical_replacement_contexts')->where('laboratory_order_exam_id', $line->id)->value('revision');
        app(ClinicalReplacementService::class)->recordEvidence($lab, $actor, $line->laboratory_order_id, $line->id, [
            'expected_revision' => $revision, 'occurred_at' => now()->toISOString(), 'kind' => 'medical_instruction',
            'facts' => ['instruction' => DemoDataset::DISCLAIMER.' Indicación médica sintética de no realizar este examen del escenario.'],
        ]);
    }

    private static function localReference(Laboratory $lab, User $actor): array
    {
        $reason = DemoDataset::create(LaboratoryClinicalReason::class,
            ['laboratory_id' => $lab->id, 'code' => 'demo_clinical_exception'],
            factory: function () use ($lab, $actor): LaboratoryClinicalReason {
                $actor->assignRole(Role::findOrCreate('owner', RbacCatalog::GUARD));

                return app(LocalClinicalReasonManager::class)->create($lab, $actor, [
                    'code' => 'demo_clinical_exception',
                    'definition' => [
                        'name' => 'FICTICIO: imposibilidad de completar el examen',
                        'description' => DemoDataset::DISCLAIMER,
                        'classification' => 'terminal_candidate',
                        'evidence_requirements' => ['schema_version' => 1, 'requirements' => ['medical_instruction']],
                        'is_active' => true,
                        'change_reason' => 'FICTICIO: preparación del dataset de laboratorio.',
                    ],
                ])['data']['reason'];
            });

        return ['source' => 'local', 'reason_id' => $reason->id, 'version_id' => $reason->latestVersion->id];
    }
}

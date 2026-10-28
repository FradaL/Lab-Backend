<?php

namespace Database\Seeders;

use App\Authorization\RbacCatalog;
use App\ClinicalExams\ClinicalReasonAdoptionManager;
use App\Models\ClinicalReasonAdoption;
use App\Models\GlobalClinicalReason;
use App\Models\Laboratory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;
use Spatie\Permission\Models\Role;

/** Demo consumers use the same structured contract; they never bootstrap SaaS privileges. */
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
        if (! is_scalar($configured) || ! preg_match('/^[1-9][0-9]*$/', (string) $configured)) {
            throw new LogicException('Demo clinical exceptions require an explicitly provisioned FICTICIO global reason in SAAS_DEMO_CLINICAL_REASON_ID. No free-text fallback is available.');
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
}

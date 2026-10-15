<?php

namespace Database\Seeders;

use App\ClinicalReports\ClinicalReportService;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Explicit opt-in: separate filesystem effects from the atomic default dataset. */
final class LaboratoryReportIssuanceSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing', 'local') || DB::transactionLevel() !== 0) {
            throw new LogicException('Explicit demo report emission requires local/testing and no outer transaction.');
        }
        $team = getPermissionsTeamId();
        try {
            $lab = DemoDataset::owned(Laboratory::class, ['nit' => 'DEMO-BE091-1']);
            $actor = DemoDataset::owned(User::class, ['email' => 'be091-manager@donqerlab.test']);
            setPermissionsTeamId($lab->id);
            foreach (['partial', 'complete'] as $kind) {
                $id = DB::table('demo_dataset_records')->where('demo_dataset_id', DB::table('demo_datasets')->where('dataset_key', DemoDataset::KEY)->value('id'))
                    ->where('entity_kind', 'report_demo_order')->where('entity_key', 'be091-'.$kind)->value('entity_id');
                $order = LaboratoryOrder::forLaboratory($lab)->whereKey($id)->firstOrFail();
                $input = ['type' => $kind, 'clinical_revision' => $order->clinical_revision];
                if ($kind === 'partial') {
                    $input['line_ids'] = $order->orderExams()->whereHas('clinicalResolution')->orderBy('id')->pluck('id')->all();
                }
                app(ClinicalReportService::class)->issue($lab, $actor, $order->id, $input, 'demo-be091-emission-'.$kind.'-v1');
            }
        } finally {
            setPermissionsTeamId($team);
        }
    }
}

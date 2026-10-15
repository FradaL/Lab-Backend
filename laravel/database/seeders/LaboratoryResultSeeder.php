<?php

namespace Database\Seeders;

use App\Actions\LaboratoryOrders\CreateLaboratoryOrder;
use App\ClinicalExams\ClinicalExamService;
use App\ClinicalExams\LaboratorySampleService;
use App\ClinicalResults\LaboratoryResultService;
use App\Models\Branch;
use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryOrder;
use App\Models\Patient;
use App\Models\PriceList;
use App\Models\PriceListExam;
use App\Models\User;
use Illuminate\Database\Seeder;
use LogicException;
use Spatie\Permission\PermissionRegistrar;

/** New, registered fictional scenarios only; no adoption of existing lines or captures. */
final class LaboratoryResultSeeder extends Seeder
{
    public function run(): void
    {
        $previousTeam = getPermissionsTeamId();
        try {
            DemoDataset::run(function (): void {
                $lab = DemoDataset::owned(Laboratory::class, ['nit' => '000000000001']);
                $actor = DemoDataset::owned(User::class, ['email' => 'admin@donqerlab.test']);
                setPermissionsTeamId($lab->id);
                $actor->unsetRelation('roles')->unsetRelation('permissions');
                DemoDataset::remember('result_order_scenario', 'results-v1', fn ($id) => LaboratoryOrder::forLaboratory($lab)->whereKey($id)->first(), function () use ($lab, $actor) {
                    foreach (['results.capture', 'orders.start_exam', 'orders.create', 'orders.add_exam', 'laboratory_exams.create_draft', 'laboratory_exams.publish'] as $permission) {
                        if (! $actor->can($permission)) {
                            throw new LogicException('Explicitly provision BE-S2-06 demo permissions. Existing roles will not be overwritten.');
                        }
                    }
                    $area = DemoDataset::owned(LaboratoryArea::class, ['laboratory_id' => $lab->id, 'code' => 'HEM']);
                    $prices = DemoDataset::owned(PriceList::class, ['laboratory_id' => $lab->id, 'name' => 'Tarifa Particular']);
                    $exam = DemoDataset::create(LaboratoryExam::class, ['laboratory_id' => $lab->id, 'code' => 'DEMO-RESULTS'], factory: function () use ($lab, $actor, $area) {
                        $clinical = app(ClinicalExamService::class);
                        $draft = $clinical->create($lab, $actor, ['code' => 'DEMO-RESULTS', 'name' => 'DEMO — Resultados ficticios', 'laboratory_area_id' => $area->id,
                            'sample_type_id' => null, 'configuration' => ['sample_mode' => 'none', 'description' => DemoDataset::DISCLAIMER, 'parameters' => [
                                ['code' => 'NUM', 'name' => 'Número ficticio', 'type' => 'numeric', 'unit_required' => true, 'unit' => 'DEMO-U'],
                                ['code' => 'TEXT', 'name' => 'Texto ficticio', 'type' => 'text', 'text_max_length' => 2000],
                                ['code' => 'QUAL', 'name' => 'Cualitativo ficticio', 'type' => 'qualitative', 'options' => [['code' => 'DEMO-Q', 'label' => 'Opción cualitativa ficticia']]],
                                ['code' => 'OPT', 'name' => 'Selección ficticia', 'type' => 'predefined_options', 'options' => [['code' => 'DEMO-O', 'label' => 'Opción predefinida ficticia']]],
                            ]]]);
                        $clinical->publish($lab, $actor, $draft->laboratory_exam_id, $draft->id, 1);
                        $exam = LaboratoryExam::findOrFail($draft->laboratory_exam_id);
                        $exam->update(['status' => 'active']);

                        return $exam;
                    });
                    DemoDataset::create(PriceListExam::class, ['laboratory_id' => $lab->id, 'price_list_id' => $prices->id, 'laboratory_exam_id' => $exam->id], ['price' => '25.00', 'status' => 'active']);
                    $branch = DemoDataset::owned(Branch::class, ['laboratory_id' => $lab->id, 'code' => 'MAIN']);
                    $patient = DemoDataset::owned(Patient::class, ['laboratory_id' => $lab->id, 'affiliation_number' => 'DEMO-0001']);
                    $order = app(CreateLaboratoryOrder::class)->execute($lab, $actor, ['branch_id' => $branch->id, 'patient_id' => $patient->id, 'doctor_id' => null,
                        'commercial_client_id' => null, 'price_list_id' => $prices->id, 'ordered_at' => now()->format('Y-m-d H:i:s'), 'notes' => DemoDataset::DISCLAIMER.' Capturas vacía, parcial y completa BE-S2-06.',
                        'exams' => array_fill(0, 3, ['laboratory_exam_id' => $exam->id])]);
                    $lines = $order->orderExams()->orderBy('id')->get();
                    foreach ($lines as $line) {
                        app(LaboratorySampleService::class)->start($lab, $actor, $order->id, $line->id);
                    }
                    $partial = [['parameter_code' => 'NUM', 'value' => '1.2500'], ['parameter_code' => 'TEXT', 'value' => DemoDataset::DISCLAIMER]];
                    $service = app(LaboratoryResultService::class);
                    $service->save($lab, $actor, $order->id, $lines[1]->id, ['revision' => 0, 'results' => $partial]);
                    $service->save($lab, $actor, $order->id, $lines[2]->id, ['revision' => 0, 'results' => [...$partial, ['parameter_code' => 'QUAL', 'value' => 'DEMO-Q'], ['parameter_code' => 'OPT', 'value' => 'DEMO-O']]]);

                    return $order->refresh();
                });
            });
        } finally {
            setPermissionsTeamId($previousTeam);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
}

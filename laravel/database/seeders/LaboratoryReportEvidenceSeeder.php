<?php

namespace Database\Seeders;

use App\Actions\LaboratoryOrders\CreateLaboratoryOrder;
use App\Authorization\RbacCatalog;
use App\ClinicalExams\ClinicalExamService;
use App\ClinicalExams\LaboratorySampleService;
use App\ClinicalFinalization\ClinicalResolutionService;
use App\ClinicalReports\ReportSettingsService;
use App\ClinicalResults\LaboratoryResultService;
use App\ClinicalValidation\LaboratoryValidationService;
use App\ClinicalValidation\ValidationAdministrationService;
use App\Models\Branch;
use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryUser;
use App\Models\Patient;
use App\Models\Plan;
use App\Models\PriceList;
use App\Models\PriceListExam;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

final class LaboratoryReportEvidenceSeeder extends Seeder
{
    public function run(): void
    {
        $team = getPermissionsTeamId();
        try {
            DemoDataset::run(function () {
                DemoDataset::remember('report_evidence_scenario', 'be091-v1', fn ($id) => Laboratory::find($id), function () {
                    $lab = DemoDataset::create(Laboratory::class, ['nit' => 'DEMO-BE091-1'], ['name' => 'FICTICIO BE09.1', 'is_active' => true, 'timezone' => 'America/Guatemala', 'currency' => 'GTQ']);
                    setPermissionsTeamId($lab->id);
                    $actors = [];
                    foreach (['manager', 'a', 'b'] as $key) {
                        $user = DemoDataset::create(User::class, ['email' => 'be091-'.$key.'@donqerlab.test'], ['name' => 'FICTICIO BE091 '.$key, 'password' => 'demo-be091-password', 'is_active' => true]);
                        DemoDataset::create(LaboratoryUser::class, ['laboratory_id' => $lab->id, 'user_id' => $user->id], ['is_active' => true, 'is_default' => true]);
                        foreach (RbacCatalog::PERMISSIONS as $name) {
                            $user->givePermissionTo(Permission::findOrCreate($name, RbacCatalog::GUARD));
                        }
                        $actors[$key] = $user;
                    }
                    $manager = $actors['manager'];
                    $management = app(ValidationAdministrationService::class);
                    foreach (['a', 'b'] as $key) {
                        $p = $management->register($lab, $manager, ['user_id' => $actors[$key]->id, 'professional_name' => 'FICTICIO profesional '.$key, 'profession' => 'FICTICIO DEMO', 'registration_number' => 'FICTICIO-'.$key]);
                        $management->professionalState($lab, $manager, $p->id, true);
                    }
                    $plan = DemoDataset::owned(Plan::class, ['code' => 'DEMO']);
                    DemoDataset::create(Subscription::class, ['laboratory_id' => $lab->id, 'plan_id' => $plan->id, 'starts_at' => '2026-01-01 00:00:00'], ['status' => 'active', 'ends_at' => null]);
                    $branch = DemoDataset::create(Branch::class, ['laboratory_id' => $lab->id, 'code' => 'BE091'], ['name' => 'FICTICIO sucursal', 'status' => 'active', 'is_main' => true]);
                    $patient = DemoDataset::create(Patient::class, ['laboratory_id' => $lab->id, 'affiliation_number' => 'BE091'], ['first_names' => 'FICTICIO', 'last_names' => 'PACIENTE', 'status' => 'active']);
                    $prices = DemoDataset::create(PriceList::class, ['laboratory_id' => $lab->id, 'name' => 'DEMO BE091'], ['currency' => 'GTQ', 'status' => 'active', 'is_default' => false]);
                    $exams = [];
                    foreach (['a', 'b'] as $key) {
                        $area = DemoDataset::create(LaboratoryArea::class, ['laboratory_id' => $lab->id, 'code' => 'BE091-'.$key], ['name' => 'FICTICIO área '.$key, 'status' => 'active']);
                        $exam = DemoDataset::create(LaboratoryExam::class, ['laboratory_id' => $lab->id, 'code' => 'BE091-'.$key], factory: function () use ($lab, $manager, $area, $key) {
                            $service = app(ClinicalExamService::class);
                            $d = $service->create($lab, $manager, ['code' => 'BE091-'.$key, 'name' => 'FICTICIO examen '.$key, 'laboratory_area_id' => $area->id, 'sample_type_id' => null,
                                'configuration' => ['description' => DemoDataset::DISCLAIMER, 'sample_mode' => 'none', 'parameters' => [['code' => 'TEXT', 'name' => 'FICTICIO parámetro', 'type' => 'text']]]]);
                            $service->publish($lab, $manager, $d->laboratory_exam_id, $d->id, 1);
                            $e = LaboratoryExam::findOrFail($d->laboratory_exam_id);
                            $e->update(['status' => 'active']);

                            return $e;
                        });
                        DemoDataset::create(PriceListExam::class, ['laboratory_id' => $lab->id, 'price_list_id' => $prices->id, 'laboratory_exam_id' => $exam->id], ['price' => '25.00', 'status' => 'active']);
                        $exams[] = $exam;
                    }
                    app(ReportSettingsService::class)->update($lab, $manager, ['revision' => 0, 'trade_name' => 'FICTICIO informes BE091', 'header' => DemoDataset::DISCLAIMER, 'footer' => 'FICTICIO documento de demostración']);
                    foreach (['partial', 'complete'] as $kind) {
                        $order = app(CreateLaboratoryOrder::class)->execute($lab, $manager, ['branch_id' => $branch->id, 'patient_id' => $patient->id, 'doctor_id' => null, 'commercial_client_id' => null,
                            'price_list_id' => $prices->id, 'ordered_at' => now()->format('Y-m-d H:i:s'), 'notes' => DemoDataset::DISCLAIMER.' '.$kind,
                            'exams' => [['laboratory_exam_id' => $exams[0]->id], ['laboratory_exam_id' => $exams[1]->id], ['laboratory_exam_id' => $exams[0]->id]]]);
                        DemoDataset::remember('report_demo_order', 'be091-'.$kind, fn ($id) => LaboratoryOrder::forLaboratory($lab)->find($id), fn () => $order);
                        $resolution = app(ClinicalResolutionService::class);
                        foreach ($order->orderExams()->orderBy('id')->get() as $i => $line) {
                            if ($i === 2) {
                                if ($kind === 'complete') {
                                    $r = $resolution->requestException($lab, $manager, $order->id, $line->id, ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 0, 'reason' => DemoDataset::DISCLAIMER]);
                                    $resolution->decideException($lab, $actors['b'], $order->id, $line->id, $r['exception']['id'], true, ['clinical_revision' => $r['clinical_revision'], 'request_revision' => 1]);
                                }

                                continue;
                            }
                            $validator = $actors[$i === 0 ? 'a' : 'b'];
                            app(LaboratorySampleService::class)->start($lab, $manager, $order->id, $line->id);
                            app(LaboratoryResultService::class)->save($lab, $validator, $order->id, $line->id, ['revision' => 0, 'results' => [['parameter_code' => 'TEXT', 'value' => DemoDataset::DISCLAIMER]]]);
                            app(LaboratoryValidationService::class)->validate($lab, $validator, $order->id, $line->id, ['revision' => 1, 'reference_context' => ['reference_date' => now()->format('Y-m-d')], 'comment' => DemoDataset::DISCLAIMER]);
                            $resolution->finalizeLine($lab, $manager, $order->id, $line->id, ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 2]);
                        }
                        if ($kind === 'complete') {
                            $resolution->finalizeOrder($lab, $manager, $order->id, ['clinical_revision' => $order->fresh()->clinical_revision]);
                        }
                    }

                    return $lab;
                });
            });
        } finally {
            setPermissionsTeamId($team);
        }
    }
}

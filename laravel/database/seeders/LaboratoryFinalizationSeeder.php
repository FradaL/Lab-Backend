<?php

namespace Database\Seeders;

use App\Actions\LaboratoryOrders\CreateLaboratoryOrder;
use App\Authorization\RbacCatalog;
use App\ClinicalExams\ClinicalExamService;
use App\ClinicalExams\LaboratorySampleService;
use App\ClinicalFinalization\ClinicalResolutionService;
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
use Spatie\Permission\PermissionRegistrar;

final class LaboratoryFinalizationSeeder extends Seeder
{
    public function run(): void
    {
        $team = getPermissionsTeamId();
        try {
            DemoDataset::run(function (): void {
                DemoDataset::remember('finalization_scenario', 'be08-v1', fn ($id) => Laboratory::whereKey($id)->first(), function () {
                    $lab = DemoDataset::create(Laboratory::class, ['nit' => 'DEMO-BE08-1'], ['name' => 'FICTICIO BE08 cierre clínico', 'timezone' => 'America/Guatemala', 'currency' => 'GTQ', 'is_active' => true]);
                    setPermissionsTeamId($lab->id);
                    $actor = $this->member($lab, 'requester', RbacCatalog::PERMISSIONS);
                    $approver = $this->member($lab, 'approver', ['orders.clinical_exceptions.approve']);
                    app(ValidationAdministrationService::class)->setPolicy($lab, $actor, ['validation_policy' => 'permission_based']);
                    $plan = DemoDataset::owned(Plan::class, ['code' => 'DEMO']);
                    DemoDataset::create(Subscription::class, ['laboratory_id' => $lab->id, 'plan_id' => $plan->id, 'starts_at' => '2026-01-01 00:00:00'], ['status' => 'active', 'ends_at' => null]);
                    $branch = DemoDataset::create(Branch::class, ['laboratory_id' => $lab->id, 'code' => 'BE08'], ['name' => 'FICTICIO', 'status' => 'active', 'is_main' => true]);
                    $patient = DemoDataset::create(Patient::class, ['laboratory_id' => $lab->id, 'affiliation_number' => 'BE08'], ['first_names' => 'FICTICIO', 'last_names' => 'DEMO', 'status' => 'active']);
                    $area = DemoDataset::create(LaboratoryArea::class, ['laboratory_id' => $lab->id, 'code' => 'BE08'], ['name' => 'FICTICIO', 'status' => 'active']);
                    $exam = DemoDataset::create(LaboratoryExam::class, ['laboratory_id' => $lab->id, 'code' => 'DEMO-FINALIZATION'], factory: function () use ($lab, $actor, $area) {
                        $clinical = app(ClinicalExamService::class);
                        $draft = $clinical->create($lab, $actor, ['code' => 'DEMO-FINALIZATION', 'name' => 'FICTICIO cierre BE08', 'laboratory_area_id' => $area->id, 'sample_type_id' => null, 'configuration' => ['description' => DemoDataset::DISCLAIMER, 'sample_mode' => 'none', 'parameters' => [['code' => 'DEMO', 'name' => 'FICTICIO texto', 'type' => 'text']]]]);
                        $clinical->publish($lab, $actor, $draft->laboratory_exam_id, $draft->id, 1);
                        $exam = LaboratoryExam::findOrFail($draft->laboratory_exam_id);
                        $exam->update(['status' => 'active']);

                        return $exam;
                    });
                    $prices = DemoDataset::create(PriceList::class, ['laboratory_id' => $lab->id, 'name' => 'DEMO BE08'], ['currency' => 'GTQ', 'status' => 'active', 'is_default' => false]);
                    DemoDataset::create(PriceListExam::class, ['laboratory_id' => $lab->id, 'price_list_id' => $prices->id, 'laboratory_exam_id' => $exam->id], ['price' => '25.00', 'status' => 'active']);
                    $resolution = app(ClinicalResolutionService::class);
                    foreach (['validated_open', 'capture_pending', 'exception_pending', 'exception_approved', 'exception_rejected', 'closed_validated', 'closed_mixed', 'closed_exceptions'] as $scenario) {
                        $order = app(CreateLaboratoryOrder::class)->execute($lab, $actor, ['branch_id' => $branch->id, 'patient_id' => $patient->id, 'doctor_id' => null, 'commercial_client_id' => null, 'price_list_id' => $prices->id, 'ordered_at' => now()->format('Y-m-d H:i:s'), 'notes' => DemoDataset::DISCLAIMER.' '.$scenario, 'exams' => array_fill(0, str_starts_with($scenario, 'closed_') ? 2 : 1, ['laboratory_exam_id' => $exam->id])]);
                        DemoDataset::remember('finalization_order', 'be08-'.$scenario, fn ($id) => LaboratoryOrder::forLaboratory($lab)->whereKey($id)->first(), fn () => $order);
                        foreach ($order->orderExams()->orderBy('id')->get() as $position => $line) {
                            $exception = str_starts_with($scenario, 'exception_') || $scenario === 'closed_exceptions' || ($scenario === 'closed_mixed' && $position === 1);
                            if ($exception) {
                                DemoClinicalReason::medicalEvidence($lab, $actor, $line);
                                $request = $resolution->requestException($lab, $actor, $order->id, $line->id, ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 0, 'reason_reference' => DemoClinicalReason::reference($lab, $actor), 'justification' => DemoDataset::DISCLAIMER.' Imposibilidad ficticia de completar el examen.']);
                                if ($scenario !== 'exception_pending') {
                                    $resolution->decideException($lab, $approver, $order->id, $line->id, $request['exception']['id'], $scenario !== 'exception_rejected', ['clinical_revision' => $request['clinical_revision'], 'request_revision' => 1, 'reason' => DemoDataset::DISCLAIMER]);
                                }
                            } else {
                                app(LaboratorySampleService::class)->start($lab, $actor, $order->id, $line->id);
                                if ($scenario === 'capture_pending') {
                                    continue;
                                }
                                app(LaboratoryResultService::class)->save($lab, $actor, $order->id, $line->id, ['revision' => 0, 'results' => [['parameter_code' => 'DEMO', 'value' => DemoDataset::DISCLAIMER]]]);
                                app(LaboratoryValidationService::class)->validate($lab, $actor, $order->id, $line->id, ['revision' => 1, 'reference_context' => ['reference_date' => now()->format('Y-m-d')]]);
                                $resolution->finalizeLine($lab, $actor, $order->id, $line->id, ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 2]);
                            }
                        }
                        if (str_starts_with($scenario, 'closed_')) {
                            $resolution->finalizeOrder($lab, $actor, $order->id, ['clinical_revision' => $order->fresh()->clinical_revision]);
                        }
                    }

                    return $lab;
                });
            });
        } finally {
            setPermissionsTeamId($team);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function member(Laboratory $lab, string $key, array $permissions): User
    {
        $user = DemoDataset::create(User::class, ['email' => 'be08-'.$key.'@donqerlab.test'], ['name' => 'FICTICIO BE08 '.$key, 'password' => 'demo-be08-password', 'is_active' => true]);
        DemoDataset::create(LaboratoryUser::class, ['laboratory_id' => $lab->id, 'user_id' => $user->id], ['is_active' => true, 'is_default' => true]);
        DemoDataset::remember('be08_permission_setup', $key, fn ($id) => User::whereKey($id)->first(), function () use ($user, $permissions) {
            foreach ($permissions as $name) {
                $user->givePermissionTo(Permission::findOrCreate($name, RbacCatalog::GUARD));
            }

            return $user;
        });

        return $user;
    }
}

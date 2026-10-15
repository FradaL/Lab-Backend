<?php

namespace Database\Seeders;

use App\Actions\LaboratoryOrders\CreateLaboratoryOrder;
use App\Authorization\RbacCatalog;
use App\ClinicalExams\ClinicalExamService;
use App\ClinicalExams\LaboratorySampleService;
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

final class LaboratoryValidationSeeder extends Seeder
{
    public function run(): void
    {
        $team = getPermissionsTeamId();
        try {
            DemoDataset::run(function () {
                foreach (['professional_required', 'permission_based'] as $index => $policy) {
                    DemoDataset::remember('validation_scenario', 'be07-'.$policy, fn ($id) => Laboratory::whereKey($id)->first(), function () use ($index, $policy) {
                        $lab = DemoDataset::create(Laboratory::class, ['nit' => 'DEMO-BE07-'.($index + 1)], ['name' => 'FICTICIO BE07 '.$policy, 'timezone' => 'America/Guatemala', 'currency' => 'GTQ', 'is_active' => true]);
                        setPermissionsTeamId($lab->id);
                        $admin = $this->member($lab, 'admin-'.$index, RbacCatalog::PERMISSIONS);
                        $validator = $this->member($lab, 'validator-'.$index, ['results.validate', 'results.rectify', 'results.capture', 'results.edit', 'results.view', 'results.view_history', 'results.validation_history', 'orders.start_exam', 'orders.create', 'orders.add_exam']);
                        $management = app(ValidationAdministrationService::class);
                        if ($policy === 'permission_based') {
                            $management->setPolicy($lab, $admin, ['validation_policy' => $policy]);
                        } else {
                            $professional = $management->register($lab, $admin, ['user_id' => $validator->id, 'professional_name' => 'FICTICIO autorizado', 'profession' => 'DEMO', 'registration_number' => 'DEMO-NO-REAL']);
                            $management->professionalState($lab, $admin, $professional->id, true);
                            foreach (['registered', 'revoked'] as $state) {
                                $member = $this->member($lab, $state, ['results.validate']);
                                $p = $management->register($lab, $admin, ['user_id' => $member->id, 'professional_name' => 'FICTICIO '.$state, 'profession' => 'DEMO']);
                                if ($state === 'revoked') {
                                    $management->professionalState($lab, $admin, $p->id, true);
                                    $management->professionalState($lab, $admin, $p->id, false, ['reason' => DemoDataset::DISCLAIMER]);
                                }
                            }
                        }
                        $plan = DemoDataset::owned(Plan::class, ['code' => 'DEMO']);
                        DemoDataset::create(Subscription::class, ['laboratory_id' => $lab->id, 'plan_id' => $plan->id, 'starts_at' => '2026-01-01 00:00:00'], ['status' => 'active', 'ends_at' => null]);
                        $branch = DemoDataset::create(Branch::class, ['laboratory_id' => $lab->id, 'code' => 'BE07'], ['name' => 'FICTICIO', 'status' => 'active', 'is_main' => true]);
                        $patient = DemoDataset::create(Patient::class, ['laboratory_id' => $lab->id, 'affiliation_number' => 'BE07'], ['first_names' => 'FICTICIO', 'last_names' => 'DEMO', 'birth_date' => '2000-01-01', 'status' => 'active']);
                        $area = DemoDataset::create(LaboratoryArea::class, ['laboratory_id' => $lab->id, 'code' => 'BE07'], ['name' => 'FICTICIO', 'status' => 'active']);
                        $exam = DemoDataset::create(LaboratoryExam::class, ['laboratory_id' => $lab->id, 'code' => 'DEMO-VALIDATION'], factory: function () use ($lab, $admin, $area) {
                            $clinical = app(ClinicalExamService::class);
                            $draft = $clinical->create($lab, $admin, ['code' => 'DEMO-VALIDATION', 'name' => 'FICTICIO validación BE07', 'laboratory_area_id' => $area->id, 'sample_type_id' => null,
                                'configuration' => ['sample_mode' => 'none', 'description' => DemoDataset::DISCLAIMER, 'parameters' => [
                                    ['code' => 'NUM', 'name' => 'FICTICIO número', 'type' => 'numeric', 'unit_required' => false, 'references' => [['key' => 'DEMO', 'priority' => 1, 'min' => '0', 'sex' => 'DEMO-S']]],
                                    ['code' => 'TXT', 'name' => 'FICTICIO texto', 'type' => 'text'],
                                    ['code' => 'QUAL', 'name' => 'FICTICIO cualitativo', 'type' => 'qualitative', 'options' => [['code' => 'DEMO', 'label' => 'FICTICIO']]],
                                    ['code' => 'OPT', 'name' => 'FICTICIO opción', 'type' => 'predefined_options', 'options' => [['code' => 'DEMO', 'label' => 'FICTICIO']]],
                                ]]]);
                            $clinical->publish($lab, $admin, $draft->laboratory_exam_id, $draft->id, 1);
                            $e = LaboratoryExam::findOrFail($draft->laboratory_exam_id);
                            $e->update(['status' => 'active']);

                            return $e;
                        });
                        $prices = DemoDataset::create(PriceList::class, ['laboratory_id' => $lab->id, 'name' => 'DEMO BE07'], ['currency' => 'GTQ', 'status' => 'active', 'is_default' => false]);
                        DemoDataset::create(PriceListExam::class, ['laboratory_id' => $lab->id, 'price_list_id' => $prices->id, 'laboratory_exam_id' => $exam->id], ['price' => '25.00', 'status' => 'active']);
                        $order = app(CreateLaboratoryOrder::class)->execute($lab, $validator, ['branch_id' => $branch->id, 'patient_id' => $patient->id, 'doctor_id' => null, 'commercial_client_id' => null, 'price_list_id' => $prices->id, 'ordered_at' => now()->format('Y-m-d H:i:s'), 'notes' => DemoDataset::DISCLAIMER, 'exams' => array_fill(0, $index === 0 ? 4 : 1, ['laboratory_exam_id' => $exam->id])]);
                        DemoDataset::remember('validation_order', 'be07-'.$policy, fn ($id) => LaboratoryOrder::forLaboratory($lab)->whereKey($id)->first(), fn () => $order);
                        foreach ($order->orderExams()->orderBy('id')->get() as $position => $line) {
                            app(LaboratorySampleService::class)->start($lab, $validator, $order->id, $line->id);
                            app(LaboratoryResultService::class)->save($lab, $validator, $order->id, $line->id, ['revision' => 0, 'results' => [['parameter_code' => 'NUM', 'value' => '1.25'], ['parameter_code' => 'TXT', 'value' => DemoDataset::DISCLAIMER], ['parameter_code' => 'QUAL', 'value' => 'DEMO'], ['parameter_code' => 'OPT', 'value' => 'DEMO']]]);
                            if ($index === 0 && $position === 0) {
                                continue;
                            }
                            $validation = app(LaboratoryValidationService::class);
                            $context = ['reference_date' => now()->format('Y-m-d'), 'sex' => 'DEMO-S'];
                            $validation->validate($lab, $validator, $order->id, $line->id, ['revision' => 1, 'reference_context' => $context]);
                            if ($index === 0 && $position >= 2) {
                                $validation->rectify($lab, $validator, $order->id, $line->id, ['revision' => 2, 'reason' => DemoDataset::DISCLAIMER]);
                                app(LaboratoryResultService::class)->save($lab, $validator, $order->id, $line->id, ['revision' => 3, 'results' => [['parameter_code' => 'NUM', 'value' => '2.50']]]);
                                if ($position === 3) {
                                    $validation->validate($lab, $validator, $order->id, $line->id, ['revision' => 4, 'reference_context' => $context]);
                                }
                            }
                        }

                        return $lab;
                    });
                }
            });
        } finally {
            setPermissionsTeamId($team);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function member(Laboratory $lab, string $key, array $permissions): User
    {
        $user = DemoDataset::create(User::class, ['email' => 'be07-'.$key.'@donqerlab.test'], ['name' => 'FICTICIO BE07 '.$key, 'password' => 'demo-be07-password', 'is_active' => true]);
        DemoDataset::create(LaboratoryUser::class, ['laboratory_id' => $lab->id, 'user_id' => $user->id], ['is_active' => true, 'is_default' => true]);
        DemoDataset::remember('be07_permission_setup', $key, fn ($id) => User::whereKey($id)->first(), function () use ($user, $permissions) {
            foreach ($permissions as $name) {
                $user->givePermissionTo(Permission::findOrCreate($name, RbacCatalog::GUARD));
            }

            return $user;
        });

        return $user;
    }
}

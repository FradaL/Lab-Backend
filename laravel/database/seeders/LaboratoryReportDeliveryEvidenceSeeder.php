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
use App\ReportDelivery\ReportDeliveryPolicyService;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

final class LaboratoryReportDeliveryEvidenceSeeder extends Seeder
{
    public function run(): void
    {
        $team = getPermissionsTeamId();
        try {
            DemoDataset::run(function (): void {
                DemoDataset::remember('report_delivery_evidence_scenario', 'be092-v1', fn ($id) => Laboratory::find($id), function () {
                    $freeLab = DemoDataset::owned(Laboratory::class, ['nit' => 'DEMO-BE091-1']);
                    $freeActor = DemoDataset::owned(User::class, ['email' => 'be091-manager@donqerlab.test']);
                    setPermissionsTeamId($freeLab->id);
                    app(ReportDeliveryPolicyService::class)->update($freeLab, $freeActor, [
                        'revision' => 0, 'mode' => 'unrestricted', 'reason' => DemoDataset::DISCLAIMER,
                    ]);

                    $lab = DemoDataset::create(Laboratory::class, ['nit' => 'DEMO-BE092-1'], [
                        'name' => 'FICTICIO BE09.2 RESTRICTIVO', 'is_active' => true, 'timezone' => 'America/Guatemala', 'currency' => 'GTQ',
                    ]);
                    setPermissionsTeamId($lab->id);
                    $actor = DemoDataset::create(User::class, ['email' => 'be092-manager@donqerlab.test'], [
                        'name' => 'FICTICIO BE092 responsable', 'password' => 'demo-be092-password', 'is_active' => true,
                    ]);
                    DemoDataset::create(LaboratoryUser::class, ['laboratory_id' => $lab->id, 'user_id' => $actor->id], ['is_active' => true, 'is_default' => true]);
                    foreach (RbacCatalog::PERMISSIONS as $permission) {
                        $actor->givePermissionTo(Permission::findOrCreate($permission, RbacCatalog::GUARD));
                    }
                    $plan = DemoDataset::owned(Plan::class, ['code' => 'DEMO']);
                    DemoDataset::create(Subscription::class, ['laboratory_id' => $lab->id, 'plan_id' => $plan->id, 'starts_at' => '2026-01-01 00:00:00'], ['status' => 'active', 'ends_at' => null]);
                    $branch = DemoDataset::create(Branch::class, ['laboratory_id' => $lab->id, 'code' => 'BE092'], ['name' => 'FICTICIO sucursal BE092', 'status' => 'active', 'is_main' => true]);
                    $patient = DemoDataset::create(Patient::class, ['laboratory_id' => $lab->id, 'affiliation_number' => 'BE092'], ['first_names' => 'FICTICIO', 'last_names' => 'DESTINATARIO', 'status' => 'active']);
                    $area = DemoDataset::create(LaboratoryArea::class, ['laboratory_id' => $lab->id, 'code' => 'BE092'], ['name' => 'FICTICIO área BE092', 'status' => 'active']);
                    $exam = DemoDataset::create(LaboratoryExam::class, ['laboratory_id' => $lab->id, 'code' => 'BE092'], factory: function () use ($lab, $actor, $area) {
                        $clinical = app(ClinicalExamService::class);
                        $draft = $clinical->create($lab, $actor, ['code' => 'BE092', 'name' => 'FICTICIO examen BE092', 'laboratory_area_id' => $area->id, 'sample_type_id' => null,
                            'configuration' => ['description' => DemoDataset::DISCLAIMER, 'sample_mode' => 'none', 'parameters' => [['code' => 'TEXT', 'name' => 'FICTICIO parámetro', 'type' => 'text']]]]);
                        $clinical->publish($lab, $actor, $draft->laboratory_exam_id, $draft->id, 1);
                        $created = LaboratoryExam::findOrFail($draft->laboratory_exam_id);
                        $created->update(['status' => 'active']);

                        return $created;
                    });
                    $prices = DemoDataset::create(PriceList::class, ['laboratory_id' => $lab->id, 'name' => 'DEMO BE092'], ['currency' => 'GTQ', 'status' => 'active', 'is_default' => false]);
                    DemoDataset::create(PriceListExam::class, ['laboratory_id' => $lab->id, 'price_list_id' => $prices->id, 'laboratory_exam_id' => $exam->id], ['price' => '25.00', 'status' => 'active']);
                    app(ValidationAdministrationService::class)->setPolicy($lab, $actor, ['validation_policy' => 'permission_based']);
                    app(ReportSettingsService::class)->update($lab, $actor, ['revision' => 0, 'trade_name' => 'FICTICIO informes BE092', 'header' => DemoDataset::DISCLAIMER, 'footer' => 'FICTICIO documento de demostración']);
                    app(ReportDeliveryPolicyService::class)->update($lab, $actor, ['revision' => 0, 'mode' => 'payment_or_credit', 'reason' => DemoDataset::DISCLAIMER]);
                    $order = app(CreateLaboratoryOrder::class)->execute($lab, $actor, ['branch_id' => $branch->id, 'patient_id' => $patient->id, 'doctor_id' => null, 'commercial_client_id' => null,
                        'price_list_id' => $prices->id, 'ordered_at' => now()->format('Y-m-d H:i:s'), 'notes' => DemoDataset::DISCLAIMER, 'exams' => [['laboratory_exam_id' => $exam->id]]]);
                    DemoDataset::remember('report_delivery_demo_order', 'be092-restrictive', fn ($id) => LaboratoryOrder::forLaboratory($lab)->find($id), fn () => $order);
                    $line = $order->orderExams()->firstOrFail();
                    app(LaboratorySampleService::class)->start($lab, $actor, $order->id, $line->id);
                    app(LaboratoryResultService::class)->save($lab, $actor, $order->id, $line->id, ['revision' => 0, 'results' => [['parameter_code' => 'TEXT', 'value' => DemoDataset::DISCLAIMER]]]);
                    app(LaboratoryValidationService::class)->validate($lab, $actor, $order->id, $line->id, ['revision' => 1, 'reference_context' => ['reference_date' => now()->format('Y-m-d')], 'comment' => DemoDataset::DISCLAIMER]);
                    $resolution = app(ClinicalResolutionService::class);
                    $resolution->finalizeLine($lab, $actor, $order->id, $line->id, ['clinical_revision' => $order->fresh()->clinical_revision, 'result_revision' => 2]);
                    $resolution->finalizeOrder($lab, $actor, $order->id, ['clinical_revision' => $order->fresh()->clinical_revision]);

                    return $lab;
                });
            });
        } finally {
            setPermissionsTeamId($team);
        }
    }
}

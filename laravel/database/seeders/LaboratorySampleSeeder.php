<?php

namespace Database\Seeders;

use App\Actions\LaboratoryOrders\CreateLaboratoryOrder;
use App\ClinicalExams\ClinicalExamService;
use App\ClinicalExams\LaboratorySampleService;
use App\Models\Branch;
use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryOrder;
use App\Models\LaboratorySample;
use App\Models\Patient;
use App\Models\PriceList;
use App\Models\PriceListExam;
use App\Models\SampleType;
use App\Models\User;
use Illuminate\Database\Seeder;
use LogicException;
use Spatie\Permission\PermissionRegistrar;

/** Adds new registered scenarios; never adopts or rewrites existing orders/configurations. */
final class LaboratorySampleSeeder extends Seeder
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
                $area = DemoDataset::owned(LaboratoryArea::class, ['laboratory_id' => $lab->id, 'code' => 'HEM']);
                $blood = DemoDataset::owned(SampleType::class, ['laboratory_id' => $lab->id, 'name' => 'Sangre']);
                $urine = DemoDataset::owned(SampleType::class, ['laboratory_id' => $lab->id, 'name' => 'Orina']);
                $prices = DemoDataset::owned(PriceList::class, ['laboratory_id' => $lab->id, 'name' => 'Tarifa Particular']);
                $requirements = [
                    ['key' => 'main', 'sample_type_id' => $blood->id, 'container' => 'DEMO-A', 'additives' => ['DEMO-ADDITIVE'],
                        'volume' => 2, 'volume_unit' => 'DEMO-U', 'storage_conditions' => ['demo_handling' => 'demo-only'], 'instructions' => DemoDataset::DISCLAIMER],
                    ['key' => 'second', 'sample_type_id' => $urine->id, 'container' => 'DEMO-B', 'instructions' => DemoDataset::DISCLAIMER],
                ];
                $exams = [];
                foreach (['DEMO-SAMPLE-A' => [$requirements[0]], 'DEMO-SAMPLE-DUAL' => $requirements] as $code => $rules) {
                    $exams[$code] = DemoDataset::create(LaboratoryExam::class, ['laboratory_id' => $lab->id, 'code' => $code], factory: function () use ($lab, $actor, $area, $code, $rules) {
                        $clinical = app(ClinicalExamService::class);
                        $draft = $clinical->create($lab, $actor, ['code' => $code, 'name' => 'DEMO — '.$code, 'laboratory_area_id' => $area->id,
                            'sample_type_id' => null, 'configuration' => ['schema_version' => 2, 'sample_mode' => 'physical', 'description' => DemoDataset::DISCLAIMER,
                                'sample_requirements' => $rules, 'parameters' => [['code' => 'DEMO_OBS', 'name' => 'Observación ficticia', 'type' => 'text']]]]);
                        $clinical->publish($lab, $actor, $draft->laboratory_exam_id, $draft->id, 1);
                        $exam = LaboratoryExam::findOrFail($draft->laboratory_exam_id);
                        $exam->update(['status' => 'active']);

                        return $exam;
                    });
                    DemoDataset::create(PriceListExam::class, ['laboratory_id' => $lab->id, 'price_list_id' => $prices->id, 'laboratory_exam_id' => $exams[$code]->id], ['price' => '25.00', 'status' => 'active']);
                }
                DemoDataset::remember('physical_order_scenario', 'samples-v1', fn ($id) => LaboratoryOrder::forLaboratory($lab)->whereKey($id)->first(), function () use ($lab, $actor, $exams, $blood, $urine, $prices) {
                    foreach (['samples.collect', 'samples.receive', 'samples.evaluate', 'samples.associate', 'orders.start_exam', 'orders.add_exam'] as $permission) {
                        if (! $actor->can($permission)) {
                            throw new LogicException('The registered demo actor needs explicitly provisioned BE-S2-05 permissions; existing roles will not be overwritten.');
                        }
                    }
                    $branch = DemoDataset::owned(Branch::class, ['laboratory_id' => $lab->id, 'code' => 'MAIN']);
                    $patient = DemoDataset::owned(Patient::class, ['laboratory_id' => $lab->id, 'affiliation_number' => 'DEMO-0001']);
                    $none = DemoDataset::owned(LaboratoryExam::class, ['laboratory_id' => $lab->id, 'code' => 'DEMO-NONE']);
                    $order = app(CreateLaboratoryOrder::class)->execute($lab, $actor, [
                        'branch_id' => $branch->id, 'patient_id' => $patient->id, 'doctor_id' => null, 'commercial_client_id' => null,
                        'price_list_id' => $prices->id, 'ordered_at' => now()->format('Y-m-d H:i:s'), 'notes' => DemoDataset::DISCLAIMER.' Escenario operativo BE-S2-05.',
                        'exams' => array_map(fn ($exam) => ['laboratory_exam_id' => $exam->id], [$exams['DEMO-SAMPLE-A'], $exams['DEMO-SAMPLE-A'], $exams['DEMO-SAMPLE-DUAL'], $none]),
                    ]);
                    $service = app(LaboratorySampleService::class);
                    $base = ['sample_type_id' => $blood->id, 'collected_at' => now()->subMinutes(5)->toISOString(),
                        'facts' => ['container' => 'DEMO-A', 'additives' => ['DEMO-ADDITIVE'], 'volume' => 2, 'volume_unit' => 'DEMO-U', 'storage_conditions' => ['demo_handling' => 'demo-only']],
                        'context' => ['notes' => DemoDataset::DISCLAIMER]];
                    $accepted = $this->registeredSample($lab, 'accepted-shared', fn () => $service->register($lab, $actor, $order->id, 'collect',
                        $base + ['idempotency_key' => 'demo05-accepted', 'collection_branch_id' => $branch->id]));
                    $service->receive($lab, $actor, $order->id, $accepted->id, ['received_at' => now()->subMinute()->toISOString(), 'receiving_branch_id' => $branch->id]);
                    $requirements = $order->orderExams()->with('sampleRequirements')->orderBy('id')->get()->flatMap->sampleRequirements->where('requirement_key', 'main')->pluck('id')->all();
                    $service->associate($lab, $actor, $order->id, $accepted->id, ['sample_requirement_ids' => $requirements]);
                    $service->evaluate($lab, $actor, $order->id, $accepted->id, 'accepted');
                    $this->registeredSample($lab, 'collected', fn () => $service->register($lab, $actor, $order->id, 'collect',
                        $base + ['idempotency_key' => 'demo05-collected', 'collection_branch_id' => $branch->id]));
                    $this->registeredSample($lab, 'received-external', fn () => $service->register($lab, $actor, $order->id, 'receiveNew', [
                        'idempotency_key' => 'demo05-received', 'sample_type_id' => $urine->id, 'provenance' => 'patient_provided',
                        'received_at' => now()->subMinute()->toISOString(), 'receiving_branch_id' => $branch->id, 'facts' => ['container' => 'DEMO-B'], 'context' => ['notes' => DemoDataset::DISCLAIMER]]));
                    $rejected = $this->registeredSample($lab, 'rejected', fn () => $service->register($lab, $actor, $order->id, 'receiveNew',
                        $base + ['idempotency_key' => 'demo05-rejected', 'provenance' => 'external_center', 'received_at' => now()->subMinute()->toISOString(), 'receiving_branch_id' => $branch->id]));
                    $service->evaluate($lab, $actor, $order->id, $rejected->id, 'rejected', ['reason' => DemoDataset::DISCLAIMER.' Rechazo demostrativo.']);
                    $service->start($lab, $actor, $order->id, $order->orderExams()->orderBy('id')->firstOrFail()->id);

                    return $order->refresh();
                });
            });
        } finally {
            setPermissionsTeamId($previousTeam);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function registeredSample(Laboratory $lab, string $key, \Closure $create): LaboratorySample
    {
        return DemoDataset::remember('physical_sample_scenario', $key,
            fn ($id) => LaboratorySample::forLaboratory($lab)->whereKey($id)->first(),
            fn () => LaboratorySample::forLaboratory($lab)->findOrFail($create()['data']['id']));
    }
}

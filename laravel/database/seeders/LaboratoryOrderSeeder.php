<?php

namespace Database\Seeders;

use App\Actions\LaboratoryOrders\CreateLaboratoryOrder;
use App\Actions\LaboratoryOrders\SetLaboratoryOrderDiscount;
use App\Models\Branch;
use App\Models\CommercialClient;
use App\Models\CommercialClientPriceList;
use App\Models\Doctor;
use App\Models\Laboratory;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryOrder;
use App\Models\Patient;
use App\Models\PriceList;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use LogicException;
use Spatie\Permission\PermissionRegistrar;

class LaboratoryOrderSeeder extends Seeder
{
    public const DEMO_ORDER_COUNT = 18;

    public function run(): void
    {
        $previousTeam = getPermissionsTeamId();
        try {
            DemoDataset::run(function (): void {
                $lab = DemoDataset::owned(Laboratory::class, ['nit' => '000000000001']);
                $actor = DemoDataset::owned(User::class, ['email' => 'reception@donqerlab.test']);
                setPermissionsTeamId($lab->id);
                $actor->unsetRelation('roles')->unsetRelation('permissions');
                foreach ($this->orders() as $index => $definition) {
                    DemoDataset::remember('order_scenario', sprintf('order-%02d', $index + 1),
                        fn ($id) => LaboratoryOrder::forLaboratory($lab)->whereKey($id)->first(),
                        function () use ($lab, $actor, $definition) {
                            $branch = DemoDataset::owned(Branch::class, ['laboratory_id' => $lab->id, 'code' => $definition['branch']]);
                            $patient = DemoDataset::owned(Patient::class, ['laboratory_id' => $lab->id, 'affiliation_number' => $definition['patient']]);
                            $doctor = $definition['doctor'] === null ? null : DemoDataset::owned(Doctor::class, ['laboratory_id' => $lab->id, 'license_number' => $definition['doctor']]);
                            $client = $definition['commercial_client'] === null ? null : DemoDataset::owned(CommercialClient::class, ['laboratory_id' => $lab->id, 'name' => $definition['commercial_client']]);
                            $prices = DemoDataset::owned(PriceList::class, ['laboratory_id' => $lab->id, 'name' => $definition['price_list']]);
                            if ($client !== null) {
                                $this->assertEffectiveAssignment($lab->id, $client, $prices->id, CarbonImmutable::parse($definition['ordered_at']));
                            }
                            $exams = array_map(fn ($code) => ['laboratory_exam_id' => DemoDataset::owned(LaboratoryExam::class, ['laboratory_id' => $lab->id, 'code' => $code])->id], $definition['exams']);
                            $order = app(CreateLaboratoryOrder::class)->execute($lab, $actor, [
                                'branch_id' => $branch->id, 'patient_id' => $patient->id, 'doctor_id' => $doctor?->id,
                                'commercial_client_id' => $client?->id, 'price_list_id' => $prices->id,
                                'ordered_at' => $definition['ordered_at'], 'notes' => DemoDataset::DISCLAIMER.' '.$definition['notes'], 'exams' => $exams,
                            ]);
                            if ($definition['discount_type'] !== null) {
                                app(SetLaboratoryOrderDiscount::class)->execute($lab, $order->id, $definition['discount_type'], $definition['discount_value'], $actor);
                            }

                            return $order;
                        });
                }
            });
        } finally {
            setPermissionsTeamId($previousTeam);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function assertEffectiveAssignment(
        int $laboratoryId,
        CommercialClient $commercialClient,
        int $priceListId,
        CarbonImmutable $orderedAt,
    ): void {
        $isEffective = CommercialClientPriceList::query()
            ->where('laboratory_id', $laboratoryId)
            ->where('commercial_client_id', $commercialClient->id)
            ->where('price_list_id', $priceListId)
            ->effectiveOn($orderedAt)
            ->exists();

        if (! $isEffective) {
            throw new LogicException(
                "No effective demo price-list assignment exists for [{$commercialClient->name}] on [{$orderedAt->toDateString()}].",
            );
        }
    }

    /**
     * @return list<array{
     *     branch: string,
     *     patient: string,
     *     doctor: ?string,
     *     commercial_client: ?string,
     *     price_list: string,
     *     exams: list<string>,
     *     discount_type: ?string,
     *     discount_value: ?string,
     *     status: string,
     *     ordered_at: string,
     *     notes: string
     * }>
     */
    private function orders(): array
    {
        return [
            ['branch' => 'MAIN', 'patient' => 'DEMO-0001', 'doctor' => 'DEMO-MED-0001', 'commercial_client' => null, 'price_list' => 'Tarifa Particular', 'exams' => ['HEM001', 'QUI001'], 'discount_type' => null, 'discount_value' => null, 'status' => LaboratoryOrder::STATUS_PENDING, 'ordered_at' => '2026-09-30 08:15:00', 'notes' => 'Control general.'],
            ['branch' => 'ZONE10', 'patient' => 'DEMO-0002', 'doctor' => null, 'commercial_client' => null, 'price_list' => 'Tarifa Particular', 'exams' => ['QUI001', 'QUI002', 'QUI003'], 'discount_type' => LaboratoryOrder::DISCOUNT_TYPE_PERCENTAGE, 'discount_value' => '10.00', 'status' => LaboratoryOrder::STATUS_PENDING, 'ordered_at' => '2026-09-27 09:30:00', 'notes' => 'Perfil metabólico.'],
            ['branch' => 'MAIN', 'patient' => 'DEMO-0003', 'doctor' => 'DEMO-MED-0002', 'commercial_client' => 'Seguros Vida Plena', 'price_list' => 'Tarifa Aseguradoras', 'exams' => ['HEM001', 'BIO001'], 'discount_type' => null, 'discount_value' => null, 'status' => LaboratoryOrder::STATUS_PENDING, 'ordered_at' => '2026-09-24 10:45:00', 'notes' => 'Orden de aseguradora.'],
            ['branch' => 'ZONE10', 'patient' => 'DEMO-0004', 'doctor' => null, 'commercial_client' => 'Corporación Atlas', 'price_list' => 'Tarifa Convenios', 'exams' => ['QUI001', 'QUI002', 'QUI003', 'URO001'], 'discount_type' => LaboratoryOrder::DISCOUNT_TYPE_AMOUNT, 'discount_value' => '25.00', 'status' => LaboratoryOrder::STATUS_PENDING, 'ordered_at' => '2026-09-20 07:50:00', 'notes' => 'Chequeo ocupacional.'],
            ['branch' => 'MAIN', 'patient' => 'DEMO-0005', 'doctor' => 'DEMO-MED-0003', 'commercial_client' => null, 'price_list' => 'Tarifa Particular', 'exams' => ['URO001', 'COP001'], 'discount_type' => LaboratoryOrder::DISCOUNT_TYPE_AMOUNT, 'discount_value' => '200.00', 'status' => LaboratoryOrder::STATUS_PENDING, 'ordered_at' => '2026-09-16 11:20:00', 'notes' => 'Descuento limitado al subtotal.'],
            ['branch' => 'ZONE10', 'patient' => 'DEMO-0006', 'doctor' => 'DEMO-MED-0004', 'commercial_client' => 'Convenio Salud Integral', 'price_list' => 'Tarifa Convenios', 'exams' => ['HEM001', 'COA001', 'INM001'], 'discount_type' => LaboratoryOrder::DISCOUNT_TYPE_PERCENTAGE, 'discount_value' => '7.50', 'status' => LaboratoryOrder::STATUS_PENDING, 'ordered_at' => '2026-09-12 13:10:00', 'notes' => 'Seguimiento por convenio.'],
            ['branch' => 'MAIN', 'patient' => 'DEMO-0007', 'doctor' => null, 'commercial_client' => null, 'price_list' => 'Tarifa Particular', 'exams' => ['QUI001', 'QUI001', 'HEM001'], 'discount_type' => null, 'discount_value' => null, 'status' => LaboratoryOrder::STATUS_PENDING, 'ordered_at' => '2026-09-08 08:05:00', 'notes' => 'Incluye dos líneas independientes de glucosa.'],
            ['branch' => 'ZONE10', 'patient' => 'DEMO-0008', 'doctor' => null, 'commercial_client' => 'Seguros Vida Plena', 'price_list' => 'Tarifa Aseguradoras', 'exams' => ['BIO001', 'INM001'], 'discount_type' => LaboratoryOrder::DISCOUNT_TYPE_AMOUNT, 'discount_value' => '50.00', 'status' => LaboratoryOrder::STATUS_PENDING, 'ordered_at' => '2026-09-03 15:40:00', 'notes' => 'Solicitud ficticia de cancelación; permanece pendiente.'],
            ['branch' => 'MAIN', 'patient' => 'DEMO-0009', 'doctor' => 'DEMO-MED-0005', 'commercial_client' => null, 'price_list' => 'Tarifa Particular', 'exams' => ['QUI002', 'QUI003'], 'discount_type' => LaboratoryOrder::DISCOUNT_TYPE_PERCENTAGE, 'discount_value' => '12.50', 'status' => LaboratoryOrder::STATUS_PENDING, 'ordered_at' => '2026-08-29 09:15:00', 'notes' => 'Control de lípidos.'],
            ['branch' => 'ZONE10', 'patient' => 'DEMO-0010', 'doctor' => 'DEMO-MED-0006', 'commercial_client' => 'Corporación Atlas', 'price_list' => 'Tarifa Convenios', 'exams' => ['HEM001', 'QUI001', 'URO001'], 'discount_type' => null, 'discount_value' => null, 'status' => LaboratoryOrder::STATUS_PENDING, 'ordered_at' => '2026-08-24 14:25:00', 'notes' => 'Evaluación de ingreso.'],
            ['branch' => 'MAIN', 'patient' => 'DEMO-0011', 'doctor' => null, 'commercial_client' => null, 'price_list' => 'Tarifa Particular', 'exams' => ['MIC001'], 'discount_type' => null, 'discount_value' => null, 'status' => LaboratoryOrder::STATUS_PENDING, 'ordered_at' => '2026-08-20 10:00:00', 'notes' => 'Requisito ficticio de cultivo; pendiente de muestra.'],
            ['branch' => 'ZONE10', 'patient' => 'DEMO-0012', 'doctor' => null, 'commercial_client' => 'Convenio Salud Integral', 'price_list' => 'Tarifa Convenios', 'exams' => ['COP001', 'URO001', 'QUI001'], 'discount_type' => LaboratoryOrder::DISCOUNT_TYPE_AMOUNT, 'discount_value' => '30.00', 'status' => LaboratoryOrder::STATUS_PENDING, 'ordered_at' => '2026-08-16 07:35:00', 'notes' => 'Panel básico de convenio.'],
            ['branch' => 'MAIN', 'patient' => 'DEMO-0013', 'doctor' => 'DEMO-MED-0007', 'commercial_client' => null, 'price_list' => 'Tarifa Particular', 'exams' => ['COA001', 'HEM001'], 'discount_type' => LaboratoryOrder::DISCOUNT_TYPE_PERCENTAGE, 'discount_value' => '5.00', 'status' => LaboratoryOrder::STATUS_PENDING, 'ordered_at' => '2026-08-12 16:10:00', 'notes' => 'Escenario ficticio anterior a recepción; permanece pendiente.'],
            ['branch' => 'ZONE10', 'patient' => 'DEMO-0014', 'doctor' => 'DEMO-MED-0008', 'commercial_client' => 'Seguros Vida Plena', 'price_list' => 'Tarifa Aseguradoras', 'exams' => ['BIO001', 'MIC001'], 'discount_type' => null, 'discount_value' => null, 'status' => LaboratoryOrder::STATUS_PENDING, 'ordered_at' => '2026-08-09 12:45:00', 'notes' => 'Estudios microbiológicos.'],
            ['branch' => 'MAIN', 'patient' => 'DEMO-0015', 'doctor' => null, 'commercial_client' => null, 'price_list' => 'Tarifa Particular', 'exams' => ['INM001', 'QUI001'], 'discount_type' => LaboratoryOrder::DISCOUNT_TYPE_AMOUNT, 'discount_value' => '20.00', 'status' => LaboratoryOrder::STATUS_PENDING, 'ordered_at' => '2026-08-06 08:55:00', 'notes' => 'Control inflamatorio.'],
            ['branch' => 'ZONE10', 'patient' => 'DEMO-0016', 'doctor' => null, 'commercial_client' => 'Corporación Atlas', 'price_list' => 'Tarifa Convenios', 'exams' => ['QUI002', 'HEM001', 'QUI003'], 'discount_type' => LaboratoryOrder::DISCOUNT_TYPE_PERCENTAGE, 'discount_value' => '15.00', 'status' => LaboratoryOrder::STATUS_PENDING, 'ordered_at' => '2026-08-04 10:30:00', 'notes' => 'Seguimiento ocupacional.'],
            ['branch' => 'MAIN', 'patient' => 'DEMO-0017', 'doctor' => 'DEMO-MED-0009', 'commercial_client' => null, 'price_list' => 'Tarifa Particular', 'exams' => ['HEM001', 'URO001', 'COP001', 'MIC001'], 'discount_type' => null, 'discount_value' => null, 'status' => LaboratoryOrder::STATUS_PENDING, 'ordered_at' => '2026-08-03 09:40:00', 'notes' => 'Panel clínico ampliado.'],
            ['branch' => 'ZONE10', 'patient' => 'DEMO-0018', 'doctor' => null, 'commercial_client' => 'Convenio Salud Integral', 'price_list' => 'Tarifa Convenios', 'exams' => ['DEMO-NONE'], 'discount_type' => LaboratoryOrder::DISCOUNT_TYPE_PERCENTAGE, 'discount_value' => '20.00', 'status' => LaboratoryOrder::STATUS_PENDING, 'ordered_at' => '2026-08-02 11:05:00', 'notes' => 'Observación ficticia sin muestra; orden comercial pendiente.'],
        ];
    }
}

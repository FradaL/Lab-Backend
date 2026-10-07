<?php

namespace Database\Seeders;

use App\Models\CommercialClient;
use App\Models\CommercialClientPriceList;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Models\LaboratoryUser;
use App\Models\PriceListExam;
use App\Models\User;
use App\Services\LaboratoryOrders\LaboratoryOrderEconomicCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class LaboratoryOrderSeeder extends Seeder
{
    public const DEMO_CODE_PREFIX = 'ORD-01K5A';

    public const DEMO_ORDER_COUNT = 18;

    /**
     * Seed deterministic final-state orders without simulating HTTP actions or audit history.
     */
    public function run(): void
    {
        $laboratory = Laboratory::query()
            ->where('nit', '000000000001')
            ->firstOrFail();
        $creator = User::query()
            ->where('email', 'reception@donqerlab.test')
            ->firstOrFail();

        if (! LaboratoryUser::query()
            ->where('laboratory_id', $laboratory->id)
            ->where('user_id', $creator->id)
            ->where('is_active', true)
            ->exists()) {
            throw new LogicException('The demo reception user needs an active laboratory membership.');
        }

        $branches = $laboratory->branches()
            ->whereIn('code', ['MAIN', 'ZONE10'])
            ->get()
            ->keyBy('code');
        $patients = $laboratory->patients()
            ->whereIn('affiliation_number', $this->patientReferences())
            ->get()
            ->keyBy('affiliation_number');
        $doctors = $laboratory->doctors()
            ->whereIn('license_number', $this->doctorReferences())
            ->get()
            ->keyBy('license_number');
        $commercialClients = $laboratory->commercialClients()
            ->whereIn('name', $this->commercialClientReferences())
            ->get()
            ->keyBy('name');
        $priceLists = $laboratory->priceLists()
            ->whereIn('name', $this->priceListReferences())
            ->get()
            ->keyBy('name');
        $prices = PriceListExam::query()
            ->where('laboratory_id', $laboratory->id)
            ->where('status', PriceListExam::STATUS_ACTIVE)
            ->whereIn('price_list_id', $priceLists->pluck('id'))
            ->with(['laboratoryExam:id,code,name', 'priceList:id,name,currency'])
            ->get()
            ->keyBy(fn (PriceListExam $price): string => $price->priceList->name.'|'.$price->laboratoryExam->code);

        $calculator = new LaboratoryOrderEconomicCalculator;

        DB::transaction(function () use (
            $branches,
            $calculator,
            $commercialClients,
            $creator,
            $doctors,
            $laboratory,
            $patients,
            $priceLists,
            $prices,
        ): void {
            foreach ($this->orders() as $index => $definition) {
                $branch = $this->related($branches, $definition['branch'], 'branch');
                $patient = $this->related($patients, $definition['patient'], 'patient');
                $doctor = $definition['doctor'] === null
                    ? null
                    : $this->related($doctors, $definition['doctor'], 'doctor');
                $commercialClient = $definition['commercial_client'] === null
                    ? null
                    : $this->related($commercialClients, $definition['commercial_client'], 'commercial client');
                $priceList = $this->related($priceLists, $definition['price_list'], 'price list');
                $orderedAt = CarbonImmutable::parse($definition['ordered_at'], 'America/Guatemala');

                if ($commercialClient instanceof CommercialClient) {
                    $this->assertEffectiveAssignment(
                        $laboratory->id,
                        $commercialClient,
                        $priceList->id,
                        $orderedAt,
                    );
                }

                $order = LaboratoryOrder::query()->updateOrCreate(
                    [
                        'laboratory_id' => $laboratory->id,
                        'code' => $this->code($index + 1),
                    ],
                    [
                        'branch_id' => $branch->id,
                        'patient_id' => $patient->id,
                        'doctor_id' => $doctor?->id,
                        'commercial_client_id' => $commercialClient?->id,
                        'commercial_client_name' => $commercialClient?->name,
                        'commercial_client_type' => $commercialClient?->type,
                        'price_list_id' => $priceList->id,
                        'price_list_name' => $priceList->name,
                        'ordered_at' => $orderedAt->utc(),
                        'status' => $definition['status'],
                        'notes' => $definition['notes'],
                        'subtotal' => '0.00',
                        'discount' => '0.00',
                        'discount_type' => $definition['discount_type'],
                        'discount_value' => $definition['discount_value'],
                        'taxes' => '0.00',
                        'total' => '0.00',
                        'currency' => $priceList->currency,
                        'created_by' => $creator->id,
                    ],
                );

                $order->orderExams()->delete();

                foreach ($definition['exams'] as $examCode) {
                    $price = $this->related(
                        $prices,
                        $priceList->name.'|'.$examCode,
                        'configured examination price',
                    );

                    LaboratoryOrderExam::query()->create([
                        'laboratory_id' => $laboratory->id,
                        'laboratory_order_id' => $order->id,
                        'laboratory_exam_id' => $price->laboratory_exam_id,
                        'price_list_id' => $priceList->id,
                        'unit_price' => $price->price,
                        'exam_code' => $price->laboratoryExam->code,
                        'exam_name' => $price->laboratoryExam->name,
                        'price_list_name' => $priceList->name,
                    ]);
                }

                $totals = $calculator->calculate($order, $order->orderExams()->get());

                $order->update([
                    'subtotal' => $totals->subtotal,
                    'discount' => $totals->discount,
                    'taxes' => $totals->taxes,
                    'total' => $totals->total,
                ]);
            }
        });
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
     * @template TKey of array-key
     * @template TValue
     *
     * @param  Collection<TKey, TValue>  $models
     * @return TValue
     */
    private function related(Collection $models, int|string $key, string $label): mixed
    {
        return $models->get($key)
            ?? throw new LogicException("Missing demo {$label} [{$key}].");
    }

    private function code(int $number): string
    {
        return self::DEMO_CODE_PREFIX.sprintf('%021d', $number);
    }

    /** @return list<string> */
    private function patientReferences(): array
    {
        return array_map(
            fn (int $number): string => sprintf('DEMO-%04d', $number),
            range(1, self::DEMO_ORDER_COUNT),
        );
    }

    /** @return list<string> */
    private function doctorReferences(): array
    {
        return array_map(
            fn (int $number): string => sprintf('DEMO-MED-%04d', $number),
            range(1, 9),
        );
    }

    /** @return list<string> */
    private function commercialClientReferences(): array
    {
        return ['Seguros Vida Plena', 'Corporación Atlas', 'Convenio Salud Integral'];
    }

    /** @return list<string> */
    private function priceListReferences(): array
    {
        return ['Tarifa Particular', 'Tarifa Convenios', 'Tarifa Aseguradoras'];
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
            ['branch' => 'MAIN', 'patient' => 'DEMO-0003', 'doctor' => 'DEMO-MED-0002', 'commercial_client' => 'Seguros Vida Plena', 'price_list' => 'Tarifa Aseguradoras', 'exams' => ['HEM001', 'BIO001'], 'discount_type' => null, 'discount_value' => null, 'status' => LaboratoryOrder::STATUS_IN_PROCESS, 'ordered_at' => '2026-09-24 10:45:00', 'notes' => 'Orden de aseguradora.'],
            ['branch' => 'ZONE10', 'patient' => 'DEMO-0004', 'doctor' => null, 'commercial_client' => 'Corporación Atlas', 'price_list' => 'Tarifa Convenios', 'exams' => ['QUI001', 'QUI002', 'QUI003', 'URO001'], 'discount_type' => LaboratoryOrder::DISCOUNT_TYPE_AMOUNT, 'discount_value' => '25.00', 'status' => LaboratoryOrder::STATUS_COMPLETED, 'ordered_at' => '2026-09-20 07:50:00', 'notes' => 'Chequeo ocupacional.'],
            ['branch' => 'MAIN', 'patient' => 'DEMO-0005', 'doctor' => 'DEMO-MED-0003', 'commercial_client' => null, 'price_list' => 'Tarifa Particular', 'exams' => ['URO001', 'COP001'], 'discount_type' => LaboratoryOrder::DISCOUNT_TYPE_AMOUNT, 'discount_value' => '200.00', 'status' => LaboratoryOrder::STATUS_COMPLETED, 'ordered_at' => '2026-09-16 11:20:00', 'notes' => 'Descuento limitado al subtotal.'],
            ['branch' => 'ZONE10', 'patient' => 'DEMO-0006', 'doctor' => 'DEMO-MED-0004', 'commercial_client' => 'Convenio Salud Integral', 'price_list' => 'Tarifa Convenios', 'exams' => ['HEM001', 'COA001', 'INM001'], 'discount_type' => LaboratoryOrder::DISCOUNT_TYPE_PERCENTAGE, 'discount_value' => '7.50', 'status' => LaboratoryOrder::STATUS_IN_PROCESS, 'ordered_at' => '2026-09-12 13:10:00', 'notes' => 'Seguimiento por convenio.'],
            ['branch' => 'MAIN', 'patient' => 'DEMO-0007', 'doctor' => null, 'commercial_client' => null, 'price_list' => 'Tarifa Particular', 'exams' => ['QUI001', 'QUI001', 'HEM001'], 'discount_type' => null, 'discount_value' => null, 'status' => LaboratoryOrder::STATUS_PENDING, 'ordered_at' => '2026-09-08 08:05:00', 'notes' => 'Incluye dos mediciones de glucosa.'],
            ['branch' => 'ZONE10', 'patient' => 'DEMO-0008', 'doctor' => null, 'commercial_client' => 'Seguros Vida Plena', 'price_list' => 'Tarifa Aseguradoras', 'exams' => ['BIO001', 'INM001'], 'discount_type' => LaboratoryOrder::DISCOUNT_TYPE_AMOUNT, 'discount_value' => '50.00', 'status' => LaboratoryOrder::STATUS_CANCELLED, 'ordered_at' => '2026-09-03 15:40:00', 'notes' => 'Orden cancelada por el paciente.'],
            ['branch' => 'MAIN', 'patient' => 'DEMO-0009', 'doctor' => 'DEMO-MED-0005', 'commercial_client' => null, 'price_list' => 'Tarifa Particular', 'exams' => ['QUI002', 'QUI003'], 'discount_type' => LaboratoryOrder::DISCOUNT_TYPE_PERCENTAGE, 'discount_value' => '12.50', 'status' => LaboratoryOrder::STATUS_COMPLETED, 'ordered_at' => '2026-08-29 09:15:00', 'notes' => 'Control de lípidos.'],
            ['branch' => 'ZONE10', 'patient' => 'DEMO-0010', 'doctor' => 'DEMO-MED-0006', 'commercial_client' => 'Corporación Atlas', 'price_list' => 'Tarifa Convenios', 'exams' => ['HEM001', 'QUI001', 'URO001'], 'discount_type' => null, 'discount_value' => null, 'status' => LaboratoryOrder::STATUS_PENDING, 'ordered_at' => '2026-08-24 14:25:00', 'notes' => 'Evaluación de ingreso.'],
            ['branch' => 'MAIN', 'patient' => 'DEMO-0011', 'doctor' => null, 'commercial_client' => null, 'price_list' => 'Tarifa Particular', 'exams' => ['MIC001'], 'discount_type' => null, 'discount_value' => null, 'status' => LaboratoryOrder::STATUS_IN_PROCESS, 'ordered_at' => '2026-08-20 10:00:00', 'notes' => 'Cultivo en proceso.'],
            ['branch' => 'ZONE10', 'patient' => 'DEMO-0012', 'doctor' => null, 'commercial_client' => 'Convenio Salud Integral', 'price_list' => 'Tarifa Convenios', 'exams' => ['COP001', 'URO001', 'QUI001'], 'discount_type' => LaboratoryOrder::DISCOUNT_TYPE_AMOUNT, 'discount_value' => '30.00', 'status' => LaboratoryOrder::STATUS_COMPLETED, 'ordered_at' => '2026-08-16 07:35:00', 'notes' => 'Panel básico de convenio.'],
            ['branch' => 'MAIN', 'patient' => 'DEMO-0013', 'doctor' => 'DEMO-MED-0007', 'commercial_client' => null, 'price_list' => 'Tarifa Particular', 'exams' => ['COA001', 'HEM001'], 'discount_type' => LaboratoryOrder::DISCOUNT_TYPE_PERCENTAGE, 'discount_value' => '5.00', 'status' => LaboratoryOrder::STATUS_CANCELLED, 'ordered_at' => '2026-08-12 16:10:00', 'notes' => 'Orden cancelada antes de recepción.'],
            ['branch' => 'ZONE10', 'patient' => 'DEMO-0014', 'doctor' => 'DEMO-MED-0008', 'commercial_client' => 'Seguros Vida Plena', 'price_list' => 'Tarifa Aseguradoras', 'exams' => ['BIO001', 'MIC001'], 'discount_type' => null, 'discount_value' => null, 'status' => LaboratoryOrder::STATUS_COMPLETED, 'ordered_at' => '2026-08-09 12:45:00', 'notes' => 'Estudios microbiológicos.'],
            ['branch' => 'MAIN', 'patient' => 'DEMO-0015', 'doctor' => null, 'commercial_client' => null, 'price_list' => 'Tarifa Particular', 'exams' => ['INM001', 'QUI001'], 'discount_type' => LaboratoryOrder::DISCOUNT_TYPE_AMOUNT, 'discount_value' => '20.00', 'status' => LaboratoryOrder::STATUS_PENDING, 'ordered_at' => '2026-08-06 08:55:00', 'notes' => 'Control inflamatorio.'],
            ['branch' => 'ZONE10', 'patient' => 'DEMO-0016', 'doctor' => null, 'commercial_client' => 'Corporación Atlas', 'price_list' => 'Tarifa Convenios', 'exams' => ['QUI002', 'HEM001', 'QUI003'], 'discount_type' => LaboratoryOrder::DISCOUNT_TYPE_PERCENTAGE, 'discount_value' => '15.00', 'status' => LaboratoryOrder::STATUS_IN_PROCESS, 'ordered_at' => '2026-08-04 10:30:00', 'notes' => 'Seguimiento ocupacional.'],
            ['branch' => 'MAIN', 'patient' => 'DEMO-0017', 'doctor' => 'DEMO-MED-0009', 'commercial_client' => null, 'price_list' => 'Tarifa Particular', 'exams' => ['HEM001', 'URO001', 'COP001', 'MIC001'], 'discount_type' => null, 'discount_value' => null, 'status' => LaboratoryOrder::STATUS_COMPLETED, 'ordered_at' => '2026-08-03 09:40:00', 'notes' => 'Panel clínico ampliado.'],
            ['branch' => 'ZONE10', 'patient' => 'DEMO-0018', 'doctor' => null, 'commercial_client' => 'Convenio Salud Integral', 'price_list' => 'Tarifa Convenios', 'exams' => ['QUI001', 'QUI003'], 'discount_type' => LaboratoryOrder::DISCOUNT_TYPE_PERCENTAGE, 'discount_value' => '20.00', 'status' => LaboratoryOrder::STATUS_CANCELLED, 'ordered_at' => '2026-08-02 11:05:00', 'notes' => 'Orden comercial cancelada.'],
        ];
    }
}

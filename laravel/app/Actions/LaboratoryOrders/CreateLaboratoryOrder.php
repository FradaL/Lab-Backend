<?php

namespace App\Actions\LaboratoryOrders;

use App\Models\Branch;
use App\Models\CommercialClient;
use App\Models\Doctor;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\Patient;
use App\Models\PriceList;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreateLaboratoryOrder
{
    private const ACTIVE_BRANCH_STATUS = 'active';

    /**
     * @param  array{
     *     branch_id: int,
     *     patient_id: int,
     *     doctor_id: ?int,
     *     commercial_client_id: ?int,
     *     price_list_id: int,
     *     ordered_at: string,
     *     notes: ?string
     * }  $attributes
     */
    public function execute(Laboratory $laboratory, User $creator, array $attributes): LaboratoryOrder
    {
        $branch = $laboratory->branches()
            ->whereKey($attributes['branch_id'])
            ->where('status', self::ACTIVE_BRANCH_STATUS)
            ->first();
        $patient = Patient::forLaboratory($laboratory)
            ->whereKey($attributes['patient_id'])
            ->where('status', Patient::STATUS_ACTIVE)
            ->first();
        $doctor = $attributes['doctor_id'] === null
            ? null
            : Doctor::forLaboratory($laboratory)
                ->whereKey($attributes['doctor_id'])
                ->where('status', Doctor::STATUS_ACTIVE)
                ->first();
        $commercialClient = $attributes['commercial_client_id'] === null
            ? null
            : CommercialClient::forLaboratory($laboratory)
                ->whereKey($attributes['commercial_client_id'])
                ->where('status', CommercialClient::STATUS_ACTIVE)
                ->first();
        $priceList = PriceList::forLaboratory($laboratory)
            ->whereKey($attributes['price_list_id'])
            ->where('status', PriceList::STATUS_ACTIVE)
            ->first();

        $errors = [];

        foreach ([
            'branch_id' => $branch,
            'patient_id' => $patient,
            'doctor_id' => $attributes['doctor_id'] === null ? true : $doctor,
            'commercial_client_id' => $attributes['commercial_client_id'] === null ? true : $commercialClient,
            'price_list_id' => $priceList,
        ] as $field => $reference) {
            if ($reference === null) {
                $errors[$field] = ['La referencia seleccionada no es válida.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        /** @var Branch $branch */
        /** @var Patient $patient */
        /** @var PriceList $priceList */
        $order = $laboratory->orders()->create([
            'branch_id' => $branch->getKey(),
            'patient_id' => $patient->getKey(),
            'doctor_id' => $doctor?->getKey(),
            'commercial_client_id' => $commercialClient?->getKey(),
            'price_list_id' => $priceList->getKey(),
            'code' => 'ORD-'.Str::ulid(),
            'ordered_at' => $attributes['ordered_at'],
            'status' => LaboratoryOrder::STATUS_PENDING,
            'notes' => $attributes['notes'],
            'subtotal' => '0.00',
            'discount' => '0.00',
            'taxes' => '0.00',
            'total' => '0.00',
            'currency' => $priceList->currency,
            'created_by' => $creator->getKey(),
        ]);

        $order->setRelation('branch', $branch);
        $order->setRelation('patient', $patient);
        $order->setRelation('doctor', $doctor);
        $order->setRelation('commercialClient', $commercialClient);
        $order->setRelation('priceList', $priceList);
        $order->setRelation('createdBy', $creator);

        return $order;
    }
}

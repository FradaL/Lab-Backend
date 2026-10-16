<?php

namespace App\Actions\LaboratoryOrders;

use App\Audit\AuditEvent;
use App\Audit\AuditWriter;
use App\Audit\OrderAuditEvents;
use App\ClinicalReports\ReportIdentity;
use App\Models\Branch;
use App\Models\CommercialClient;
use App\Models\Doctor;
use App\Models\Laboratory;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryOrder;
use App\Models\Patient;
use App\Models\PriceList;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreateLaboratoryOrder
{
    private const ACTIVE_BRANCH_STATUS = 'active';

    public function __construct(
        private readonly AuditWriter $auditWriter,
        private readonly AddExamToLaboratoryOrder $addExam,
        private readonly SetLaboratoryOrderDiscount $setDiscount,
    ) {}

    /**
     * @param  array{
     *     branch_id: int,
     *     patient_id: int,
     *     doctor_id: ?int,
     *     commercial_client_id: ?int,
     *     price_list_id: int,
     *     ordered_at: string,
     *     notes: ?string,
     *     exams?: list<array{laboratory_exam_id: int}>,
     *     discount?: array{type: string, value: string}
     * }  $attributes
     */
    public function execute(Laboratory $laboratory, User $creator, array $attributes): LaboratoryOrder
    {
        $exams = $attributes['exams'] ?? [];
        if ($exams !== []) {
            Gate::forUser($creator)->authorize('orders.add_exam');
        }
        if (array_key_exists('discount', $attributes)) {
            Gate::forUser($creator)->authorize('orders.manage_discount');
        }

        return DB::transaction(function () use ($laboratory, $creator, $attributes, $exams): LaboratoryOrder {
            $branch = $laboratory->branches()
                ->whereKey($attributes['branch_id'])
                ->where('status', self::ACTIVE_BRANCH_STATUS)
                ->sharedLock()->first();
            $patient = Patient::forLaboratory($laboratory)
                ->whereKey($attributes['patient_id'])
                ->where('status', Patient::STATUS_ACTIVE)
                ->sharedLock()->first();
            $doctor = $attributes['doctor_id'] === null
                ? null
                : Doctor::forLaboratory($laboratory)
                    ->whereKey($attributes['doctor_id'])
                    ->where('status', Doctor::STATUS_ACTIVE)
                    ->sharedLock()->first();
            $commercialClient = $attributes['commercial_client_id'] === null
                ? null
                : CommercialClient::forLaboratory($laboratory)
                    ->whereKey($attributes['commercial_client_id'])
                    ->where('status', CommercialClient::STATUS_ACTIVE)
                    ->sharedLock()->first();
            $priceList = PriceList::forLaboratory($laboratory)
                ->whereKey($attributes['price_list_id'])
                ->where('status', PriceList::STATUS_ACTIVE)
                ->sharedLock()->first();

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
                ...(Schema::hasColumn('laboratory_orders', 'report_identity_snapshot') ? ['report_identity_snapshot' => ReportIdentity::snapshot($patient, $branch, 'order_creation', $creator->id)] : []),
                'branch_id' => $branch->getKey(),
                'patient_id' => $patient->getKey(),
                'doctor_id' => $doctor?->getKey(),
                'commercial_client_id' => $commercialClient?->getKey(),
                'commercial_client_name' => $commercialClient?->name,
                'commercial_client_type' => $commercialClient?->type,
                'price_list_id' => $priceList->getKey(),
                'price_list_name' => $priceList->name,
                'code' => 'ORD-'.Str::ulid(),
                'ordered_at' => $attributes['ordered_at'],
                'status' => LaboratoryOrder::STATUS_PENDING,
                'notes' => $attributes['notes'],
                'subtotal' => '0.00',
                'discount' => '0.00',
                'discount_type' => null,
                'discount_value' => null,
                'taxes' => '0.00',
                'total' => '0.00',
                'currency' => $priceList->currency,
                'created_by' => $creator->getKey(),
            ]);

            $this->auditWriter->record($laboratory, $creator, new AuditEvent(
                OrderAuditEvents::CREATED,
                OrderAuditEvents::SUBJECT_ORDER,
                $order->getKey(),
                newValues: [
                    'code' => $order->code,
                    'branch_id' => $order->branch_id,
                    'patient_id' => $order->patient_id,
                    'doctor_id' => $order->doctor_id,
                    'commercial_client_id' => $order->commercial_client_id,
                    'price_list_id' => $order->price_list_id,
                    'ordered_at' => $order->ordered_at->format('Y-m-d H:i:s'),
                    'status' => $order->status,
                    'currency' => $order->currency,
                ],
            ));

            // Hold all selected exams in ID order before adding any line.
            // The price list is already locked; every add uses order/list/exam/price.
            $examIds = array_values(array_unique(array_column($exams, 'laboratory_exam_id')));
            sort($examIds, SORT_NUMERIC);
            LaboratoryExam::forLaboratory($laboratory)->whereIn('id', $examIds)
                ->orderBy('id')->lockForUpdate()->get();
            $lines = [];
            foreach ($exams as $index => $selection) {
                try {
                    $lines[] = $this->addExam->execute($laboratory, $order->id, $selection['laboratory_exam_id'], $creator);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(collect($exception->errors())
                        ->mapWithKeys(fn ($messages, $field) => ["exams.$index.$field" => $messages])->all());
                }
            }

            if (array_key_exists('discount', $attributes)) {
                $discount = $attributes['discount'];
                $order = $this->setDiscount->execute(
                    $laboratory,
                    $order->id,
                    $discount['type'],
                    $discount['value'],
                    $creator,
                );
            }

            $order->refresh();
            $order->setRelation('orderExams', collect($lines));

            $order->setRelation('branch', $branch);
            $order->setRelation('patient', $patient);
            $order->setRelation('doctor', $doctor);
            $order->setRelation('createdBy', $creator);

            return $order;
        });
    }
}

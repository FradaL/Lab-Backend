<?php

namespace App\Http\Requests\Api\V1\LaboratoryOrder;

use App\Models\LaboratoryOrder;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateLaboratoryOrderStatusRequest
{
    public function __construct(
        private readonly ValidationFactory $validationFactory,
    ) {}

    /** @return array{status: string} */
    public function validated(Request $request): array
    {
        $payload = $request->all();
        $validator = $this->validationFactory->make($payload, [
            'status' => [
                'required',
                'string',
                Rule::in(LaboratoryOrder::STATUSES),
            ],
        ]);

        $validator->after(function (Validator $validator) use ($payload, $request): void {
            foreach (array_keys($payload) as $field) {
                if ($field !== 'status') {
                    $validator->errors()->add($field, 'Este campo no está permitido.');
                }
            }

            $rawPayload = json_decode($request->getContent(), true);

            if (
                is_array($rawPayload)
                && array_key_exists('status', $rawPayload)
                && $rawPayload['status'] !== ($payload['status'] ?? null)
            ) {
                $validator->errors()->add(
                    'status',
                    'El estado debe enviarse exactamente como pending, in_process, completed o cancelled.',
                );
            }
        });

        /** @var array{status: string} */
        return $validator->validate();
    }
}

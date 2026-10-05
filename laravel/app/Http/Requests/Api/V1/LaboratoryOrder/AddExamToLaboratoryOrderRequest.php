<?php

namespace App\Http\Requests\Api\V1\LaboratoryOrder;

use Closure;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\Request;
use Illuminate\Validation\Validator;

final class AddExamToLaboratoryOrderRequest
{
    public function __construct(
        private readonly ValidationFactory $validationFactory,
    ) {}

    /** @return array{laboratory_exam_id: int} */
    public function validated(Request $request): array
    {
        $payload = $request->all();
        $rawPayload = json_decode($request->getContent(), true);
        $rawPayload = is_array($rawPayload) ? $rawPayload : [];

        $validator = $this->validationFactory->make($payload, [
            'laboratory_exam_id' => [
                'required',
                function (string $attribute, mixed $value, Closure $fail) use ($rawPayload): void {
                    if (
                        ! is_int($value)
                        || $value < 1
                        || ($rawPayload[$attribute] ?? null) !== $value
                    ) {
                        $fail("El campo {$attribute} debe ser un entero positivo.");
                    }
                },
            ],
        ]);

        $validator->after(function (Validator $validator) use ($rawPayload): void {
            foreach (array_keys($rawPayload) as $field) {
                if ($field !== 'laboratory_exam_id') {
                    $validator->errors()->add($field, 'El campo no está permitido.');
                }
            }
        });

        /** @var array{laboratory_exam_id: int} */
        return $validator->validate();
    }
}

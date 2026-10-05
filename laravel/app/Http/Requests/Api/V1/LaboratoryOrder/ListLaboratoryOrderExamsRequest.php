<?php

namespace App\Http\Requests\Api\V1\LaboratoryOrder;

use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\Request;
use Illuminate\Validation\Validator;

final class ListLaboratoryOrderExamsRequest
{
    public function __construct(
        private readonly ValidationFactory $validationFactory,
    ) {}

    public function validated(Request $request): void
    {
        $validator = $this->validationFactory->make([], []);

        $validator->after(function (Validator $validator) use ($request): void {
            foreach ($request->query->keys() as $field) {
                $validator->errors()->add($field, 'Este parámetro no está permitido.');
            }
        });

        $validator->validate();
    }
}

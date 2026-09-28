<?php

namespace App\Http\Requests\Api\V1\PriceList;

use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\Request;
use Illuminate\Validation\Validator;

final class SetDefaultPriceListRequest
{
    public function __construct(
        private readonly ValidationFactory $validationFactory,
    ) {}

    public function validate(Request $request): void
    {
        $body = $this->body($request);
        $query = $request->query->all();
        $validator = $this->validationFactory->make([], []);

        $validator->after(function (Validator $validator) use ($body, $query): void {
            foreach (array_keys($body) as $field) {
                $validator->errors()->add($field, 'Este campo no está permitido.');
            }

            foreach (array_keys($query) as $field) {
                $validator->errors()->add($field, 'Este parámetro de consulta no está permitido.');
            }
        });

        $validator->validate();
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $body = json_decode($request->getContent(), true);

        return is_array($body) ? $body : [];
    }
}

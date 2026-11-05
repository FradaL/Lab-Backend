<?php

namespace App\Http\Requests\Api\V1\PriceList;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ActivePriceListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['context' => ['sometimes', 'required', 'string', 'in:particular']];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ($this->query->keys() as $field) {
                    if ($field === 'context') {
                        continue;
                    }
                    $validator->errors()->add(
                        $field,
                        'Este parámetro no está permitido.',
                    );
                }
            },
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1\SampleType;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ShowSampleTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, never> */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ($this->query->keys() as $field) {
                    $validator->errors()->add(
                        $field,
                        'Este parámetro no está permitido.',
                    );
                }
            },
        ];
    }
}

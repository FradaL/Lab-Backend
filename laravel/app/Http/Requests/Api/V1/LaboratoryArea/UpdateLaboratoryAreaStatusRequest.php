<?php

namespace App\Http\Requests\Api\V1\LaboratoryArea;

use App\Models\LaboratoryArea;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateLaboratoryAreaStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'string',
                Rule::in([
                    LaboratoryArea::STATUS_ACTIVE,
                    LaboratoryArea::STATUS_INACTIVE,
                ]),
            ],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ($this->keys() as $field) {
                    if ($field !== 'status') {
                        $validator->errors()->add($field, 'Este campo no está permitido.');
                    }
                }

                $rawPayload = json_decode($this->getContent(), true);

                if (
                    is_array($rawPayload)
                    && array_key_exists('status', $rawPayload)
                    && $rawPayload['status'] !== $this->input('status')
                ) {
                    $validator->errors()->add(
                        'status',
                        'El estado debe enviarse exactamente como active o inactive.',
                    );
                }
            },
        ];
    }
}

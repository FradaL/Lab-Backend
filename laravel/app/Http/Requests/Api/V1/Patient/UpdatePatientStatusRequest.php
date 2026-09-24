<?php

namespace App\Http\Requests\Api\V1\Patient;

use App\Models\Patient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePatientStatusRequest extends FormRequest
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
                    Patient::STATUS_ACTIVE,
                    Patient::STATUS_INACTIVE,
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
                foreach (array_keys($this->all()) as $field) {
                    if ($field !== 'status') {
                        $validator->errors()->add(
                            $field,
                            'Este campo no está permitido.',
                        );
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

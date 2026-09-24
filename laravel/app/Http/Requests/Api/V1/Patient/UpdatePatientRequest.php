<?php

namespace App\Http\Requests\Api\V1\Patient;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdatePatientRequest extends FormRequest
{
    /** @var list<string> */
    private const EDITABLE_FIELDS = [
        'first_names',
        'last_names',
        'birth_date',
        'gender',
        'phone',
        'mobile',
        'email',
        'address',
        'affiliation_number',
        'weight',
        'height',
        'notes',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach ([
            'first_names',
            'last_names',
            'gender',
            'phone',
            'mobile',
            'email',
            'address',
            'affiliation_number',
            'notes',
        ] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $normalized[$field] = trim($value);
            }
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'first_names' => ['sometimes', 'required', 'string', 'max:75'],
            'last_names' => ['sometimes', 'required', 'string', 'max:75'],
            'birth_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'gender' => ['sometimes', 'nullable', 'string', 'max:20'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'mobile' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:150'],
            'address' => ['sometimes', 'nullable', 'string'],
            'affiliation_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'weight' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:999.99', 'decimal:0,2'],
            'height' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:999.99', 'decimal:0,2'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'id' => ['prohibited'],
            'laboratory_id' => ['prohibited'],
            'status' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $input = $this->all();

                foreach (self::EDITABLE_FIELDS as $field) {
                    if (array_key_exists($field, $input)) {
                        return;
                    }
                }

                $validator->errors()->add(
                    'fields',
                    'Debe proporcionar al menos un campo editable.',
                );
            },
        ];
    }
}

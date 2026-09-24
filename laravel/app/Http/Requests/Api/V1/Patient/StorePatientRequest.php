<?php

namespace App\Http\Requests\Api\V1\Patient;

use Illuminate\Foundation\Http\FormRequest;

class StorePatientRequest extends FormRequest
{
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
            'first_names' => ['required', 'string', 'max:75'],
            'last_names' => ['required', 'string', 'max:75'],
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'gender' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:30'],
            'mobile' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'string', 'email', 'max:150'],
            'address' => ['nullable', 'string'],
            'affiliation_number' => ['nullable', 'string', 'max:50'],
            'weight' => ['nullable', 'numeric', 'gt:0', 'max:999.99', 'decimal:0,2'],
            'height' => ['nullable', 'numeric', 'gt:0', 'max:999.99', 'decimal:0,2'],
            'notes' => ['nullable', 'string'],
            'id' => ['prohibited'],
            'laboratory_id' => ['prohibited'],
            'status' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
        ];
    }
}

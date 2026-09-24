<?php

namespace App\Http\Requests\Api\V1\Doctor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreDoctorRequest extends FormRequest
{
    /** @var list<string> */
    private const KNOWN_INPUT_FIELDS = [
        'first_names',
        'last_names',
        'specialty',
        'phone',
        'email',
        'license_number',
        'notes',
        'id',
        'laboratory_id',
        'status',
        'created_at',
        'updated_at',
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
            'specialty',
            'phone',
            'email',
            'license_number',
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
            'first_names' => ['required', 'string', 'max:125'],
            'last_names' => ['required', 'string', 'max:125'],
            'specialty' => ['nullable', 'string', 'max:125'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'string', 'email', 'max:150'],
            'license_number' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string'],
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
                foreach (array_keys($this->all()) as $field) {
                    if (! in_array($field, self::KNOWN_INPUT_FIELDS, true)) {
                        $validator->errors()->add(
                            $field,
                            'El campo no está permitido.',
                        );
                    }
                }
            },
        ];
    }
}

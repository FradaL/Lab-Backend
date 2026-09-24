<?php

namespace App\Http\Requests\Api\V1\Doctor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateDoctorRequest extends FormRequest
{
    /** @var list<string> */
    private const EDITABLE_FIELDS = [
        'first_names',
        'last_names',
        'specialty',
        'phone',
        'email',
        'license_number',
        'notes',
    ];

    /** @var list<string> */
    private const KNOWN_INPUT_FIELDS = [
        ...self::EDITABLE_FIELDS,
        'id',
        'laboratory_id',
        'status',
        'created_at',
        'updated_at',
    ];

    /** @var list<string> */
    private const NULLABLE_FIELDS = [
        'specialty',
        'phone',
        'email',
        'license_number',
        'notes',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (self::EDITABLE_FIELDS as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $value = trim($value);
                $normalized[$field] = $value === '' && in_array($field, self::NULLABLE_FIELDS, true)
                    ? null
                    : $value;
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
            'first_names' => ['sometimes', 'required', 'string', 'max:125'],
            'last_names' => ['sometimes', 'required', 'string', 'max:125'],
            'specialty' => ['sometimes', 'nullable', 'string', 'max:125'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:150'],
            'license_number' => ['sometimes', 'nullable', 'string', 'max:30'],
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

                foreach (array_keys($input) as $field) {
                    if (! in_array($field, self::KNOWN_INPUT_FIELDS, true)) {
                        $validator->errors()->add($field, 'El campo no está permitido.');
                    }
                }

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

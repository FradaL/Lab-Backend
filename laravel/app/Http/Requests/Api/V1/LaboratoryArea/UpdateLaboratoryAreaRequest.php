<?php

namespace App\Http\Requests\Api\V1\LaboratoryArea;

use App\Tenancy\CurrentLaboratory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateLaboratoryAreaRequest extends FormRequest
{
    /** @var list<string> */
    private const EDITABLE_FIELDS = [
        'code',
        'name',
        'description',
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

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (self::EDITABLE_FIELDS as $field) {
            if (! $this->exists($field)) {
                continue;
            }

            $value = $this->input($field);

            if (is_string($value)) {
                $value = trim($value);
                $normalized[$field] = $field === 'description' && $value === ''
                    ? null
                    : $value;
            }
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(CurrentLaboratory $currentLaboratory): array
    {
        $laboratoryId = $currentLaboratory->id();
        $areaId = (int) $this->route('area');

        return [
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:30',
                Rule::unique('laboratory_areas', 'code')
                    ->where('laboratory_id', $laboratoryId)
                    ->ignore($areaId),
            ],
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('laboratory_areas', 'name')
                    ->where('laboratory_id', $laboratoryId)
                    ->ignore($areaId),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
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
                $fields = $this->keys();

                foreach ($fields as $field) {
                    if (! in_array($field, self::KNOWN_INPUT_FIELDS, true)) {
                        $validator->errors()->add(
                            $field,
                            'El campo no está permitido.',
                        );
                    }
                }

                foreach (self::EDITABLE_FIELDS as $field) {
                    if (in_array($field, $fields, true)) {
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

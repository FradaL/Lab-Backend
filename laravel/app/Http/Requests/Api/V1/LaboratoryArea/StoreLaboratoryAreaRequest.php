<?php

namespace App\Http\Requests\Api\V1\LaboratoryArea;

use App\Tenancy\CurrentLaboratory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreLaboratoryAreaRequest extends FormRequest
{
    /** @var list<string> */
    private const KNOWN_INPUT_FIELDS = [
        'code',
        'name',
        'description',
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

        foreach (['code', 'name', 'description'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $normalized[$field] = trim($value);
            }
        }

        if (($normalized['description'] ?? null) === '') {
            $normalized['description'] = null;
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(CurrentLaboratory $currentLaboratory): array
    {
        $laboratoryId = $currentLaboratory->id();

        return [
            'code' => [
                'required',
                'string',
                'max:30',
                Rule::unique('laboratory_areas', 'code')
                    ->where('laboratory_id', $laboratoryId),
            ],
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('laboratory_areas', 'name')
                    ->where('laboratory_id', $laboratoryId),
            ],
            'description' => ['nullable', 'string', 'max:255'],
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
                foreach ($this->keys() as $field) {
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

<?php

namespace App\Http\Requests\Api\V1\SampleType;

use App\Tenancy\CurrentLaboratory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSampleTypeRequest extends FormRequest
{
    /** @var list<string> */
    private const KNOWN_INPUT_FIELDS = [
        'name',
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
        $name = $this->input('name');

        if (is_string($name)) {
            $this->merge(['name' => trim($name)]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(CurrentLaboratory $currentLaboratory): array
    {
        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('sample_types', 'name')
                    ->where('laboratory_id', $currentLaboratory->id())
                    ->ignore((int) $this->route('sampleType')),
            ],
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

                if (! in_array('name', $fields, true)) {
                    $validator->errors()->add(
                        'fields',
                        'Debe proporcionar al menos un campo editable.',
                    );
                }
            },
        ];
    }
}

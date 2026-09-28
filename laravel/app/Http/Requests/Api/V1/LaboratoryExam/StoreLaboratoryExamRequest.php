<?php

namespace App\Http\Requests\Api\V1\LaboratoryExam;

use App\Models\LaboratoryArea;
use App\Models\SampleType;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreLaboratoryExamRequest extends FormRequest
{
    /** @var list<string> */
    private const KNOWN_INPUT_FIELDS = [
        'laboratory_area_id',
        'sample_type_id',
        'code',
        'name',
        'description',
        'turnaround_time_minutes',
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
            'laboratory_area_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('laboratory_areas', 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('laboratory_id', $laboratoryId)
                        ->where('status', LaboratoryArea::STATUS_ACTIVE)),
            ],
            'sample_type_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('sample_types', 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('laboratory_id', $laboratoryId)
                        ->where('status', SampleType::STATUS_ACTIVE)),
            ],
            'code' => [
                'required',
                'string',
                'max:30',
                Rule::unique('laboratory_exams', 'code')
                    ->where('laboratory_id', $laboratoryId),
            ],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'turnaround_time_minutes' => ['nullable', 'integer', 'min:0'],
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

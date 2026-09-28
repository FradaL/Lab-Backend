<?php

namespace App\Http\Requests\Api\V1\LaboratoryExam;

use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use App\Models\LaboratoryExam;
use App\Models\SampleType;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateLaboratoryExamRequest
{
    /** @var list<string> */
    private const EDITABLE_FIELDS = [
        'laboratory_area_id',
        'sample_type_id',
        'code',
        'name',
        'description',
        'turnaround_time_minutes',
    ];

    public function __construct(
        private readonly ValidationFactory $validationFactory,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function validated(
        Request $request,
        Laboratory $laboratory,
        LaboratoryExam $laboratoryExam,
    ): array {
        $payload = $this->normalize($request->all());

        $validator = $this->validationFactory->make($payload, [
            'laboratory_area_id' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
                Rule::exists('laboratory_areas', 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('laboratory_id', $laboratory->getKey())
                        ->where('status', LaboratoryArea::STATUS_ACTIVE)),
            ],
            'sample_type_id' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
                Rule::exists('sample_types', 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('laboratory_id', $laboratory->getKey())
                        ->where('status', SampleType::STATUS_ACTIVE)),
            ],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:30',
                Rule::unique('laboratory_exams', 'code')
                    ->where('laboratory_id', $laboratory->getKey())
                    ->ignore($laboratoryExam->getKey()),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string'],
            'turnaround_time_minutes' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        $validator->after(function (Validator $validator) use ($payload): void {
            foreach (array_keys($payload) as $field) {
                if (! in_array($field, self::EDITABLE_FIELDS, true)) {
                    $validator->errors()->add($field, 'El campo no está permitido.');
                }
            }

            if (array_intersect(self::EDITABLE_FIELDS, array_keys($payload)) === []) {
                $validator->errors()->add(
                    'payload',
                    'Debe enviar al menos un campo permitido.',
                );
            }
        });

        return $validator->validate();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalize(array $payload): array
    {
        foreach (['code', 'name', 'description'] as $field) {
            if (array_key_exists($field, $payload) && is_string($payload[$field])) {
                $payload[$field] = trim($payload[$field]);
            }
        }

        if (($payload['description'] ?? null) === '') {
            $payload['description'] = null;
        }

        return $payload;
    }
}

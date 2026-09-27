<?php

namespace App\Http\Requests\Api\V1\LaboratoryExam;

use App\Models\LaboratoryExam;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class IndexLaboratoryExamRequest extends FormRequest
{
    /** @var list<string> */
    private const KNOWN_QUERY_PARAMETERS = [
        'search',
        'status',
        'laboratory_area_id',
        'sample_type_id',
        'sort',
        'direction',
        'per_page',
        'page',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $search = $this->input('search');

        if (is_string($search)) {
            $this->merge(['search' => trim($search)]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'status' => [
                'sometimes',
                Rule::in([
                    LaboratoryExam::STATUS_ACTIVE,
                    LaboratoryExam::STATUS_INACTIVE,
                ]),
                function (string $attribute, mixed $value, Closure $fail): void {
                    $original = $this->originalQueryParameter($attribute);

                    if (is_string($original) && $original !== trim($original)) {
                        $fail('El estado seleccionado no es válido.');
                    }
                },
            ],
            'laboratory_area_id' => ['sometimes', 'integer', 'min:1'],
            'sample_type_id' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', Rule::in([
                'code',
                'name',
                'created_at',
            ])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (array_keys($this->query->all()) as $parameter) {
                    if (! in_array($parameter, self::KNOWN_QUERY_PARAMETERS, true)) {
                        $validator->errors()->add(
                            $parameter,
                            'El parámetro de consulta no está permitido.',
                        );
                    }
                }
            },
        ];
    }

    public function search(): ?string
    {
        return $this->normalizedOptionalString('search');
    }

    public function status(): ?string
    {
        return $this->normalizedOptionalString('status');
    }

    public function laboratoryAreaId(): ?int
    {
        return $this->validatedInteger('laboratory_area_id');
    }

    public function sampleTypeId(): ?int
    {
        return $this->validatedInteger('sample_type_id');
    }

    public function sort(): string
    {
        return (string) ($this->validated('sort') ?? 'name');
    }

    public function direction(): string
    {
        return (string) ($this->validated('direction') ?? 'asc');
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 15);
    }

    private function normalizedOptionalString(string $field): ?string
    {
        $value = $this->validated($field);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function validatedInteger(string $field): ?int
    {
        $value = $this->validated($field);

        return $value === null ? null : (int) $value;
    }

    private function originalQueryParameter(string $field): mixed
    {
        parse_str((string) $this->server->get('QUERY_STRING'), $query);

        return $query[$field] ?? null;
    }
}

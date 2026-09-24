<?php

namespace App\Http\Requests\Api\V1\SampleType;

use App\Models\SampleType;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class IndexSampleTypeRequest extends FormRequest
{
    /** @var list<string> */
    private const KNOWN_QUERY_PARAMETERS = [
        'search',
        'status',
        'sort',
        'direction',
        'per_page',
        'page',
        'laboratory_id',
        'laboratory',
        'lab',
        'tenant',
        'institution_id',
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
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => [
                'sometimes',
                Rule::in([
                    SampleType::STATUS_ACTIVE,
                    SampleType::STATUS_INACTIVE,
                ]),
                function (string $attribute, mixed $value, Closure $fail): void {
                    $original = $this->originalQueryParameter($attribute);

                    if (is_string($original) && $original !== trim($original)) {
                        $fail('El estado seleccionado no es válido.');
                    }
                },
            ],
            'sort' => ['sometimes', Rule::in([
                'name',
                'created_at',
            ])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'laboratory_id' => ['prohibited'],
            'laboratory' => ['prohibited'],
            'lab' => ['prohibited'],
            'tenant' => ['prohibited'],
            'institution_id' => ['prohibited'],
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

    private function originalQueryParameter(string $field): mixed
    {
        parse_str((string) $this->server->get('QUERY_STRING'), $query);

        return $query[$field] ?? null;
    }
}

<?php

namespace App\Http\Requests\Api\V1\Doctor;

use App\Models\Doctor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class IndexDoctorRequest extends FormRequest
{
    /** @var list<string> */
    private const KNOWN_QUERY_PARAMETERS = [
        'search',
        'status',
        'specialty',
        'sort',
        'direction',
        'per_page',
        'page',
        'laboratory_id',
        'lab',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['search', 'specialty'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $normalized[$field] = trim($value);
            }
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'status' => ['sometimes', Rule::in([
                Doctor::STATUS_ACTIVE,
                Doctor::STATUS_INACTIVE,
            ])],
            'specialty' => ['sometimes', 'nullable', 'string', 'max:125'],
            'sort' => ['sometimes', Rule::in([
                'first_names',
                'last_names',
                'specialty',
                'created_at',
            ])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'laboratory_id' => ['prohibited'],
            'lab' => ['prohibited'],
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

    public function specialty(): ?string
    {
        return $this->normalizedOptionalString('specialty');
    }

    public function sort(): string
    {
        return (string) ($this->validated('sort') ?? 'last_names');
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
}

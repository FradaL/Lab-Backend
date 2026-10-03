<?php

namespace App\Http\Requests\Api\V1\CommercialClient;

use App\Models\CommercialClient;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class IndexCommercialClientRequest extends FormRequest
{
    /** @var list<string> */
    private const KNOWN_QUERY_PARAMETERS = [
        'search',
        'status',
        'type',
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

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'status' => ['sometimes', $this->strictValueRule([
                CommercialClient::STATUS_ACTIVE,
                CommercialClient::STATUS_INACTIVE,
            ])],
            'type' => ['sometimes', $this->strictValueRule([
                CommercialClient::TYPE_INSURANCE,
                CommercialClient::TYPE_COMPANY,
                CommercialClient::TYPE_AGREEMENT,
                CommercialClient::TYPE_OTHER,
            ])],
            'sort' => ['sometimes', $this->strictValueRule(['name', 'type', 'created_at'])],
            'direction' => ['sometimes', $this->strictValueRule(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
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

    public function type(): ?string
    {
        return $this->normalizedOptionalString('type');
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

    /** @param list<string> $allowed */
    private function strictValueRule(array $allowed): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($allowed): void {
            $original = $this->originalQueryParameter($attribute);

            if (! is_string($value) || ! in_array($value, $allowed, true) || $original !== $value) {
                $fail('El valor seleccionado no es válido.');
            }
        };
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

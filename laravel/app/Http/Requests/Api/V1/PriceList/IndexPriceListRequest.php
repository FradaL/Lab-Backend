<?php

namespace App\Http\Requests\Api\V1\PriceList;

use App\Models\PriceList;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class IndexPriceListRequest extends FormRequest
{
    /** @var list<string> */
    private const KNOWN_QUERY_PARAMETERS = [
        'search',
        'status',
        'currency',
        'is_default',
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
            'search' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', $this->strictValueRule(
                [PriceList::STATUS_ACTIVE, PriceList::STATUS_INACTIVE],
                'El estado seleccionado no es válido.',
            )],
            'currency' => ['sometimes', $this->strictPatternRule(
                '/\A[A-Z]{3}\z/',
                'La moneda debe contener exactamente tres letras mayúsculas.',
            )],
            'is_default' => ['sometimes', $this->strictValueRule(
                ['true', 'false'],
                'El filtro de lista predeterminada debe ser true o false.',
            )],
            'sort' => ['sometimes', $this->strictValueRule(
                ['name', 'currency', 'created_at'],
                'El criterio de ordenamiento no es válido.',
            )],
            'direction' => ['sometimes', $this->strictValueRule(
                ['asc', 'desc'],
                'La dirección de ordenamiento no es válida.',
            )],
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

    public function currency(): ?string
    {
        return $this->normalizedOptionalString('currency');
    }

    public function isDefault(): ?bool
    {
        return match ($this->validated('is_default')) {
            'true' => true,
            'false' => false,
            default => null,
        };
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
    private function strictValueRule(array $allowed, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($allowed, $message): void {
            $original = $this->originalQueryParameter($attribute);

            if (! is_string($value) || ! in_array($value, $allowed, true) || $original !== $value) {
                $fail($message);
            }
        };
    }

    private function strictPatternRule(string $pattern, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($pattern, $message): void {
            $original = $this->originalQueryParameter($attribute);

            if (! is_string($value) || preg_match($pattern, $value) !== 1 || $original !== $value) {
                $fail($message);
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

<?php

namespace App\Http\Requests\Api\V1\CommercialClientPriceList;

use App\Models\CommercialClientPriceList;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class IndexCommercialClientPriceListRequest extends FormRequest
{
    /** @var list<string> */
    private const KNOWN_QUERY_PARAMETERS = [
        'effective_date',
        'status',
        'effective',
        'search',
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
            'effective_date' => ['sometimes', 'date_format:Y-m-d', $this->strictDateRule()],
            'status' => ['sometimes', $this->strictValueRule([
                CommercialClientPriceList::STATUS_ACTIVE,
                CommercialClientPriceList::STATUS_INACTIVE,
            ])],
            'effective' => ['sometimes', $this->strictValueRule(['true', 'false'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'sort' => ['sometimes', $this->strictValueRule([
                'starts_at',
                'ends_at',
                'status',
                'created_at',
                'updated_at',
                'price_list_name',
            ])],
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

    public function effectiveDate(): CarbonImmutable
    {
        $value = $this->validated('effective_date');

        return is_string($value)
            ? CarbonImmutable::createFromFormat('!Y-m-d', $value, config('app.timezone'))
            : CarbonImmutable::today(config('app.timezone'));
    }

    public function status(): ?string
    {
        return $this->normalizedOptionalString('status');
    }

    public function effective(): ?bool
    {
        return match ($this->validated('effective')) {
            'true' => true,
            'false' => false,
            default => null,
        };
    }

    public function search(): ?string
    {
        return $this->normalizedOptionalString('search');
    }

    public function sort(): string
    {
        return (string) ($this->validated('sort') ?? 'starts_at');
    }

    public function direction(): string
    {
        return (string) ($this->validated('direction') ?? 'desc');
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 15);
    }

    private function strictDateRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (
                ! is_string($value)
                || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1
                || $this->originalQueryParameter($attribute) !== $value
            ) {
                $fail("El campo {$attribute} debe tener el formato YYYY-MM-DD.");
            }
        };
    }

    /** @param list<string> $allowed */
    private function strictValueRule(array $allowed): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($allowed): void {
            if (
                ! is_string($value)
                || ! in_array($value, $allowed, true)
                || $this->originalQueryParameter($attribute) !== $value
            ) {
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

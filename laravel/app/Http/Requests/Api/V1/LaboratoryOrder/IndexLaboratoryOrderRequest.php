<?php

namespace App\Http\Requests\Api\V1\LaboratoryOrder;

use App\Models\LaboratoryOrder;
use App\Tenancy\CurrentLaboratory;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class IndexLaboratoryOrderRequest extends FormRequest
{
    public const COMMERCIAL_CONTEXT_PARTICULAR = 'particular';

    public const COMMERCIAL_CONTEXT_CLIENT = 'client';

    /** @var list<string> */
    private const KNOWN_QUERY_PARAMETERS = [
        'page',
        'per_page',
        'search',
        'date_from',
        'date_to',
        'status',
        'branch_id',
        'doctor_id',
        'commercial_client_id',
        'commercial_context',
        'price_list_id',
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
        $normalized = [];
        $search = $this->query('search');

        if (is_string($search)) {
            $collapsedSearch = preg_replace('/\s+/u', ' ', trim($search));
            $normalized['search'] = $collapsedSearch ?? trim($search);
        }

        $commercialContext = $this->query('commercial_context');

        if (is_string($commercialContext)) {
            $normalized['commercial_context'] = trim($commercialContext);
        }

        $this->merge($normalized);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(CurrentLaboratory $currentLaboratory): array
    {
        $laboratoryId = $currentLaboratory->id();

        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'date_from' => ['sometimes', 'date_format:Y-m-d', $this->strictDateRule()],
            'date_to' => ['sometimes', 'date_format:Y-m-d', $this->strictDateRule()],
            'status' => ['sometimes', Rule::in(LaboratoryOrder::STATUSES), $this->strictQueryValueRule()],
            'branch_id' => [
                'sometimes',
                'integer',
                'min:1',
                Rule::exists('branches', 'id')->where('laboratory_id', $laboratoryId),
            ],
            'doctor_id' => [
                'sometimes',
                'integer',
                'min:1',
                Rule::exists('doctors', 'id')->where('laboratory_id', $laboratoryId),
            ],
            'commercial_client_id' => [
                'sometimes',
                'integer',
                'min:1',
                Rule::exists('commercial_clients', 'id')->where('laboratory_id', $laboratoryId),
            ],
            'commercial_context' => ['sometimes', 'nullable', Rule::in([
                self::COMMERCIAL_CONTEXT_PARTICULAR,
                self::COMMERCIAL_CONTEXT_CLIENT,
            ]), $this->strictQueryValueRule()],
            'price_list_id' => [
                'sometimes',
                'integer',
                'min:1',
                Rule::exists('price_lists', 'id')->where('laboratory_id', $laboratoryId),
            ],
            'laboratory_id' => ['prohibited'],
            'laboratory' => ['prohibited'],
            'lab' => ['prohibited'],
            'tenant' => ['prohibited'],
            'institution_id' => ['prohibited'],
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

                $dateFrom = $this->input('date_from');
                $dateTo = $this->input('date_to');

                if (
                    ! $validator->errors()->has('date_from')
                    && ! $validator->errors()->has('date_to')
                    && is_string($dateFrom)
                    && is_string($dateTo)
                    && $dateFrom > $dateTo
                ) {
                    $validator->errors()->add(
                        'date_to',
                        'La fecha final debe ser igual o posterior a la fecha inicial.',
                    );
                }

                if (
                    $this->input('commercial_context') === self::COMMERCIAL_CONTEXT_PARTICULAR
                    && $this->query->has('commercial_client_id')
                ) {
                    $validator->errors()->add(
                        'commercial_client_id',
                        'El cliente comercial contradice el contexto particular.',
                    );
                }
            },
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 15);
    }

    public function search(): ?string
    {
        $search = $this->validated('search');

        return is_string($search) && $search !== '' ? $search : null;
    }

    public function dateFrom(): ?CarbonImmutable
    {
        return $this->validatedDate('date_from');
    }

    public function dateToExclusive(): ?CarbonImmutable
    {
        return $this->validatedDate('date_to')?->addDay();
    }

    /** @return array<string, int|string> */
    public function exactFilters(): array
    {
        $filters = [];

        foreach (['status', 'branch_id', 'doctor_id', 'commercial_client_id', 'price_list_id'] as $field) {
            $value = $this->validated($field);

            if ($value !== null) {
                $filters[$field] = str_ends_with($field, '_id') ? (int) $value : (string) $value;
            }
        }

        return $filters;
    }

    public function commercialContext(): ?string
    {
        $context = $this->validated('commercial_context');

        return is_string($context) && $context !== '' ? $context : null;
    }

    private function validatedDate(string $field): ?CarbonImmutable
    {
        $value = $this->validated($field);

        return is_string($value)
            ? CarbonImmutable::createFromFormat('!Y-m-d', $value, config('app.timezone'))
            : null;
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

    private function strictQueryValueRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || $this->originalQueryParameter($attribute) !== $value) {
                $fail('El valor seleccionado no es válido.');
            }
        };
    }

    private function originalQueryParameter(string $field): mixed
    {
        parse_str((string) $this->server->get('QUERY_STRING'), $query);

        return $query[$field] ?? null;
    }
}

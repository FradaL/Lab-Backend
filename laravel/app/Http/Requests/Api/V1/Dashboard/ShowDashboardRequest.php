<?php

namespace App\Http\Requests\Api\V1\Dashboard;

use App\Tenancy\CurrentLaboratory;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class ShowDashboardRequest extends FormRequest
{
    /** @var list<string> */
    private const KNOWN_QUERY_PARAMETERS = [
        'date',
        'branch_id',
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

    /** @return array<string, array<int, mixed>> */
    public function rules(CurrentLaboratory $currentLaboratory): array
    {
        return [
            'date' => ['sometimes', 'date_format:Y-m-d', $this->strictDateRule()],
            'branch_id' => [
                'sometimes',
                'integer',
                'min:1',
                Rule::exists('branches', 'id')->where('laboratory_id', $currentLaboratory->id()),
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
            },
        ];
    }

    public function businessDate(CurrentLaboratory $currentLaboratory): CarbonImmutable
    {
        $timezone = $currentLaboratory->get()->timezone;
        $date = $this->validated('date');

        return is_string($date)
            ? CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone)
            : CarbonImmutable::now($timezone)->startOfDay();
    }

    public function branchId(): ?int
    {
        $branchId = $this->validated('branch_id');

        return $branchId === null ? null : (int) $branchId;
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

    private function originalQueryParameter(string $field): mixed
    {
        parse_str((string) $this->server->get('QUERY_STRING'), $query);

        return $query[$field] ?? null;
    }
}

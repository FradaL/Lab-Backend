<?php

namespace App\Http\Requests\Api\V1\PriceList;

use App\Tenancy\CurrentLaboratory;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePriceListRequest extends FormRequest
{
    /** @var list<string> */
    private const KNOWN_INPUT_FIELDS = [
        'name',
        'description',
        'currency',
        'id',
        'laboratory_id',
        'status',
        'is_default',
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

        foreach (['name', 'description'] as $field) {
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

    /** @return array<string, array<int, mixed>> */
    public function rules(CurrentLaboratory $currentLaboratory): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:75',
                Rule::unique('price_lists', 'name')
                    ->where('laboratory_id', $currentLaboratory->id()),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'currency' => [
                'required',
                $this->strictCurrencyRule(),
            ],
            'id' => ['prohibited'],
            'laboratory_id' => ['prohibited'],
            'status' => ['prohibited'],
            'is_default' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ($this->originalPayloadKeys() as $field) {
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

    private function strictCurrencyRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $rawPayload = $this->originalPayload();
            $original = $rawPayload[$attribute] ?? null;

            if (
                ! is_string($value)
                || preg_match('/\A[A-Z]{3}\z/', $value) !== 1
                || $original !== $value
            ) {
                $fail('La moneda debe contener exactamente tres letras mayúsculas.');
            }
        };
    }

    /** @return array<string, mixed> */
    private function originalPayload(): array
    {
        $payload = json_decode($this->getContent(), true);

        return is_array($payload) ? $payload : [];
    }

    /** @return list<string> */
    private function originalPayloadKeys(): array
    {
        return array_values(array_filter(
            array_keys($this->originalPayload()),
            is_string(...),
        ));
    }
}

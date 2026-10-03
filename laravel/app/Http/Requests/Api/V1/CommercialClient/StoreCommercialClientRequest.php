<?php

namespace App\Http\Requests\Api\V1\CommercialClient;

use App\Models\CommercialClient;
use App\Tenancy\CurrentLaboratory;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCommercialClientRequest extends FormRequest
{
    /** @var list<string> */
    private const KNOWN_INPUT_FIELDS = [
        'name',
        'type',
        'tax_id',
        'phone',
        'email',
        'address',
        'notes',
        'id',
        'laboratory_id',
        'status',
        'price_list_id',
        'branch_id',
        'patient_id',
        'doctor_id',
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

        foreach (['name', 'tax_id', 'phone', 'email', 'address', 'notes'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $normalized[$field] = trim($value);
            }
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
                'max:150',
                Rule::unique('commercial_clients', 'name')
                    ->where('laboratory_id', $currentLaboratory->id()),
            ],
            'type' => ['required', $this->strictTypeRule()],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'string', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'id' => ['prohibited'],
            'laboratory_id' => ['prohibited'],
            'status' => ['prohibited'],
            'price_list_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'patient_id' => ['prohibited'],
            'doctor_id' => ['prohibited'],
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

    private function strictTypeRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $original = $this->originalPayload()[$attribute] ?? null;

            if (
                ! is_string($value)
                || ! in_array($value, [
                    CommercialClient::TYPE_INSURANCE,
                    CommercialClient::TYPE_COMPANY,
                    CommercialClient::TYPE_AGREEMENT,
                    CommercialClient::TYPE_OTHER,
                ], true)
                || $original !== $value
            ) {
                $fail('El tipo seleccionado no es válido.');
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

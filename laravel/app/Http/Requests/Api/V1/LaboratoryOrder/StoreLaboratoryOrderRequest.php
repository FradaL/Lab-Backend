<?php

namespace App\Http\Requests\Api\V1\LaboratoryOrder;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StoreLaboratoryOrderRequest extends FormRequest
{
    /** @var list<string> */
    private const INPUT_FIELDS = [
        'branch_id',
        'patient_id',
        'doctor_id',
        'commercial_client_id',
        'price_list_id',
        'ordered_at',
        'notes',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $payload = $this->originalPayload();

        return [
            'branch_id' => ['required', $this->strictPositiveIntegerRule($payload)],
            'patient_id' => ['required', $this->strictPositiveIntegerRule($payload)],
            'doctor_id' => ['present', 'nullable', $this->strictPositiveIntegerRule($payload)],
            'commercial_client_id' => ['present', 'nullable', $this->strictPositiveIntegerRule($payload)],
            'price_list_id' => ['required', $this->strictPositiveIntegerRule($payload)],
            'ordered_at' => [
                'required',
                'date_format:Y-m-d H:i:s',
                $this->strictDateTimeRule($payload),
            ],
            'notes' => ['present', 'nullable', 'string'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (array_keys($this->originalPayload()) as $field) {
                    if (! in_array($field, self::INPUT_FIELDS, true)) {
                        $validator->errors()->add($field, 'El campo no está permitido.');
                    }
                }
            },
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function strictPositiveIntegerRule(array $payload): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($payload): void {
            if (
                ! is_int($value)
                || $value < 1
                || ($payload[$attribute] ?? null) !== $value
            ) {
                $fail("El campo {$attribute} debe ser un entero positivo.");
            }
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function strictDateTimeRule(array $payload): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($payload): void {
            if (
                ! is_string($value)
                || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $value) !== 1
                || ($payload[$attribute] ?? null) !== $value
            ) {
                $fail("El campo {$attribute} debe tener el formato YYYY-MM-DD HH:mm:ss.");
            }
        };
    }

    /** @return array<string, mixed> */
    private function originalPayload(): array
    {
        $payload = json_decode($this->getContent(), true);

        return is_array($payload) ? $payload : [];
    }
}

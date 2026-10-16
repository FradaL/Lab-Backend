<?php

namespace App\Http\Requests\Api\V1\LaboratoryOrder;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use stdClass;

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
        'exams',
        'discount',
    ];

    /** @var array{type: string, value: string}|null */
    private ?array $validatedDiscount = null;

    public function authorize(): bool
    {
        $payload = $this->originalPayload();
        $exams = $payload['exams'] ?? [];
        $canAddExams = ! is_array($exams)
            || $exams === []
            || ($this->user()?->can('orders.add_exam') ?? false);
        $canManageDiscount = ! array_key_exists('discount', $payload)
            || ($this->user()?->can('orders.manage_discount') ?? false);

        return $canAddExams && $canManageDiscount;
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
            'exams' => [
                'sometimes', 'array', 'list', 'max:50',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $original = json_decode($this->getContent());
                    if (! is_object($original) || ! is_array($original->exams ?? null)) {
                        $fail('El campo exams debe ser un arreglo JSON.');
                    }
                },
            ],
            'exams.*' => ['required', 'array:laboratory_exam_id'],
            'exams.*.laboratory_exam_id' => [
                'required',
                function (string $attribute, mixed $value, Closure $fail) use ($payload): void {
                    if (! is_int($value) || $value < 1 || data_get($payload, $attribute) !== $value) {
                        $fail("El campo {$attribute} debe ser un entero positivo.");
                    }
                },
            ],
            'discount' => ['sometimes'],
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

                if (! array_key_exists('discount', $this->originalPayload())) {
                    return;
                }

                try {
                    $this->validatedDiscount = app(UpdateLaboratoryOrderDiscountRequest::class)
                        ->validatedPayload($this->originalDiscountPayload());
                } catch (ValidationException $exception) {
                    foreach ($exception->errors() as $field => $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add("discount.{$field}", $message);
                        }
                    }
                }
            },
        ];
    }

    /** @return array<string, mixed> */
    public function creationAttributes(): array
    {
        $attributes = $this->validated();

        if (array_key_exists('discount', $this->originalPayload())) {
            $attributes['discount'] = $this->validatedDiscount;
        }

        return $attributes;
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

    private function originalDiscountPayload(): mixed
    {
        $payload = json_decode($this->getContent(), false, 512, JSON_BIGINT_AS_STRING);

        return $payload instanceof stdClass && property_exists($payload, 'discount')
            ? $payload->discount
            : null;
    }
}

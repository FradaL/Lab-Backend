<?php

namespace App\Http\Requests\Api\V1\Pricing;

use Closure;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\Request;
use Illuminate\Validation\Validator;

final class ResolvePriceListRequest
{
    /** @var list<string> */
    private const INPUT_FIELDS = ['commercial_client_id', 'effective_date'];

    public function __construct(
        private readonly ValidationFactory $validationFactory,
    ) {}

    /** @return array{commercial_client_id: ?int, effective_date: string} */
    public function validated(Request $request): array
    {
        $payload = $request->all();
        $originalPayload = $this->originalPayload($request);
        $validator = $this->validationFactory->make($payload, [
            'commercial_client_id' => [
                'present',
                'nullable',
                'integer',
                'min:1',
                $this->strictNullableIntegerRule($originalPayload),
            ],
            'effective_date' => [
                'required',
                'date_format:Y-m-d',
                $this->strictDateRule($originalPayload),
            ],
        ]);

        $validator->after(function (Validator $validator) use ($originalPayload, $payload): void {
            foreach (array_keys($payload) as $field) {
                if (! in_array($field, self::INPUT_FIELDS, true)) {
                    $validator->errors()->add($field, 'El campo no está permitido.');
                }
            }

            if (
                array_key_exists('commercial_client_id', $originalPayload)
                && $originalPayload['commercial_client_id'] !== null
                && ! is_int($originalPayload['commercial_client_id'])
            ) {
                $validator->errors()->add(
                    'commercial_client_id',
                    'El campo commercial_client_id debe ser null o un entero positivo.',
                );
            }
        });

        /** @var array{commercial_client_id: ?int, effective_date: string} */
        return $validator->validate();
    }

    /** @param array<string, mixed> $originalPayload */
    private function strictNullableIntegerRule(array $originalPayload): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($originalPayload): void {
            if ($value !== null && (! is_int($value) || ($originalPayload[$attribute] ?? null) !== $value)) {
                $fail("El campo {$attribute} debe ser null o un entero positivo.");
            }
        };
    }

    /** @param array<string, mixed> $originalPayload */
    private function strictDateRule(array $originalPayload): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($originalPayload): void {
            if (
                ! is_string($value)
                || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1
                || ($originalPayload[$attribute] ?? null) !== $value
            ) {
                $fail("El campo {$attribute} debe tener el formato YYYY-MM-DD.");
            }
        };
    }

    /** @return array<string, mixed> */
    private function originalPayload(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);

        return is_array($payload) ? $payload : [];
    }
}

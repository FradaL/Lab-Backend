<?php

namespace App\Http\Requests\Api\V1\LaboratoryOrder;

use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\Request;
use Illuminate\Validation\Validator;
use stdClass;

final class UpdateLaboratoryOrderDiscountRequest
{
    private const MAX_CENTS = 999_999_999_999;

    public function __construct(
        private readonly ValidationFactory $validationFactory,
    ) {}

    /** @return array{type: string, value: string} */
    public function validated(Request $request): array
    {
        $decoded = json_decode($request->getContent(), false, 512, JSON_BIGINT_AS_STRING);
        $payload = $decoded instanceof stdClass ? get_object_vars($decoded) : [];

        $validator = $this->validationFactory->make($payload, [
            'type' => ['required', 'string'],
            'value' => ['required'],
        ]);

        $validator->after(function (Validator $validator) use ($payload): void {
            foreach (array_keys($payload) as $field) {
                if (! in_array($field, ['type', 'value'], true)) {
                    $validator->errors()->add($field, 'El campo no está permitido.');
                }
            }

            $type = $payload['type'] ?? null;
            if (is_string($type) && ! in_array($type, ['percentage', 'amount'], true)) {
                $validator->errors()->add('type', 'El tipo debe ser percentage o amount.');
            }

            if (! array_key_exists('value', $payload)) {
                return;
            }

            $cents = $this->decimalToCents($payload['value']);
            if ($cents === null) {
                $validator->errors()->add(
                    'value',
                    'El valor debe ser un decimal positivo con máximo dos posiciones decimales.',
                );

                return;
            }

            if ($type === 'percentage' && $cents > 10_000) {
                $validator->errors()->add('value', 'El porcentaje debe ser mayor que 0 y no superar 100.');
            }
        });

        $validated = $validator->validate();
        $cents = $this->decimalToCents($validated['value']);

        return [
            'type' => $validated['type'],
            'value' => $this->formatCents($cents),
        ];
    }

    private function decimalToCents(mixed $value): ?int
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $decimal = (string) $value;
        if (preg_match('/\A\d+(?:\.\d{1,2})?\z/', $decimal) !== 1) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;

        if (strlen($whole) > 10 || (strlen($whole) === 10 && $whole > '9999999999')) {
            return null;
        }

        $cents = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');

        return $cents > 0 && $cents <= self::MAX_CENTS ? $cents : null;
    }

    private function formatCents(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}

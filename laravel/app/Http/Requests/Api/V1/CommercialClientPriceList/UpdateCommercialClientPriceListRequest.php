<?php

namespace App\Http\Requests\Api\V1\CommercialClientPriceList;

use App\Models\CommercialClientPriceList;
use Closure;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

final class UpdateCommercialClientPriceListRequest
{
    /** @var list<string> */
    private const INPUT_FIELDS = ['price_list_id', 'starts_at', 'ends_at'];

    public function __construct(
        private readonly ValidationFactory $validationFactory,
    ) {}

    /** @return array{price_list_id?: int, starts_at?: string, ends_at?: ?string} */
    public function validated(Request $request, CommercialClientPriceList $assignment): array
    {
        $payload = $request->all();
        $originalPayload = $this->originalPayload($request);
        $validator = $this->validationFactory->make($payload, [
            'price_list_id' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
                $this->strictIntegerRule($originalPayload),
            ],
            'starts_at' => [
                'sometimes',
                'required',
                'date_format:Y-m-d',
                $this->strictDateRule($originalPayload),
            ],
            'ends_at' => [
                'sometimes',
                'nullable',
                'date_format:Y-m-d',
                $this->strictDateRule($originalPayload),
            ],
        ]);

        $validator->after(function (Validator $validator) use ($payload): void {
            if ($payload === []) {
                $validator->errors()->add('payload', 'Debe proporcionar al menos un campo editable.');
            }

            foreach (array_keys($payload) as $field) {
                if (! in_array($field, self::INPUT_FIELDS, true)) {
                    $validator->errors()->add($field, 'El campo no está permitido.');
                }
            }
        });

        /** @var array{price_list_id?: int, starts_at?: string, ends_at?: ?string} $validated */
        $validated = $validator->validate();
        $resultingStart = $validated['starts_at'] ?? $assignment->starts_at->toDateString();
        $resultingEnd = array_key_exists('ends_at', $validated)
            ? $validated['ends_at']
            : $assignment->ends_at?->toDateString();

        if ($resultingEnd !== null && $resultingEnd < $resultingStart) {
            throw ValidationException::withMessages([
                'ends_at' => ['El campo ends_at debe ser una fecha posterior o igual a starts_at.'],
            ]);
        }

        return $validated;
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

    /** @param array<string, mixed> $originalPayload */
    private function strictIntegerRule(array $originalPayload): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($originalPayload): void {
            if (! is_int($value) || ($originalPayload[$attribute] ?? null) !== $value) {
                $fail("El campo {$attribute} debe ser un entero.");
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

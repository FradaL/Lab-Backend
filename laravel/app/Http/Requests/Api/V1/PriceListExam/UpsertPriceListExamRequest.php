<?php

namespace App\Http\Requests\Api\V1\PriceListExam;

use App\Support\ExamPrice;
use Closure;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\Request;
use Illuminate\Validation\Validator;

final class UpsertPriceListExamRequest
{
    public function __construct(
        private readonly ValidationFactory $validationFactory,
    ) {}

    /** @return array{price: string} */
    public function validated(Request $request): array
    {
        $payload = $this->originalPayload($request);
        $priceToken = $this->priceToken($request);
        $validator = $this->validationFactory->make($payload, [
            'price' => [
                'required',
                function (string $attribute, mixed $value, Closure $fail) use ($priceToken): void {
                    if (ExamPrice::normalize($value, $priceToken) === null) {
                        $fail('El precio debe ser un decimal válido entre 0.00 y 9999999999.99, con máximo dos decimales.');
                    }
                },
            ],
        ]);

        $validator->after(function (Validator $validator) use ($payload): void {
            foreach (array_keys($payload) as $field) {
                if ($field !== 'price') {
                    $validator->errors()->add($field, 'El campo no está permitido.');
                }
            }
        });

        $validator->validate();

        return ['price' => (string) ExamPrice::normalize($payload['price'], $priceToken)];
    }

    /** @return array<string, mixed> */
    private function originalPayload(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);

        return is_array($payload) ? $payload : [];
    }

    private function priceToken(Request $request): ?string
    {
        preg_match_all(
            '/"price"\s*:\s*("(?:\\\\.|[^"\\\\])*"|-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?|true|false|null|\[|\{)/',
            $request->getContent(),
            $matches,
        );

        if (count($matches[1] ?? []) !== 1) {
            return null;
        }

        $token = $matches[1][0];
        if (str_starts_with($token, '"')) {
            $decoded = json_decode($token);

            return is_string($decoded) ? $decoded : null;
        }

        return $token;
    }
}

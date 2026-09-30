<?php

namespace App\Http\Requests\Api\V1\PriceListExam;

use App\Support\ExamPrice;
use Closure;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\Request;
use Illuminate\Validation\Validator;

final class BulkUpsertPriceListExamRequest
{
    public function __construct(
        private readonly ValidationFactory $validationFactory,
    ) {}

    /** @return array{items: list<array{laboratory_exam_id: int, price: string}>} */
    public function validated(Request $request): array
    {
        $raw = $request->getContent();
        $decoded = json_decode($raw, true);
        $decodedObjects = json_decode($raw);
        $payload = is_array($decoded) ? $decoded : [];
        $priceTokens = $this->priceTokens($raw);

        $validator = $this->validationFactory->make($payload, [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array'],
            'items.*.laboratory_exam_id' => [
                'required',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_int($value) || $value < 1) {
                        $fail('El identificador del examen debe ser un entero mayor o igual a 1.');
                    }
                },
            ],
            'items.*.price' => [
                'required',
                function (string $attribute, mixed $value, Closure $fail) use ($priceTokens): void {
                    $index = (int) explode('.', $attribute)[1];
                    if (ExamPrice::normalize($value, $priceTokens[$index] ?? null) === null) {
                        $fail('El precio debe ser un decimal válido entre 0.00 y 9999999999.99, con máximo dos decimales.');
                    }
                },
            ],
        ]);

        $validator->after(function (Validator $validator) use ($payload, $decodedObjects): void {
            foreach (array_keys($payload) as $field) {
                if ($field !== 'items') {
                    $validator->errors()->add($field, 'El campo no está permitido.');
                }
            }

            $rawItems = is_object($decodedObjects) && property_exists($decodedObjects, 'items')
                ? $decodedObjects->items
                : null;
            $seen = [];

            $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];

            foreach ($items as $index => $item) {
                if (! is_array($rawItems) || ! is_object($rawItems[$index] ?? null)) {
                    $validator->errors()->add("items.{$index}", 'Cada item debe ser un objeto.');
                }

                if (! is_array($item)) {
                    continue;
                }

                foreach (array_keys($item) as $field) {
                    if (! in_array($field, ['laboratory_exam_id', 'price'], true)) {
                        $validator->errors()->add("items.{$index}.{$field}", 'El campo no está permitido.');
                    }
                }

                $examId = $item['laboratory_exam_id'] ?? null;
                if (is_int($examId) && $examId >= 1) {
                    if (isset($seen[$examId])) {
                        $validator->errors()->add(
                            "items.{$index}.laboratory_exam_id",
                            'El identificador del examen está duplicado en el batch.',
                        );
                    }
                    $seen[$examId] = true;
                }
            }
        });

        $validator->validate();

        return ['items' => array_map(
            fn (array $item, int $index): array => [
                'laboratory_exam_id' => $item['laboratory_exam_id'],
                'price' => (string) ExamPrice::normalize($item['price'], $priceTokens[$index]),
            ],
            $payload['items'],
            array_keys($payload['items']),
        )];
    }

    /** @return list<string|null> */
    private function priceTokens(string $json): array
    {
        preg_match_all(
            '/"price"\s*:\s*("(?:\\\\.|[^"\\\\])*"|-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?|true|false|null|\[|\{)/',
            $json,
            $matches,
        );

        return array_map(function (string $token): ?string {
            if (! str_starts_with($token, '"')) {
                return $token;
            }

            $decoded = json_decode($token);

            return is_string($decoded) ? $decoded : null;
        }, $matches[1] ?? []);
    }
}

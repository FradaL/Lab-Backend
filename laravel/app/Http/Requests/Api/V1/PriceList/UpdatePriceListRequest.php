<?php

namespace App\Http\Requests\Api\V1\PriceList;

use App\Models\Laboratory;
use App\Models\PriceList;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdatePriceListRequest
{
    /** @var list<string> */
    private const EDITABLE_FIELDS = ['name', 'description', 'currency'];

    public function __construct(
        private readonly ValidationFactory $validationFactory,
    ) {}

    /** @return array<string, mixed> */
    public function validated(Request $request, Laboratory $laboratory, PriceList $priceList): array
    {
        $originalPayload = $this->originalPayload($request);
        $payload = $this->normalize($request->all());
        $nameRules = ['sometimes', 'required', 'string', 'max:75'];

        if (
            array_key_exists('name', $payload)
            && is_string($payload['name'])
            && $payload['name'] !== $priceList->name
        ) {
            $nameRules[] = Rule::unique('price_lists', 'name')
                ->where('laboratory_id', $laboratory->getKey())
                ->ignore($priceList->getKey());
        }

        $validator = $this->validationFactory->make($payload, [
            'name' => $nameRules,
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'currency' => [
                'sometimes',
                'required',
                function (string $attribute, mixed $value, \Closure $fail) use ($originalPayload): void {
                    $original = $originalPayload[$attribute] ?? null;

                    if (! is_string($value) || preg_match('/\A[A-Z]{3}\z/', $value) !== 1 || $original !== $value) {
                        $fail('El campo currency debe contener exactamente tres letras mayúsculas.');
                    }
                },
            ],
        ]);

        $validator->after(function (Validator $validator) use ($payload): void {
            foreach (array_keys($payload) as $field) {
                if (! in_array($field, self::EDITABLE_FIELDS, true)) {
                    $validator->errors()->add($field, 'El campo no está permitido.');
                }
            }

            if (array_intersect(self::EDITABLE_FIELDS, array_keys($payload)) === []) {
                $validator->errors()->add('payload', 'Debe enviar al menos un campo permitido.');
            }
        });

        return $validator->validate();
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalize(array $payload): array
    {
        foreach (['name', 'description'] as $field) {
            if (array_key_exists($field, $payload) && is_string($payload[$field])) {
                $payload[$field] = trim($payload[$field]);
            }
        }

        if (($payload['description'] ?? null) === '') {
            $payload['description'] = null;
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function originalPayload(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);

        return is_array($payload) ? $payload : [];
    }
}

<?php

namespace App\Http\Requests\Api\V1\CommercialClient;

use App\Models\CommercialClient;
use App\Models\Laboratory;
use Closure;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateCommercialClientRequest
{
    /** @var list<string> */
    private const EDITABLE_FIELDS = [
        'name',
        'type',
        'tax_id',
        'phone',
        'email',
        'address',
        'notes',
    ];

    public function __construct(
        private readonly ValidationFactory $validationFactory,
    ) {}

    /** @return array<string, mixed> */
    public function validated(
        Request $request,
        Laboratory $laboratory,
        CommercialClient $commercialClient,
    ): array {
        $originalPayload = $this->originalPayload($request);
        $payload = $this->normalize($request->all());
        $nameRules = ['sometimes', 'required', 'string', 'max:150'];

        if (
            array_key_exists('name', $payload)
            && is_string($payload['name'])
            && $payload['name'] !== $commercialClient->name
        ) {
            $nameRules[] = Rule::unique('commercial_clients', 'name')
                ->where('laboratory_id', $laboratory->getKey())
                ->ignore($commercialClient->getKey());
        }

        $validator = $this->validationFactory->make($payload, [
            'name' => $nameRules,
            'type' => ['sometimes', 'required', $this->strictTypeRule($originalPayload)],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:50'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:150'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
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

    /** @param array<string, mixed> $originalPayload */
    private function strictTypeRule(array $originalPayload): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($originalPayload): void {
            $original = $originalPayload[$attribute] ?? null;

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

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalize(array $payload): array
    {
        foreach (['name', 'tax_id', 'phone', 'email', 'address', 'notes'] as $field) {
            if (array_key_exists($field, $payload) && is_string($payload[$field])) {
                $payload[$field] = trim($payload[$field]);
            }
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

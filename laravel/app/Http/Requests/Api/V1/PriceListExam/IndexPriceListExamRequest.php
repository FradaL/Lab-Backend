<?php

namespace App\Http\Requests\Api\V1\PriceListExam;

use App\Models\PriceListExam;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class IndexPriceListExamRequest
{
    /** @var list<string> */
    private const KNOWN_QUERY_PARAMETERS = [
        'search',
        'status',
        'laboratory_area_id',
        'sample_type_id',
        'sort',
        'direction',
        'per_page',
        'page',
    ];

    public function __construct(
        private readonly ValidationFactory $validationFactory,
    ) {}

    /** @return array<string, mixed> */
    public function validated(Request $request): array
    {
        $payload = $request->query->all();

        if (isset($payload['search']) && is_string($payload['search'])) {
            $payload['search'] = trim($payload['search']);
        }

        $validator = $this->validationFactory->make($payload, [
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'status' => ['sometimes', Rule::in([
                PriceListExam::STATUS_ACTIVE,
                PriceListExam::STATUS_INACTIVE,
            ])],
            'laboratory_area_id' => ['sometimes', 'integer', 'min:1'],
            'sample_type_id' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', Rule::in([
                'exam_code',
                'exam_name',
                'price',
                'created_at',
            ])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $validator->after(function (Validator $validator) use ($payload): void {
            foreach (array_keys($payload) as $parameter) {
                if (! in_array($parameter, self::KNOWN_QUERY_PARAMETERS, true)) {
                    $validator->errors()->add(
                        $parameter,
                        'El parámetro de consulta no está permitido.',
                    );
                }
            }
        });

        return $validator->validate();
    }
}

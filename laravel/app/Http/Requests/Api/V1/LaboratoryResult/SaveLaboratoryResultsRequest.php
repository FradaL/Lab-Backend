<?php

namespace App\Http\Requests\Api\V1\LaboratoryResult;

use App\ClinicalResults\LaboratoryResultService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveLaboratoryResultsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return LaboratoryResultService::inputRules();
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
                $validator->errors()->add('results', 'Unknown input fields.');
            }
            if (! is_int($this->input('revision'))) {
                $validator->errors()->add('revision', 'Use an integer JSON revision.');
            }
            if (is_array($this->input('results')) && ! array_is_list($this->input('results'))) {
                $validator->errors()->add('results', 'Use a JSON list.');
            }
        }];
    }
}

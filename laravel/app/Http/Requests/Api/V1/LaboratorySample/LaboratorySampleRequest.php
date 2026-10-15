<?php

namespace App\Http\Requests\Api\V1\LaboratorySample;

use App\ClinicalExams\LaboratorySampleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class LaboratorySampleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return LaboratorySampleService::inputRules($this->route()->getActionMethod());
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'Unknown input field.');
            }
        }];
    }
}

<?php

namespace App\Http\Requests\Api\V1\ClinicalExam;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ClinicalExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'store' => [
                'code' => ['required', 'string', 'max:30'], 'name' => ['required', 'string', 'max:150'],
                'laboratory_area_id' => ['required', 'integer', 'min:1'],
                'sample_type_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
                'configuration' => ['sometimes', 'array'],
            ],
            'import' => [
                'template_id' => ['required', 'integer', 'min:1'],
                'code' => ['required', 'string', 'max:30'], 'name' => ['required', 'string', 'max:150'],
                'laboratory_area_id' => ['required', 'integer', 'min:1'],
                'sample_type_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
                'sample_type_map' => ['sometimes', 'array', 'max:30'],
                'sample_type_map.*' => ['integer', 'min:1'],
            ],
            'update' => ['revision' => ['required', 'integer', 'min:1'], 'configuration' => ['present', 'array']],
            'publish' => ['revision' => ['required', 'integer', 'min:1']],
            'fork' => ['source_version_id' => ['sometimes', 'nullable', 'integer', 'min:1']],
            'index', 'templates' => ['page' => ['sometimes', 'integer', 'min:1']],
            default => [],
        };
    }

    protected function prepareForValidation(): void
    {
        foreach (['code', 'name'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => trim($this->input($key))]);
            }
        }
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $key) {
                $validator->errors()->add($key, 'Unknown input field.');
            }
            $configuration = $this->input('configuration', []);
            if (is_array($configuration)) {
                foreach (is_array($configuration['sample_requirements'] ?? null) ? $configuration['sample_requirements'] : [] as $i => $requirement) {
                    if (is_array($requirement) && array_key_exists('sample_type_name', $requirement)) {
                        $validator->errors()->add("configuration.sample_requirements.$i.sample_type_name", 'Server-controlled snapshot.');
                    }
                }
            }
        }];
    }
}

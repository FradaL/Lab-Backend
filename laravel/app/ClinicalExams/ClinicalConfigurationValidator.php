<?php

namespace App\ClinicalExams;

use App\Models\Laboratory;
use App\Models\SampleType;
use Illuminate\Support\Facades\Validator;

/** Validates configuration, never interprets patient results. Ages are inclusive completed days. */
final class ClinicalConfigurationValidator
{
    public function errors(array $configuration, Laboratory $laboratory, bool $publishing = false): array
    {
        $presence = $publishing ? 'required' : 'sometimes';
        $rules = [
            'schema_version' => [$presence, 'integer', 'in:1,2'],
            'code' => [$presence, 'string', 'max:30'],
            'name' => [$presence, 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'sample_mode' => [$presence, 'in:none,physical'],
            'sample_requirements' => ['sometimes', 'array', 'list', 'max:30'],
            'sample_requirements.*' => ['array:key,sample_type_id,volume,volume_unit,container,instructions,sample_type_name'],
            'sample_requirements.*.key' => ['required', 'string', 'max:60', 'regex:/^[a-zA-Z0-9_-]+$/', 'distinct:strict'],
            'sample_requirements.*.sample_type_id' => [$publishing ? 'required' : 'sometimes', 'nullable', 'integer', 'min:1'],
            'sample_requirements.*.volume' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'sample_requirements.*.volume_unit' => ['sometimes', 'nullable', 'string', 'max:50'],
            'sample_requirements.*.container' => ['sometimes', 'nullable', 'string', 'max:150'],
            'sample_requirements.*.instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // sample_type_name is a server snapshot, rejected on API writes.
            'sample_requirements.*.sample_type_name' => ['sometimes', 'string', 'max:100'],
            'parameters' => [$presence, 'array', 'list', 'max:100'],
            'parameters.*' => ['array:code,name,type,unit_required,unit,options,references,text_max_length'],
            'parameters.*.code' => [$presence, 'string', 'max:60', 'regex:/^[a-zA-Z0-9_-]+$/', 'distinct:strict'],
            'parameters.*.name' => [$presence, 'string', 'max:150'],
            'parameters.*.type' => [$presence, 'in:numeric,text,qualitative,predefined_options'],
            'parameters.*.unit_required' => ['sometimes', 'boolean'],
            'parameters.*.unit' => ['sometimes', 'nullable', 'string', 'max:50'],
            'parameters.*.text_max_length' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'parameters.*.options' => ['sometimes', 'array', 'list', 'max:100'],
            'parameters.*.options.*' => ['array:code,label'],
            'parameters.*.options.*.code' => ['required', 'string', 'max:60'],
            'parameters.*.options.*.label' => ['required', 'string', 'max:150'],
            'parameters.*.references' => ['sometimes', 'array', 'list', 'max:100'],
            'parameters.*.references.*' => ['array:key,priority,age_min_days,age_max_days,sex,conditions,min,max,text,allowed_options'],
            'parameters.*.references.*.key' => ['required', 'string', 'max:60'],
            'parameters.*.references.*.priority' => ['required', 'integer', 'min:0', 'max:1000000'],
            'parameters.*.references.*.age_min_days' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'parameters.*.references.*.age_max_days' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'parameters.*.references.*.sex' => ['sometimes', 'nullable', 'string', 'max:60'],
            'parameters.*.references.*.conditions' => ['sometimes', 'array', 'max:30'],
            'parameters.*.references.*.conditions.*' => ['string', 'max:150'],
            'parameters.*.references.*.min' => ['sometimes', 'nullable', 'numeric'],
            'parameters.*.references.*.max' => ['sometimes', 'nullable', 'numeric'],
            'parameters.*.references.*.text' => ['sometimes', 'string', 'max:2000'],
            'parameters.*.references.*.allowed_options' => ['sometimes', 'array', 'list', 'min:1', 'max:100'],
            'parameters.*.references.*.allowed_options.*' => ['string', 'max:60', 'distinct:strict'],
        ];
        if ((int) ($configuration['schema_version'] ?? 1) === 2) {
            $rules['sample_requirements.*'] = ['array:key,sample_type_id,volume,volume_unit,container,instructions,sample_type_name,mandatory,additives,collection_conditions,storage_conditions,max_age_minutes,max_transport_minutes'];
            $rules['sample_requirements.*.mandatory'] = ['sometimes', 'boolean'];
            $rules['sample_requirements.*.additives'] = ['sometimes', 'array', 'list', 'max:30'];
            $rules['sample_requirements.*.additives.*'] = ['string', 'max:100', 'distinct:strict'];
            foreach (['collection_conditions', 'storage_conditions'] as $field) {
                $rules["sample_requirements.*.$field"] = ['sometimes', 'array', 'max:30'];
                $rules["sample_requirements.*.$field.*"] = ['string', 'max:150'];
            }
            foreach (['max_age_minutes', 'max_transport_minutes'] as $field) {
                $rules["sample_requirements.*.$field"] = ['sometimes', 'integer', 'min:1', 'max:5256000'];
            }
        }
        $validator = Validator::make($configuration, $rules);
        foreach (array_diff(array_keys($configuration), ['schema_version', 'code', 'name', 'description', 'sample_mode', 'sample_requirements', 'parameters']) as $key) {
            $validator->after(fn ($v) => $v->errors()->add($key, 'Unknown configuration field.'));
        }
        $errors = $validator->errors()->toArray();
        if ($errors !== []) {
            return $errors;
        }
        $add = static function (string $path, string $message) use (&$errors): void {
            $errors[$path][] = $message;
        };
        $requirements = $configuration['sample_requirements'] ?? [];
        foreach ($requirements as $i => $requirement) {
            $id = $requirement['sample_type_id'] ?? null;
            if ($id !== null && ! SampleType::forLaboratory($laboratory)->whereKey($id)->where('status', 'active')->exists()) {
                $add("sample_requirements.$i.sample_type_id", 'An active sample type in this laboratory is required.');
            }
            if ($publishing && $id === null) {
                $add("sample_requirements.$i.sample_type_id", 'Physical requirements need a local sample type.');
            }
            foreach (['collection_conditions', 'storage_conditions'] as $field) {
                if (isset($requirement[$field]) && ($requirement[$field] === [] || array_is_list($requirement[$field]))) {
                    $add("sample_requirements.$i.$field", 'Conditions require a nonempty map of named exact text values.');
                }
            }
            if (isset($requirement['volume']) && ! is_finite((float) $requirement['volume'])) {
                $add("sample_requirements.$i.volume", 'Volume must be finite.');
            }
            if ($publishing && isset($requirement['volume']) && empty($requirement['volume_unit'])) {
                $add("sample_requirements.$i.volume_unit", 'Volume requires an explicit unit.');
            }
        }
        if ($publishing) {
            if (($configuration['sample_mode'] ?? null) === 'physical' && $requirements === []) {
                $add('sample_requirements', 'A physical exam requires at least one sample requirement.');
            }
            if (($configuration['sample_mode'] ?? null) === 'none' && $requirements !== []) {
                $add('sample_requirements', 'A sample-free exam cannot have physical requirements.');
            }
            if (($configuration['parameters'] ?? []) === []) {
                $add('parameters', 'Publication requires at least one parameter.');
            }
        }
        foreach ($configuration['parameters'] ?? [] as $i => $parameter) {
            $path = "parameters.$i";
            $type = $parameter['type'] ?? null;
            $options = $parameter['options'] ?? [];
            $codes = array_column($options, 'code');
            if (count($codes) !== count(array_unique($codes))) {
                $add("$path.options", 'Option codes must be unique within the parameter.');
            }
            if ($publishing && in_array($type, ['qualitative', 'predefined_options'], true) && $options === []) {
                $add("$path.options", 'Categorical parameters require explicit options.');
            }
            if ($options !== [] && ! in_array($type, ['qualitative', 'predefined_options'], true)) {
                $add("$path.options", 'Options are only valid for categorical parameters.');
            }
            if ($publishing && $type === 'numeric' && ! array_key_exists('unit_required', $parameter)) {
                $add("$path.unit_required", 'Explicitly declare whether a numeric parameter needs a unit.');
            }
            if ($publishing && ($parameter['unit_required'] ?? false) && empty($parameter['unit'])) {
                $add("$path.unit", 'A unit is required for this parameter.');
            }
            if ($type !== null && $type !== 'numeric' && (isset($parameter['unit']) || ($parameter['unit_required'] ?? false))) {
                $add("$path.unit", 'Units apply only to numeric parameters.');
            }
            if (isset($parameter['text_max_length']) && $type !== 'text') {
                $add("$path.text_max_length", 'Text limits apply only to text parameters.');
            }
            $references = $parameter['references'] ?? [];
            $keys = array_column($references, 'key');
            if (count($keys) !== count(array_unique($keys))) {
                $add("$path.references", 'Reference keys must be unique within the parameter.');
            }
            foreach ($references as $j => $reference) {
                $refPath = "$path.references.$j";
                if (($reference['age_min_days'] ?? 0) > ($reference['age_max_days'] ?? PHP_INT_MAX)) {
                    $add($refPath, 'Age bounds are inverted.');
                }
                foreach (array_keys($reference['conditions'] ?? []) as $key) {
                    if (! is_string($key) || preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,59}$/', $key) !== 1) {
                        $add("$refPath.conditions", 'Conditions use named keys and exact string values.');
                    }
                }
                foreach (['min', 'max'] as $bound) {
                    if (isset($reference[$bound]) && ! is_finite((float) $reference[$bound])) {
                        $add($refPath, 'Numeric bounds must be finite.');
                    }
                }
                if ($type === 'numeric') {
                    if ($publishing && ! isset($reference['min']) && ! isset($reference['max'])) {
                        $add($refPath, 'A numeric reference requires at least one bound.');
                    }
                    if (isset($reference['min'], $reference['max']) && $reference['min'] > $reference['max']) {
                        $add($refPath, 'Numeric reference bounds are inverted.');
                    }
                    if (isset($reference['text']) || isset($reference['allowed_options'])) {
                        $add($refPath, 'Numeric references only accept numeric bounds.');
                    }
                } elseif ($type === 'text') {
                    if ($publishing && empty($reference['text'])) {
                        $add($refPath, 'Text references require text.');
                    }
                    if (isset($reference['min']) || isset($reference['max']) || isset($reference['allowed_options'])) {
                        $add($refPath, 'Text references only accept text.');
                    }
                } elseif (in_array($type, ['qualitative', 'predefined_options'], true)) {
                    if ($publishing && empty($reference['allowed_options'])) {
                        $add($refPath, 'Categorical references require allowed options.');
                    }
                    if (array_diff($reference['allowed_options'] ?? [], $codes) !== []) {
                        $add($refPath, 'Reference options must belong to this parameter.');
                    }
                    if (isset($reference['min']) || isset($reference['max']) || isset($reference['text'])) {
                        $add($refPath, 'Categorical references only accept allowed options.');
                    }
                }
                if ($publishing) {
                    foreach (array_slice($references, 0, $j) as $earlier) {
                        if ((int) $earlier['priority'] === (int) $reference['priority'] && $this->overlap($earlier, $reference)) {
                            $add($refPath, 'Overlapping references at the same priority are ambiguous.');
                        }
                    }
                }
            }
        }

        return $errors;
    }

    private function overlap(array $left, array $right): bool
    {
        if (($left['age_min_days'] ?? 0) > ($right['age_max_days'] ?? PHP_INT_MAX)
            || ($right['age_min_days'] ?? 0) > ($left['age_max_days'] ?? PHP_INT_MAX)) {
            return false;
        }
        if (isset($left['sex'], $right['sex']) && $left['sex'] !== $right['sex']) {
            return false;
        }
        foreach ($left['conditions'] ?? [] as $key => $value) {
            if (isset($right['conditions'][$key]) && $right['conditions'][$key] !== $value) {
                return false;
            }
        }

        return true;
    }
}

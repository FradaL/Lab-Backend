<?php

namespace App\ClinicalValidation;

use Brick\Math\BigDecimal;

/** Classification belongs to validated clinical evidence, never presentation. */
final class ResultClassification
{
    public function classify(array $results, array $references): array
    {
        $references = array_column($references, null, 'parameter_code');

        return array_map(function (array $result) use ($references): array {
            $reference = $references[$result['parameter_code']] ?? null;
            $rule = ($reference['status'] ?? null) === 'resolved' ? $reference['reference'] : null;
            $status = 'not_classified';
            if ($rule !== null && $result['value_type'] === 'numeric') {
                $value = BigDecimal::of($result['value']);
                if ((isset($rule['critical_min']) && $value->isLessThanOrEqualTo(BigDecimal::of((string) $rule['critical_min'])))
                    || (isset($rule['critical_max']) && $value->isGreaterThanOrEqualTo(BigDecimal::of((string) $rule['critical_max'])))) {
                    $status = 'critical';
                } elseif (isset($rule['min']) && $value->isLessThan(BigDecimal::of((string) $rule['min']))) {
                    $status = 'low';
                } elseif (isset($rule['max']) && $value->isGreaterThan(BigDecimal::of((string) $rule['max']))) {
                    $status = 'high';
                } elseif (isset($rule['min']) || isset($rule['max'])) {
                    $status = 'normal';
                }
            } elseif (isset($rule['allowed_options']) && in_array($result['value_type'], ['qualitative', 'predefined_options'], true)) {
                $status = in_array($result['value'], $rule['allowed_options'], true) ? 'normal' : 'outside_reference';
            }

            return ['parameter_code' => $result['parameter_code'], 'status' => $status];
        }, $results);
    }
}

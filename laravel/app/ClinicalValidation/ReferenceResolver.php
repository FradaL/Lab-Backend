<?php

namespace App\ClinicalValidation;

use App\Models\Patient;
use Carbon\CarbonImmutable;

/** Three-valued applicability, never diagnostic interpretation. */
final class ReferenceResolver
{
    public function resolve(array $parameters, Patient $patient, array $input, int $actorId): array
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $input['reference_date']);
        $needsAge = false;
        $needsSex = false;
        $conditionKeys = [];
        foreach ($parameters as $parameter) {
            foreach ($parameter['references'] ?? [] as $rule) {
                $needsAge = $needsAge || isset($rule['age_min_days']) || isset($rule['age_max_days']);
                $needsSex = $needsSex || isset($rule['sex']);
                $conditionKeys = array_unique([...$conditionKeys, ...array_keys($rule['conditions'] ?? [])]);
            }
        }
        $birth = $needsAge ? $patient->birth_date?->format('Y-m-d') : null;
        $contradiction = $birth !== null && $birth > $input['reference_date'];
        $age = $birth !== null && ! $contradiction ? (int) CarbonImmutable::parse($birth)->diffInDays($date) : null;
        $conditions = [];
        foreach ($conditionKeys as $key) {
            $conditions[$key] = $input['conditions'][$key] ?? null;
        }
        $context = ['reference_date' => $input['reference_date'], 'patient_id' => $patient->id,
            'birth_date' => $birth, 'age_days' => $age, 'sex' => $needsSex ? ($input['sex'] ?? null) : null,
            'conditions' => $conditions, 'sources' => [
                'reference_date' => ['source' => 'validator_declared', 'confirmed_by' => $actorId],
                'age_days' => ['source' => $birth === null ? 'unavailable' : 'patient.birth_date', 'confirmed_by' => ($input['confirm_birth_date'] ?? false) ? $actorId : null],
                'sex' => ['source' => isset($input['sex']) && $needsSex ? 'validator_declared' : 'unavailable', 'confirmed_by' => isset($input['sex']) && $needsSex ? $actorId : null],
                'conditions' => array_map(fn ($key) => ['key' => $key, 'source' => isset($conditions[$key]) ? 'validator_declared' : 'unavailable', 'confirmed_by' => isset($conditions[$key]) ? $actorId : null], $conditionKeys)],
            'missing_relevant_fields' => [], 'conflicts' => $contradiction ? ['birth_date_after_reference_date'] : []];
        $resolved = [];
        $blocked = false;
        foreach ($parameters as $parameter) {
            $rules = $parameter['references'] ?? [];
            $matches = [];
            $unknown = [];
            $missing = [];
            foreach ($rules as $rule) {
                $false = false;
                $absent = [];
                foreach (['age_min_days', 'age_max_days'] as $bound) {
                    if (! isset($rule[$bound])) {
                        continue;
                    }
                    if ($age === null) {
                        $absent[] = 'age_days';
                    } elseif ($bound === 'age_min_days' ? $age < $rule[$bound] : $age > $rule[$bound]) {
                        $false = true;
                    }
                }
                if (isset($rule['sex'])) {
                    if ($context['sex'] === null) {
                        $absent[] = 'sex';
                    } elseif ($context['sex'] !== $rule['sex']) {
                        $false = true;
                    }
                }
                foreach ($rule['conditions'] ?? [] as $key => $value) {
                    if (($conditions[$key] ?? null) === null) {
                        $absent[] = 'conditions.'.$key;
                    } elseif ($conditions[$key] !== $value) {
                        $false = true;
                    }
                }
                if ($false) {
                    continue;
                }
                if ($absent !== []) {
                    $unknown[] = $rule + ['missing_fields' => array_values(array_unique($absent))];
                    $missing = [...$missing, ...$absent];
                } else {
                    $matches[] = $rule;
                }
            }
            $priority = $matches === [] ? -1 : max(array_map(fn ($r) => (int) $r['priority'], $matches));
            $winners = array_values(array_filter($matches, fn ($r) => (int) $r['priority'] === $priority));
            $threat = array_filter($unknown, fn ($r) => (int) $r['priority'] >= $priority);
            $missing = array_merge(...array_map(fn ($r) => $r['missing_fields'], array_values($threat)));
            $status = $rules === [] ? 'not_configured' : ($threat !== [] ? 'insufficient_context' : ($matches === [] ? 'not_applicable' : (count($winners) !== 1 ? 'ambiguous' : 'resolved')));
            if ($contradiction && $needsAge && $rules !== [] && $status === 'insufficient_context') {
                $status = 'conflicting_context';
            }
            $isBlocked = in_array($status, ['insufficient_context', 'ambiguous', 'conflicting_context'], true);
            $blocked = $blocked || $isBlocked;
            $resolved[] = ['parameter_code' => $parameter['code'], 'status' => $status,
                'reference_key' => $status === 'resolved' ? $winners[0]['key'] : null, 'reference' => $status === 'resolved' ? $winners[0] : null,
                'missing_fields' => $isBlocked ? array_values(array_unique($missing)) : [], 'candidate_keys' => $isBlocked ? array_column([...$matches, ...$unknown], 'key') : []];
            if ($isBlocked) {
                $context['missing_relevant_fields'] = array_values(array_unique([...$context['missing_relevant_fields'], ...$missing]));
            }
        }

        return ['context' => $context, 'references' => $resolved, 'blocked' => $blocked];
    }
}

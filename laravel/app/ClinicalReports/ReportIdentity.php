<?php

namespace App\ClinicalReports;

use App\Models\Branch;
use App\Models\Patient;

final class ReportIdentity
{
    public static function snapshot(Patient $patient, Branch $branch, string $source, int $actor): array
    {
        return ['source' => $source, 'recorded_at' => now()->toISOString(), 'recorded_by' => $actor,
            'patient' => ['id' => $patient->id, 'first_names' => $patient->first_names, 'last_names' => $patient->last_names,
                'birth_date' => $patient->birth_date?->format('Y-m-d'), 'affiliation_number' => $patient->affiliation_number],
            'branch' => ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name]];
    }
}

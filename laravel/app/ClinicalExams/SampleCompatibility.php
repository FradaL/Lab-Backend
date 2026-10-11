<?php

namespace App\ClinicalExams;

use App\Models\Laboratory;
use App\Models\LaboratorySample;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** PostgreSQL is the single evaluator for compatibility, current satisfaction and start decisions. */
final class SampleCompatibility
{
    public function candidates(Laboratory $laboratory, LaboratorySample $sample): Collection
    {
        return DB::table('laboratory_order_sample_requirements as r')
            ->join('laboratory_exam_versions as v', 'v.id', '=', 'r.clinical_version_id')
            ->join('laboratory_order_exams as l', 'l.id', '=', 'r.laboratory_order_exam_id')
            ->join('laboratory_orders as o', 'o.id', '=', 'r.laboratory_order_id')
            ->crossJoin('laboratory_samples as s')
            ->where('r.laboratory_id', $laboratory->id)->where('r.laboratory_order_id', $sample->laboratory_order_id)
            ->where('s.id', $sample->id)->orderBy('r.id')
            ->select(['r.id', 'r.laboratory_order_exam_id', 'l.processing_started_at', 's.status as sample_status', 'o.status as order_status'])
            ->selectRaw("sample_compatibility_errors(r.configuration, to_jsonb(s), (v.configuration->>'schema_version')::integer, sample_operation_time()) AS errors")
            ->get()->map(fn ($row) => [
                'sample_requirement_id' => $row->id, 'laboratory_order_exam_id' => $row->laboratory_order_exam_id,
                'compatible' => $row->sample_status !== 'rejected' && in_array($row->order_status, ['pending', 'in_process'], true) && $row->processing_started_at === null && $row->errors === '[]',
                'errors' => array_merge(json_decode($row->errors, true, flags: JSON_THROW_ON_ERROR), $row->sample_status === 'rejected' ? ['sample_rejected'] : [], ! in_array($row->order_status, ['pending', 'in_process'], true) ? ['order_closed'] : [], $row->processing_started_at !== null ? ['line_already_started'] : []),
            ]);
    }

    /** One individually adequate accepted sample per requirement; no pooling or summing. */
    public function qualifyingAssociations(Laboratory $laboratory, int $orderId): Collection
    {
        return $this->assessments($laboratory, $orderId)->filter(fn ($row) => $row->released_at === null && $row->sample_status === 'accepted' && $row->errors === '[]')->values();
    }

    public function assessments(Laboratory $laboratory, int $orderId): Collection
    {
        return DB::table('laboratory_sample_associations as a')
            ->join('laboratory_order_sample_requirements as r', 'r.id', '=', 'a.sample_requirement_id')
            ->join('laboratory_exam_versions as v', 'v.id', '=', 'r.clinical_version_id')
            ->join('laboratory_samples as s', 's.id', '=', 'a.laboratory_sample_id')
            ->where('a.laboratory_id', $laboratory->id)->where('a.laboratory_order_id', $orderId)
            ->orderBy('a.id')->select(['a.id', 'a.sample_requirement_id', 'a.laboratory_sample_id', 'r.laboratory_order_exam_id', 'a.released_at', 's.status as sample_status'])
            ->selectRaw("r.configuration AS requirement_snapshot, jsonb_build_object('id', s.id, 'identifier', s.identifier, 'sample_type_id', s.sample_type_id, 'facts', s.facts, 'collected_at', s.collected_at, 'received_at', s.received_at, 'provenance', s.provenance) AS sample_snapshot, sample_operation_time() AS evaluated_at")
            ->selectRaw("sample_compatibility_errors(r.configuration, to_jsonb(s), (v.configuration->>'schema_version')::integer, sample_operation_time()) AS errors")->get();
    }
}

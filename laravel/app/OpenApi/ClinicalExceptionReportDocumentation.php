<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'ReportClinicalLineState', type: 'object', description: 'Advisory state on clinical-finalization lines. Pending/Incompleto cannot be selected as clinical report lines. Only informative pending identities are archived in partial PDFs. Values require current validation; full issuance still requires explicit order closure.', properties: [
    new OA\Property(property: 'status', type: 'string', enum: ['validated_result', 'not_performed', 'authorized_exception', 'incomplete', 'pending']),
    new OA\Property(property: 'label', type: 'string'),
    new OA\Property(property: 'processing_started', type: 'boolean'),
    new OA\Property(property: 'eligible_line', type: 'boolean'),
    new OA\Property(property: 'blocking_reasons', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'reason', type: ['string', 'null'], description: 'Original clinical reason name only; approval justification and internal evidence are not patient-facing text.'),
    new OA\Property(property: 'reason_reference', type: ['object', 'null'], properties: [new OA\Property(property: 'source', type: 'string', enum: ['global', 'local']), new OA\Property(property: 'reason_id', type: 'integer'), new OA\Property(property: 'code', type: 'string'), new OA\Property(property: 'version_id', type: 'integer'), new OA\Property(property: 'version_number', type: 'integer')]),
])]
#[OA\Schema(schema: 'ReportPendingSnapshotLine', type: 'object', additionalProperties: false, description: 'Internal archived informative identity, separate from selected resolved clinical lines. No results, clinical evidence or internal justifications.', required: ['line_id', 'exam_code', 'exam_name', 'clinical_status', 'processing_started'], properties: [
    new OA\Property(property: 'line_id', type: 'integer'), new OA\Property(property: 'exam_code', type: 'string'), new OA\Property(property: 'exam_name', type: 'string'),
    new OA\Property(property: 'clinical_status', type: 'string', enum: ['incomplete', 'pending']), new OA\Property(property: 'processing_started', type: 'boolean'),
])]
#[OA\Schema(schema: 'ReportLineSnapshotV2', type: 'object', description: 'Internal immutable format-2 snapshot, not exposed by report metadata or public verification. Original BE091 resolution/validation/request/decision fields are retained. Structured exceptions additionally archive their original global/local reason reference, complete original version definition and BE04 evidence-review ID. Generic historical exceptions remain generic.', required: ['snapshot_version', 'clinical_display', 'resolution', 'validation', 'exception'], properties: [
    new OA\Property(property: 'snapshot_version', type: 'integer', enum: [2]),
    new OA\Property(property: 'clinical_display', type: 'object', properties: [new OA\Property(property: 'status', type: 'string', enum: ['validated_result', 'not_performed', 'authorized_exception']), new OA\Property(property: 'label', type: 'string'), new OA\Property(property: 'reason', type: ['string', 'null'])]),
    new OA\Property(property: 'resolution', type: 'object', additionalProperties: true), new OA\Property(property: 'validation', type: ['object', 'null'], additionalProperties: true),
    new OA\Property(property: 'exception', type: ['object', 'null'], properties: [new OA\Property(property: 'reason_reference', type: ['object', 'null'], additionalProperties: true), new OA\Property(property: 'evidence_review_id', type: ['integer', 'null'])], additionalProperties: true),
])]
final class ClinicalExceptionReportDocumentation {}

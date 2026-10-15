<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Clinical Exams', description: 'Local immutable clinical versions. Does not capture or interpret results. Order-line integration is deferred.')]
#[OA\Schema(
    schema: 'ClinicalExamConfiguration', type: 'object', additionalProperties: false,
    description: 'Schema 1 document. Drafts can be incomplete. Publication requires identification, sample mode and typed parameters; equal-priority overlapping references are rejected. See docs/sprint-2/BE-S2-03-configuracion-clinica-versionada.md for complete constraints.',
    properties: [
        new OA\Property(property: 'schema_version', type: 'integer', enum: [1, 2]),
        new OA\Property(property: 'code', type: 'string', maxLength: 30),
        new OA\Property(property: 'name', type: 'string', maxLength: 150),
        new OA\Property(property: 'description', type: ['string', 'null'], maxLength: 5000),
        new OA\Property(property: 'sample_mode', type: 'string', enum: ['none', 'physical']),
        new OA\Property(property: 'sample_requirements', type: 'array', maxItems: 30, items: new OA\Items(ref: '#/components/schemas/ClinicalExamSampleRequirement')),
        new OA\Property(property: 'parameters', type: 'array', maxItems: 100, items: new OA\Items(ref: '#/components/schemas/ClinicalExamParameter')),
    ],
)]
#[OA\Schema(
    schema: 'ClinicalExamParameter', type: 'object', additionalProperties: false,
    properties: [
        new OA\Property(property: 'code', type: 'string', maxLength: 60),
        new OA\Property(property: 'name', type: 'string', maxLength: 150),
        new OA\Property(property: 'type', type: 'string', enum: ['numeric', 'text', 'qualitative', 'predefined_options']),
        new OA\Property(property: 'unit_required', type: 'boolean'),
        new OA\Property(property: 'unit', type: ['string', 'null'], maxLength: 50),
        new OA\Property(property: 'text_max_length', type: 'integer', minimum: 1, maximum: 100000),
        new OA\Property(property: 'options', type: 'array', maxItems: 100, items: new OA\Items(type: 'object', required: ['code', 'label'], properties: [new OA\Property(property: 'code', type: 'string'), new OA\Property(property: 'label', type: 'string')])),
        new OA\Property(property: 'references', type: 'array', maxItems: 100, items: new OA\Items(ref: '#/components/schemas/ClinicalExamReference')),
    ],
)]
#[OA\Schema(
    schema: 'ClinicalExamReference', type: 'object', additionalProperties: false, required: ['key', 'priority'],
    properties: [
        new OA\Property(property: 'key', type: 'string', maxLength: 60),
        new OA\Property(property: 'priority', type: 'integer', minimum: 0, maximum: 1000000),
        new OA\Property(property: 'age_min_days', description: 'Inclusive completed days; null means unbounded.', type: ['integer', 'null'], minimum: 0),
        new OA\Property(property: 'age_max_days', description: 'Inclusive completed days; null means unbounded.', type: ['integer', 'null'], minimum: 0),
        new OA\Property(property: 'sex', description: 'Configured exact code; null is wildcard.', type: ['string', 'null']),
        new OA\Property(property: 'conditions', description: 'Named conditions matched by exact strings using AND.', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
        new OA\Property(property: 'min', type: ['number', 'null']),
        new OA\Property(property: 'max', type: ['number', 'null']),
        new OA\Property(property: 'text', type: 'string', maxLength: 2000),
        new OA\Property(property: 'allowed_options', type: 'array', items: new OA\Items(type: 'string')),
    ],
)]
#[OA\Schema(
    schema: 'ClinicalExamDraftInput', type: 'object', additionalProperties: false, required: ['code', 'name', 'laboratory_area_id'],
    properties: [
        new OA\Property(property: 'code', type: 'string', maxLength: 30),
        new OA\Property(property: 'name', type: 'string', maxLength: 150),
        new OA\Property(property: 'laboratory_area_id', type: 'integer', minimum: 1),
        new OA\Property(property: 'sample_type_id', type: ['integer', 'null'], minimum: 1),
        new OA\Property(property: 'configuration', ref: '#/components/schemas/ClinicalExamConfiguration'),
    ],
)]
#[OA\Schema(
    schema: 'ClinicalExamImportInput', type: 'object', additionalProperties: false, required: ['template_id', 'code', 'name', 'laboratory_area_id'],
    properties: [
        new OA\Property(property: 'template_id', type: 'integer', minimum: 1),
        new OA\Property(property: 'code', type: 'string', maxLength: 30),
        new OA\Property(property: 'name', type: 'string', maxLength: 150),
        new OA\Property(property: 'laboratory_area_id', type: 'integer', minimum: 1),
        new OA\Property(property: 'sample_type_id', type: ['integer', 'null'], minimum: 1),
        new OA\Property(property: 'sample_type_map', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'integer', minimum: 1)),
    ],
)]
#[OA\Schema(
    schema: 'ClinicalExamForkInput', type: 'object', additionalProperties: false,
    properties: [new OA\Property(property: 'source_version_id', type: ['integer', 'null'], minimum: 1)],
)]
#[OA\Schema(
    schema: 'ClinicalExamWriteInput', type: 'object', additionalProperties: false, required: ['revision', 'configuration'],
    properties: [new OA\Property(property: 'revision', type: 'integer', minimum: 1), new OA\Property(property: 'configuration', ref: '#/components/schemas/ClinicalExamConfiguration')],
)]
#[OA\Schema(
    schema: 'ClinicalExamPublishInput', type: 'object', additionalProperties: false, required: ['revision'],
    properties: [new OA\Property(property: 'revision', type: 'integer', minimum: 1)],
)]
#[OA\Schema(
    schema: 'ClinicalExamSampleRequirement', type: 'object', additionalProperties: false,
    properties: [
        new OA\Property(property: 'key', type: 'string', maxLength: 60),
        new OA\Property(property: 'sample_type_id', type: ['integer', 'null'], minimum: 1),
        new OA\Property(property: 'sample_type_name', type: 'string', readOnly: true, description: 'Server snapshot at publication.'),
        new OA\Property(property: 'volume', type: ['number', 'null'], exclusiveMinimum: 0),
        new OA\Property(property: 'volume_unit', type: ['string', 'null'], maxLength: 50),
        new OA\Property(property: 'container', type: ['string', 'null'], maxLength: 150),
        new OA\Property(property: 'instructions', type: ['string', 'null'], maxLength: 2000),
        new OA\Property(property: 'mandatory', type: 'boolean', default: true, description: 'Schema 2 only; schema 1 requirements are mandatory.'),
        new OA\Property(property: 'additives', type: 'array', items: new OA\Items(type: 'string'), description: 'Schema 2; exact additive set, ignoring ordering; extra or unknown additives block compatibility.'),
        new OA\Property(property: 'collection_conditions', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string'), description: 'Schema 2; nonempty named map, exact values.'),
        new OA\Property(property: 'storage_conditions', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string'), description: 'Schema 2; nonempty named map, exact values.'),
        new OA\Property(property: 'max_age_minutes', type: 'integer', minimum: 1, description: 'Schema 2; inclusive lifetime from known collection to evaluation/start.'),
        new OA\Property(property: 'max_transport_minutes', type: 'integer', minimum: 1, description: 'Schema 2; inclusive maximum collection-to-reception duration.'),
    ],
)]
#[OA\Schema(
    schema: 'ClinicalExamVersion', type: 'object',
    required: ['id', 'laboratory_exam_id', 'number', 'revision', 'status', 'configuration'],
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'laboratory_exam_id', type: 'integer'),
        new OA\Property(property: 'number', type: 'integer', minimum: 1),
        new OA\Property(property: 'revision', type: 'integer', minimum: 1),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'published']),
        new OA\Property(property: 'base_version_id', type: ['integer', 'null']),
        new OA\Property(property: 'configuration', ref: '#/components/schemas/ClinicalExamConfiguration'),
        new OA\Property(property: 'configuration_hash', type: ['string', 'null'], maxLength: 64),
        new OA\Property(property: 'published_at', type: ['string', 'null'], format: 'date-time'),
        new OA\Property(property: 'published_by', type: ['integer', 'null']),
        new OA\Property(property: 'created_at', type: ['string', 'null'], format: 'date-time'),
        new OA\Property(property: 'updated_at', type: ['string', 'null'], format: 'date-time'),
    ],
)]
#[OA\Schema(
    schema: 'ClinicalExamVersionResponse', type: 'object', required: ['data'],
    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ClinicalExamVersion')],
)]
#[OA\Schema(
    schema: 'ClinicalExamOrderConfiguration', type: 'object',
    required: ['schema_version', 'laboratory_exam_id', 'clinical_version_id', 'clinical_version_number', 'configuration_hash', 'configuration', 'result_context_contract'],
    properties: [
        new OA\Property(property: 'schema_version', type: 'integer', enum: [1]),
        new OA\Property(property: 'laboratory_exam_id', type: 'integer'),
        new OA\Property(property: 'clinical_version_id', type: 'integer'),
        new OA\Property(property: 'clinical_version_number', type: 'integer'),
        new OA\Property(property: 'configuration_hash', type: 'string', minLength: 64, maxLength: 64),
        new OA\Property(property: 'configuration', ref: '#/components/schemas/ClinicalExamConfiguration'),
        new OA\Property(property: 'result_context_contract', type: 'object', properties: [
            new OA\Property(property: 'capture_at', type: 'string', enum: ['interpretation_or_validation']),
            new OA\Property(property: 'required_snapshot_fields', type: 'array', items: new OA\Items(type: 'string')),
            new OA\Property(property: 'priority_rule', type: 'string', enum: ['highest_matching_priority']),
            new OA\Property(property: 'missing_context_rule', type: 'string', enum: ['authorized_audited_resolution']),
            new OA\Property(property: 'no_match_or_tie_rule', type: 'string', enum: ['authorized_audited_resolution']),
        ]),
    ],
)]
class ClinicalExamDocumentation {}

<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'SampleFacts', type: 'object', additionalProperties: false, properties: [
    new OA\Property(property: 'container', type: 'string', maxLength: 150),
    new OA\Property(property: 'additives', type: 'array', items: new OA\Items(type: 'string', maxLength: 100)),
    new OA\Property(property: 'volume', type: ['number', 'string'], description: 'Positive finite decimal; response uses canonical decimal text without binary rounding.'),
    new OA\Property(property: 'volume_unit', type: 'string', maxLength: 50),
    new OA\Property(property: 'collection_conditions', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
    new OA\Property(property: 'storage_conditions', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
])]
#[OA\Schema(schema: 'SampleContext', type: 'object', additionalProperties: false, properties: [
    new OA\Property(property: 'external_center_name', type: 'string', maxLength: 200),
    new OA\Property(property: 'collector_description', type: 'string', maxLength: 200),
    new OA\Property(property: 'notes', type: 'string', maxLength: 1000),
])]
#[OA\Schema(schema: 'SampleCollectionInput', type: 'object', additionalProperties: false,
    required: ['idempotency_key', 'sample_type_id', 'collected_at', 'collection_branch_id'], properties: [
        new OA\Property(property: 'idempotency_key', type: 'string', maxLength: 100, description: 'Persistent tenant-wide key; changed order/operation/input conflicts.'),
        new OA\Property(property: 'sample_type_id', type: 'integer', minimum: 1),
        new OA\Property(property: 'collected_at', type: 'string', format: 'date-time', description: 'Known actual time, explicit UTC offset; no future dates.'),
        new OA\Property(property: 'collected_by', type: 'integer', minimum: 1, description: 'Known local collector when reporting on behalf of another user; otherwise the authenticated collector.'),
        new OA\Property(property: 'collection_branch_id', type: 'integer', minimum: 1),
        new OA\Property(property: 'facts', ref: '#/components/schemas/SampleFacts'),
        new OA\Property(property: 'context', ref: '#/components/schemas/SampleContext'),
    ])]
#[OA\Schema(schema: 'SampleExternalReceptionInput', type: 'object', additionalProperties: false,
    required: ['idempotency_key', 'sample_type_id', 'provenance', 'received_at', 'receiving_branch_id'], properties: [
        new OA\Property(property: 'idempotency_key', type: 'string', maxLength: 100),
        new OA\Property(property: 'sample_type_id', type: 'integer', minimum: 1),
        new OA\Property(property: 'provenance', type: 'string', enum: ['internal', 'patient_provided', 'external_center']),
        new OA\Property(property: 'collected_by', type: ['integer', 'null'], minimum: 1, description: 'Internal provenance only; never inferred from the receiver.'),
        new OA\Property(property: 'collection_branch_id', type: ['integer', 'null'], minimum: 1, description: 'Internal provenance only; unknown collection context stays null.'),
        new OA\Property(property: 'collected_at', type: ['string', 'null'], format: 'date-time'),
        new OA\Property(property: 'received_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'receiving_branch_id', type: 'integer', minimum: 1),
        new OA\Property(property: 'facts', ref: '#/components/schemas/SampleFacts'),
        new OA\Property(property: 'context', ref: '#/components/schemas/SampleContext'),
    ])]
#[OA\Schema(schema: 'SampleReceptionInput', type: 'object', additionalProperties: false, required: ['received_at', 'receiving_branch_id'], properties: [
    new OA\Property(property: 'facts', ref: '#/components/schemas/SampleFacts', description: 'May complete unknown collection observations; previously recorded values cannot change.'),
    new OA\Property(property: 'received_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'receiving_branch_id', type: 'integer', minimum: 1),
])]
#[OA\Schema(schema: 'SampleAssociationInput', type: 'object', additionalProperties: false, required: ['sample_requirement_ids'], properties: [
    new OA\Property(property: 'sample_requirement_ids', type: 'array', minItems: 1, maxItems: 100, uniqueItems: true, items: new OA\Items(type: 'integer', minimum: 1)),
])]
#[OA\Schema(schema: 'SampleReasonInput', type: 'object', additionalProperties: false, required: ['reason'], properties: [new OA\Property(property: 'reason', type: 'string', minLength: 1, maxLength: 1000)])]
#[OA\Schema(schema: 'SampleEmptyInput', type: 'object', additionalProperties: false)]
#[OA\Schema(schema: 'PhysicalSample', type: 'object', required: ['id', 'identifier', 'laboratory_order_id', 'sample_type_id', 'status', 'provenance', 'facts', 'collection', 'reception', 'events', 'associations'], properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(property: 'identifier', type: 'string', minLength: 26, maxLength: 26, description: 'Immutable backend ULID, globally unique and barcode-compatible.'),
    new OA\Property(property: 'laboratory_order_id', type: 'integer'),
    new OA\Property(property: 'sample_type_id', type: 'integer'),
    new OA\Property(property: 'status', type: 'string', enum: ['collected', 'received', 'accepted', 'rejected']),
    new OA\Property(property: 'provenance', type: 'string', enum: ['internal', 'patient_provided', 'external_center']),
    new OA\Property(property: 'facts', ref: '#/components/schemas/SampleFacts'),
    new OA\Property(property: 'collection', ref: '#/components/schemas/SampleEventContext'),
    new OA\Property(property: 'reception', ref: '#/components/schemas/SampleEventContext'),
    new OA\Property(property: 'events', type: 'array', items: new OA\Items(ref: '#/components/schemas/SampleHistoryEvent')),
    new OA\Property(property: 'associations', type: 'array', items: new OA\Items(ref: '#/components/schemas/SampleAssociation')),
])]
#[OA\Schema(schema: 'SampleEventContext', type: 'object', properties: [
    new OA\Property(property: 'occurred_at', type: ['string', 'null'], format: 'date-time'),
    new OA\Property(property: 'branch_id', type: ['integer', 'null']),
    new OA\Property(property: 'actor_id', type: ['integer', 'null'], description: 'Actual internal collector or receiver; external collector remains null.'),
])]
#[OA\Schema(schema: 'SampleHistoryEvent', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'event', type: 'string', enum: ['collected', 'received', 'accepted', 'rejected', 'associated', 'released']),
    new OA\Property(property: 'actor_id', type: 'integer', description: 'User who recorded the event; not an inferred external collector.'),
    new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time'), new OA\Property(property: 'recorded_at', type: 'string', format: 'date-time'), new OA\Property(property: 'context', type: 'object'),
])]
#[OA\Schema(schema: 'SampleAssociation', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'sample_requirement_id', type: 'integer'),
    new OA\Property(property: 'associated_by', type: 'integer'), new OA\Property(property: 'associated_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'released_by', type: ['integer', 'null']), new OA\Property(property: 'released_at', type: ['string', 'null'], format: 'date-time'), new OA\Property(property: 'release_reason', type: ['string', 'null']),
])]
#[OA\Schema(schema: 'SampleResponse', type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PhysicalSample')])]
#[OA\Schema(schema: 'SampleCollectionResponse', type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PhysicalSample')), new OA\Property(property: 'links', type: 'object'), new OA\Property(property: 'meta', type: 'object')])]
#[OA\Schema(schema: 'SampleCandidateResponse', type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object', properties: [
    new OA\Property(property: 'sample_requirement_id', type: 'integer'), new OA\Property(property: 'laboratory_order_exam_id', type: 'integer'), new OA\Property(property: 'compatible', type: 'boolean'), new OA\Property(property: 'errors', type: 'array', items: new OA\Items(type: 'string')),
]))])]
#[OA\Schema(schema: 'SampleConflict', type: 'object', properties: [new OA\Property(property: 'code', type: 'string'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'errors', type: ['object', 'array'], items: new OA\Items(type: 'string'))])]
#[OA\Schema(schema: 'OrderExamStartResponse', type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'operational_status', type: 'string', enum: ['started']), new OA\Property(property: 'processing_started_at', type: 'string', format: 'date-time'), new OA\Property(property: 'processing_started_by', type: 'integer')])])]
#[OA\Schema(schema: 'OrderOperationalResponse', type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [
    new OA\Property(property: 'laboratory_order_id', type: 'integer'), new OA\Property(property: 'order_number', type: 'string'), new OA\Property(property: 'status', type: 'string'),
    new OA\Property(property: 'order', ref: '#/components/schemas/LaboratoryOrder'),
    new OA\Property(property: 'samples', type: 'array', items: new OA\Items(ref: '#/components/schemas/PhysicalSample')),
    new OA\Property(property: 'exams', type: 'array', items: new OA\Items(ref: '#/components/schemas/OrderExamOperational')),
])])]
#[OA\Schema(schema: 'OrderExamOperational', type: 'object', properties: [
    new OA\Property(property: 'can_start', type: 'boolean'),
    new OA\Property(property: 'result_capture', ref: '#/components/schemas/ClinicalResultProgress'),
    new OA\Property(property: 'processing_dependencies', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'association_id', type: 'integer'), new OA\Property(property: 'decision', type: 'object', description: 'Immutable historical configuration, observed facts and evaluation timestamp used at start.')])),
    new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'clinical_version_id', type: ['integer', 'null']), new OA\Property(property: 'clinical_configuration_source', type: 'string'),
    new OA\Property(property: 'operational_status', type: 'string', enum: ['pending_samples', 'ready', 'started']), new OA\Property(property: 'blocking_requirement_ids', type: 'array', items: new OA\Items(type: 'integer')),
    new OA\Property(property: 'blocking_reasons', type: 'array', items: new OA\Items(type: 'string')), new OA\Property(property: 'processing_started_at', type: ['string', 'null'], format: 'date-time'), new OA\Property(property: 'processing_started_by', type: ['integer', 'null']),
    new OA\Property(property: 'sample_requirements', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'requirement_key', type: 'string'), new OA\Property(property: 'mandatory', type: 'boolean'), new OA\Property(property: 'status', type: 'string', enum: ['pending', 'satisfied']), new OA\Property(property: 'configuration', ref: '#/components/schemas/ClinicalExamSampleRequirement'), new OA\Property(property: 'qualifying_association_ids', type: 'array', items: new OA\Items(type: 'integer')), new OA\Property(property: 'association_evaluations', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'association_id', type: 'integer'), new OA\Property(property: 'sample_id', type: 'integer'), new OA\Property(property: 'active', type: 'boolean'), new OA\Property(property: 'sample_status', type: 'string'), new OA\Property(property: 'compatibility_errors', type: 'array', items: new OA\Items(type: 'string'))]))])),
])]
final class PhysicalSampleDocumentation {}

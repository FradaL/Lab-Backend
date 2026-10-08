<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Dashboard',
    description: 'Resumen operativo diario del laboratorio actual.',
)]
#[OA\Schema(
    schema: 'DashboardContext',
    required: ['date', 'timezone', 'currency', 'generated_at'],
    properties: [
        new OA\Property(property: 'date', type: 'string', format: 'date', example: '2026-10-08'),
        new OA\Property(property: 'timezone', type: 'string', example: 'America/Guatemala'),
        new OA\Property(property: 'currency', type: 'string', example: 'GTQ'),
        new OA\Property(property: 'generated_at', type: 'string', format: 'date-time', example: '2026-10-08T08:32:00-06:00'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'DashboardComparisonMetric',
    required: ['count', 'previous_count', 'change_percentage'],
    properties: [
        new OA\Property(property: 'count', type: 'integer', minimum: 0, example: 48),
        new OA\Property(property: 'previous_count', type: 'integer', minimum: 0, example: 43),
        new OA\Property(property: 'change_percentage', type: ['number', 'null'], format: 'float', example: 11.63),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'DashboardPendingOrdersMetric',
    required: ['count'],
    properties: [
        new OA\Property(property: 'count', type: 'integer', minimum: 0, example: 12),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'DashboardMetrics',
    required: ['orders', 'attended_patients', 'pending_orders'],
    properties: [
        new OA\Property(property: 'orders', ref: '#/components/schemas/DashboardComparisonMetric'),
        new OA\Property(property: 'attended_patients', ref: '#/components/schemas/DashboardComparisonMetric'),
        new OA\Property(property: 'pending_orders', ref: '#/components/schemas/DashboardPendingOrdersMetric'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'DashboardActivityPatient',
    required: ['id', 'first_names', 'last_names'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 52),
        new OA\Property(property: 'first_names', type: 'string', example: 'María'),
        new OA\Property(property: 'last_names', type: 'string', example: 'Rodríguez'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'DashboardActivityOrder',
    required: ['id', 'code', 'status'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 128),
        new OA\Property(property: 'code', type: 'string', example: 'ORD-1028'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'in_process', 'completed', 'cancelled']),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'DashboardRecentActivity',
    required: ['id', 'type', 'occurred_at', 'patient', 'order'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 918),
        new OA\Property(property: 'type', type: 'string', enum: ['order.created', 'order.status_changed']),
        new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time', example: '2026-10-08T08:24:00-06:00'),
        new OA\Property(property: 'patient', ref: '#/components/schemas/DashboardActivityPatient'),
        new OA\Property(property: 'order', ref: '#/components/schemas/DashboardActivityOrder'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'DashboardData',
    required: ['context', 'metrics', 'recent_activity'],
    properties: [
        new OA\Property(property: 'context', ref: '#/components/schemas/DashboardContext'),
        new OA\Property(property: 'metrics', ref: '#/components/schemas/DashboardMetrics'),
        new OA\Property(
            property: 'recent_activity',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/DashboardRecentActivity'),
            maxItems: 4,
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'DashboardResponse',
    required: ['data'],
    properties: [
        new OA\Property(property: 'data', ref: '#/components/schemas/DashboardData'),
    ],
    type: 'object',
)]
final class DashboardDocumentation
{
    // OpenAPI schemas for the daily dashboard overview.
}

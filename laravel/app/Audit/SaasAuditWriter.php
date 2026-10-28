<?php

namespace App\Audit;

use App\Models\GlobalClinicalReason;
use App\Models\GlobalClinicalReasonImpact;
use App\Models\GlobalClinicalReasonVersion;
use App\Models\SaasAuditLog;
use App\Models\User;
use Illuminate\Support\Str;

final class SaasAuditWriter
{
    public function recordImpact(GlobalClinicalReasonImpact $impact, User $actor): SaasAuditLog
    {
        return SaasAuditLog::create([
            'operation_id' => (string) Str::uuid(), 'actor_type' => 'user', 'actor_id' => $actor->id,
            'action' => 'saas.clinical_reasons.impact_declared', 'source' => 'service', 'result' => 'succeeded',
            'capability_code' => 'saas.clinical_reasons.classify_impact', 'justification' => $impact->justification,
            'global_impact_declaration_id' => $impact->id,
            'old_values' => ['previous_id' => $impact->previous_id],
            'new_values' => ['impact_id' => $impact->id, 'reason_id' => $impact->reason_id, 'version_id' => $impact->version_id, 'revision' => $impact->revision, 'classification' => $impact->classification],
        ])->refresh();
    }

    public function recordGlobalReason(User $actor, GlobalClinicalReason $reason, GlobalClinicalReasonVersion $version, ?GlobalClinicalReasonVersion $previous, string $capability, string $action): SaasAuditLog
    {
        return SaasAuditLog::create([
            'operation_id' => (string) Str::uuid(),
            'actor_type' => 'user', 'actor_id' => $actor->id, 'actor_label' => null,
            'affected_user_id' => null, 'capability_code' => $capability, 'action' => $action,
            'source' => 'service', 'result' => 'succeeded', 'justification' => $version->change_reason,
            'global_reason_id' => $reason->id, 'global_reason_version_id' => $version->id,
            'previous_global_reason_version_id' => $previous?->id,
            'old_values' => $previous === null ? null : ['version_id' => $previous->id, 'number' => $previous->number, 'is_active' => $previous->is_active],
            'new_values' => ['version_id' => $version->id, 'number' => $version->number, 'is_active' => $version->is_active],
        ])->refresh();
    }

    public function record(?User $actor, User $target, string $capability, string $action, string $reason, ?array $oldValues = null): SaasAuditLog
    {
        return SaasAuditLog::create([
            'operation_id' => (string) Str::uuid(),
            'actor_type' => $actor === null ? 'system' : 'user',
            'actor_id' => $actor?->id,
            'actor_label' => $actor === null ? 'SaasAccessBootstrapSeeder' : null,
            'affected_user_id' => $target->id,
            'capability_code' => $capability,
            'action' => $action,
            'source' => $actor === null ? 'bootstrap' : 'service',
            'result' => 'succeeded',
            'justification' => $reason,
            'old_values' => $oldValues,
            'new_values' => ['capability' => $capability, 'active' => $action === 'saas.access.granted'],
        ])->refresh();
    }
}

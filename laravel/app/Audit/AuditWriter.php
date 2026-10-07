<?php

namespace App\Audit;

use App\Models\AuditLog;
use App\Models\Laboratory;
use App\Models\User;
use InvalidArgumentException;

final class AuditWriter
{
    public function record(Laboratory $laboratory, ?User $actor, AuditEvent $event): AuditLog
    {
        if (! $laboratory->exists) {
            throw new InvalidArgumentException('The audit laboratory must be persisted.');
        }

        if ($actor !== null && ! $actor->exists) {
            throw new InvalidArgumentException('The audit actor must be persisted.');
        }

        $auditLog = new AuditLog([
            'event' => $event->event,
            'auditable_type' => $event->subjectType,
            'auditable_id' => $event->subjectId,
            'old_values' => $event->oldValues,
            'new_values' => $event->newValues,
            'metadata' => $event->metadata,
        ]);
        $auditLog->laboratory()->associate($laboratory);
        $auditLog->actor()->associate($actor);
        $auditLog->save();

        return $auditLog;
    }
}

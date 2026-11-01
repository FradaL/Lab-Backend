<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LaboratorySampleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'identifier' => $this->identifier, 'laboratory_order_id' => $this->laboratory_order_id,
            'sample_type' => $this->whenLoaded('sampleType', fn () => ['id' => $this->sampleType->id, 'name' => $this->sampleType->name]),
            'sample_type_id' => $this->sample_type_id, 'status' => $this->status, 'provenance' => $this->provenance,
            'collection' => ['occurred_at' => $this->collected_at?->toISOString(), 'branch_id' => $this->collection_branch_id, 'branch_name' => $this->whenLoaded('collectionBranch', fn () => $this->collectionBranch?->name), 'actor_id' => $this->collected_by, 'actor' => $this->whenLoaded('collectedBy', fn () => $this->actorSummary($this->collectedBy))],
            'reception' => ['occurred_at' => $this->received_at?->toISOString(), 'branch_id' => $this->receiving_branch_id, 'branch_name' => $this->whenLoaded('receivingBranch', fn () => $this->receivingBranch?->name), 'actor_id' => $this->received_by, 'actor' => $this->whenLoaded('receivedBy', fn () => $this->actorSummary($this->receivedBy))],
            'facts' => (object) $this->facts,
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event) => [
                'id' => $event->id, 'event' => $event->event, 'actor_id' => $event->actor_id,
                'actor' => $event->relationLoaded('actor') ? $this->actorSummary($event->actor) : null,
                'occurred_at' => $event->occurred_at->toISOString(), 'recorded_at' => $event->recorded_at->toISOString(), 'context' => (object) $event->context,
            ])),
            'associations' => $this->whenLoaded('associations', fn () => $this->associations->map(fn ($association) => [
                'id' => $association->id, 'sample_requirement_id' => $association->sample_requirement_id,
                'associated_by_user' => $association->relationLoaded('associatedBy') ? $this->actorSummary($association->associatedBy) : null,
                'released_by_user' => $association->relationLoaded('releasedBy') ? $this->actorSummary($association->releasedBy) : null,
                'associated_by' => $association->associated_by, 'associated_at' => $association->associated_at->toISOString(),
                'released_by' => $association->released_by, 'released_at' => $association->released_at?->toISOString(), 'release_reason' => $association->release_reason,
            ])),
        ];
    }

    private function actorSummary(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'name' => $user->name];
    }
}

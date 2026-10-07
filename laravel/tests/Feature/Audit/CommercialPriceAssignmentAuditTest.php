<?php

namespace Tests\Feature\Audit;

use App\Audit\CommercialAuditEvents;
use App\Models\AuditLog;
use App\Models\CommercialClient;
use App\Models\CommercialClientPriceList;
use App\Models\Laboratory;
use App\Models\PriceList;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CommercialPriceAssignmentAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_update_status_and_noops_have_exact_assignment_history(): void
    {
        [$actor, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->for($laboratory)->create();
        $originalList = PriceList::factory()->for($laboratory)->create();
        $newList = PriceList::factory()->for($laboratory)->create();

        $response = $this->request($actor, $laboratory)
            ->postJson("/api/v1/commercial-clients/{$client->id}/price-list-assignments", [
                'price_list_id' => $originalList->id,
                'starts_at' => '2026-01-01',
                'ends_at' => null,
            ])->assertCreated();

        $assignmentId = $response->json('data.id');
        $created = AuditLog::query()->sole();
        $this->assertSame(CommercialAuditEvents::COMMERCIAL_ASSIGNMENT_CREATED, $created->event);
        $this->assertSame(CommercialAuditEvents::SUBJECT_COMMERCIAL_ASSIGNMENT, $created->auditable_type);
        $this->assertSame($assignmentId, $created->auditable_id);
        $this->assertSame($actor->id, $created->user_id);
        $this->assertSame($laboratory->id, $created->laboratory_id);
        $this->assertEquals([
            'commercial_client_id' => $client->id,
            'price_list_id' => $originalList->id,
            'starts_at' => '2026-01-01',
            'ends_at' => null,
            'status' => CommercialClientPriceList::STATUS_ACTIVE,
        ], $created->new_values);

        $uri = "/api/v1/commercial-clients/{$client->id}/price-list-assignments/{$assignmentId}";
        $this->request($actor, $laboratory)->patchJson($uri, [
            'price_list_id' => $newList->id,
            'starts_at' => '2026-02-01',
            'ends_at' => '2026-12-31',
        ])->assertOk();

        $updated = AuditLog::query()->latest('id')->firstOrFail();
        $this->assertSame(CommercialAuditEvents::COMMERCIAL_ASSIGNMENT_UPDATED, $updated->event);
        $this->assertEquals([
            'price_list_id' => $originalList->id,
            'starts_at' => '2026-01-01',
            'ends_at' => null,
        ], $updated->old_values);
        $this->assertEquals([
            'price_list_id' => $newList->id,
            'starts_at' => '2026-02-01',
            'ends_at' => '2026-12-31',
        ], $updated->new_values);

        $this->request($actor, $laboratory)->patchJson($uri, [
            'price_list_id' => $newList->id,
            'starts_at' => '2026-02-01',
            'ends_at' => '2026-12-31',
        ])->assertOk();
        $this->assertDatabaseCount('audit_logs', 2);

        $this->request($actor, $laboratory)
            ->patchJson("{$uri}/status", ['status' => CommercialClientPriceList::STATUS_INACTIVE])
            ->assertOk();

        $status = AuditLog::query()->latest('id')->firstOrFail();
        $this->assertSame(CommercialAuditEvents::COMMERCIAL_ASSIGNMENT_STATUS_CHANGED, $status->event);
        $this->assertEquals(['status' => CommercialClientPriceList::STATUS_ACTIVE], $status->old_values);
        $this->assertEquals(['status' => CommercialClientPriceList::STATUS_INACTIVE], $status->new_values);

        $this->request($actor, $laboratory)
            ->patchJson("{$uri}/status", ['status' => CommercialClientPriceList::STATUS_INACTIVE])
            ->assertOk();
        $this->assertDatabaseCount('audit_logs', 3);
    }

    public function test_cross_tenant_and_actor_spoofing_write_no_assignment_audit(): void
    {
        $actor = User::factory()->create();
        [, $laboratory] = $this->activeTenant($actor);
        [, $foreignLaboratory] = $this->activeTenant($actor);
        $foreignClient = CommercialClient::factory()->for($foreignLaboratory)->create();
        $foreignList = PriceList::factory()->for($foreignLaboratory)->create();

        $this->request($actor, $laboratory)
            ->postJson("/api/v1/commercial-clients/{$foreignClient->id}/price-list-assignments", [
                'price_list_id' => $foreignList->id,
                'starts_at' => '2026-01-01',
            ])->assertNotFound();

        $client = CommercialClient::factory()->for($laboratory)->create();
        $priceList = PriceList::factory()->for($laboratory)->create();
        $this->request($actor, $laboratory)
            ->postJson("/api/v1/commercial-clients/{$client->id}/price-list-assignments", [
                'price_list_id' => $priceList->id,
                'starts_at' => '2026-01-01',
                'created_by' => User::factory()->create()->id,
            ])->assertUnprocessable()->assertJsonValidationErrors('created_by');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_audit_failure_rolls_back_assignment_creation(): void
    {
        [$actor, $laboratory] = $this->activeTenant();
        $client = CommercialClient::factory()->for($laboratory)->create();
        $priceList = PriceList::factory()->for($laboratory)->create();
        AuditLog::creating(static fn (): never => throw new RuntimeException('audit unavailable'));
        $this->withoutExceptionHandling();

        try {
            $this->request($actor, $laboratory)
                ->postJson("/api/v1/commercial-clients/{$client->id}/price-list-assignments", [
                    'price_list_id' => $priceList->id,
                    'starts_at' => '2026-01-01',
                ]);
            $this->fail('The audit failure was not propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        } finally {
            AuditLog::flushEventListeners();
        }

        $this->assertDatabaseCount('commercial_client_price_lists', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    /** @return array{User, Laboratory} */
    private function activeTenant(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ]);

        return [$user, $laboratory];
    }

    private function request(User $user, Laboratory $laboratory): static
    {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id);
    }
}

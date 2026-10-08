<?php

namespace Tests\Feature\Audit;

use App\Audit\CommercialAuditEvents;
use App\Models\AuditLog;
use App\Models\Laboratory;
use App\Models\PriceList;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PriceListCommercialAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_records_explicit_commercial_state_with_authenticated_actor_and_tenant(): void
    {
        [$actor, $laboratory] = $this->activeTenant();

        $response = $this->request($actor, $laboratory)
            ->postJson('/api/v1/price-lists', [
                'name' => 'Particular',
                'description' => 'Tarifa directa',
                'currency' => 'GTQ',
            ])->assertCreated();

        $log = AuditLog::query()->sole();
        $this->assertSame(CommercialAuditEvents::PRICE_LIST_CREATED, $log->event);
        $this->assertSame(CommercialAuditEvents::SUBJECT_PRICE_LIST, $log->auditable_type);
        $this->assertSame($response->json('data.id'), $log->auditable_id);
        $this->assertSame($laboratory->id, $log->laboratory_id);
        $this->assertSame($actor->id, $log->user_id);
        $this->assertNull($log->old_values);
        $this->assertEquals([
            'name' => 'Particular',
            'description' => 'Tarifa directa',
            'currency' => 'GTQ',
            'status' => PriceList::STATUS_ACTIVE,
            'is_default' => false,
        ], $log->new_values);
        $this->assertNull($log->metadata);
    }

    public function test_update_and_status_record_exact_deltas_while_noops_record_nothing(): void
    {
        [$actor, $laboratory] = $this->activeTenant();
        $priceList = PriceList::factory()->for($laboratory)->create([
            'name' => 'Particular',
            'description' => null,
            'currency' => 'GTQ',
            'status' => PriceList::STATUS_ACTIVE,
        ]);

        $this->request($actor, $laboratory)
            ->patchJson("/api/v1/price-lists/{$priceList->id}", [
                'name' => 'Particular 2026',
                'currency' => 'GTQ',
            ])->assertOk();

        $updated = AuditLog::query()->sole();
        $this->assertSame(CommercialAuditEvents::PRICE_LIST_UPDATED, $updated->event);
        $this->assertEquals(['name' => 'Particular'], $updated->old_values);
        $this->assertEquals(['name' => 'Particular 2026'], $updated->new_values);

        $this->request($actor, $laboratory)
            ->patchJson("/api/v1/price-lists/{$priceList->id}", ['name' => 'Particular 2026'])
            ->assertOk();
        $this->assertDatabaseCount('audit_logs', 1);

        $this->request($actor, $laboratory)
            ->patchJson("/api/v1/price-lists/{$priceList->id}/status", ['status' => PriceList::STATUS_INACTIVE])
            ->assertOk();

        $status = AuditLog::query()->latest('id')->firstOrFail();
        $this->assertSame(CommercialAuditEvents::PRICE_LIST_STATUS_CHANGED, $status->event);
        $this->assertEquals(['status' => PriceList::STATUS_ACTIVE], $status->old_values);
        $this->assertEquals(['status' => PriceList::STATUS_INACTIVE], $status->new_values);

        $this->request($actor, $laboratory)
            ->patchJson("/api/v1/price-lists/{$priceList->id}/status", ['status' => PriceList::STATUS_INACTIVE])
            ->assertOk();
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_default_change_records_each_modified_price_list_and_is_idempotent(): void
    {
        [$actor, $laboratory] = $this->activeTenant();
        $previous = PriceList::factory()->for($laboratory)->create(['is_default' => true]);
        $target = PriceList::factory()->for($laboratory)->create(['is_default' => false]);

        $this->request($actor, $laboratory)
            ->patchJson("/api/v1/price-lists/{$target->id}/default")
            ->assertOk();

        $logs = AuditLog::query()->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame([$previous->id, $target->id], $logs->pluck('auditable_id')->all());
        $this->assertSame([
            ['is_default' => true],
            ['is_default' => false],
        ], $logs->pluck('old_values')->all());
        $this->assertSame([
            ['is_default' => false],
            ['is_default' => true],
        ], $logs->pluck('new_values')->all());
        $this->assertSame([
            CommercialAuditEvents::PRICE_LIST_DEFAULT_CHANGED,
            CommercialAuditEvents::PRICE_LIST_DEFAULT_CHANGED,
        ], $logs->pluck('event')->all());

        $this->request($actor, $laboratory)
            ->patchJson("/api/v1/price-lists/{$target->id}/default")
            ->assertOk();
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_cross_tenant_and_spoofed_actor_are_rejected_without_audit(): void
    {
        $actor = User::factory()->create();
        [, $laboratory] = $this->activeTenant($actor);
        [, $foreignLaboratory] = $this->activeTenant($actor);
        $foreign = PriceList::factory()->for($foreignLaboratory)->create();

        $this->request($actor, $laboratory)
            ->patchJson("/api/v1/price-lists/{$foreign->id}", ['name' => 'Intrusión'])
            ->assertNotFound();
        $this->request($actor, $laboratory)
            ->postJson('/api/v1/price-lists', [
                'name' => 'Spoof',
                'currency' => 'GTQ',
                'actor_id' => User::factory()->create()->id,
            ])->assertUnprocessable()->assertJsonValidationErrors('actor_id');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_audit_failure_rolls_back_price_list_creation(): void
    {
        [$actor, $laboratory] = $this->activeTenant();
        AuditLog::creating(static fn (): never => throw new RuntimeException('audit unavailable'));
        $this->withoutExceptionHandling();

        try {
            $this->request($actor, $laboratory)->postJson('/api/v1/price-lists', [
                'name' => 'Rollback',
                'currency' => 'GTQ',
            ]);
            $this->fail('The audit failure was not propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        } finally {
            AuditLog::flushEventListeners();
        }

        $this->assertDatabaseCount('price_lists', 0);
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
        $this->assignDirectLaboratoryPermissions($user, $laboratory, [
            'price_lists.create', 'price_lists.update', 'price_lists.change_status', 'price_lists.set_default',
        ]);

        return [$user, $laboratory];
    }

    private function request(User $user, Laboratory $laboratory): static
    {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id);
    }
}

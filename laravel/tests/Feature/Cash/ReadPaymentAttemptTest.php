<?php

namespace Tests\Feature\Cash;

use App\Cash\CashAuthority;
use App\Models\LaboratoryOrder;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReadPaymentAttemptTest extends TestCase
{
    use RefreshDatabase;

    private function context(bool $view = true, bool $create = true): array
    {
        $order = LaboratoryOrder::factory()->create(['total' => '80.00', 'subtotal' => '80.00']);
        $lab = $order->laboratory;
        Subscription::factory()->for($lab)->create();
        $actor = $this->createLaboratoryMember($lab);
        if ($view) {
            $this->assignDirectLaboratoryPermission($actor, $lab, CashAuthority::VIEW);
        }
        if ($create) {
            $this->assignDirectLaboratoryPermission($actor, $lab, CashAuthority::CREATE);
        }
        $this->actingAs($actor)->withHeader('X-Laboratory-ID', (string) $lab->id);

        return [$lab, $actor, $order];
    }

    private function postPayment(LaboratoryOrder $order, string $key = 'ficticio-attempt-key-01')
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/laboratory-orders/'.$order->id.'/payments', [
            'expected_revision' => 1, 'amount' => '10.00', 'method' => 'cash',
        ]);
    }

    private function readAttempt(LaboratoryOrder $order, string $key = 'ficticio-attempt-key-01')
    {
        return $this->getJson('/api/v1/laboratory-orders/'.$order->id.'/payment-attempts/'.rawurlencode($key));
    }

    public function test_confirmed_attempt_returns_the_exact_persisted_post_response_without_financial_effects(): void
    {
        [, , $order] = $this->context();
        $posted = $this->postPayment($order)->assertCreated();
        $before = [
            'movements' => DB::table('laboratory_order_cash_movements')->count(),
            'operations' => DB::table('laboratory_order_cash_operations')->count(),
            'audits' => DB::table('audit_logs')->where('event', 'cash.payment_recorded')->count(),
            'revision' => DB::table('laboratory_order_financial_states')->where('laboratory_order_id', $order->id)->value('revision'),
        ];

        $this->readAttempt($order)->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.operation_id', $posted->json('data.operation_id'))
            ->assertJsonPath('data.http_status', 201);
        $this->assertSame($posted->json(), $this->readAttempt($order)->json('data.response'));
        $this->assertSame($before['movements'], DB::table('laboratory_order_cash_movements')->count());
        $this->assertSame($before['operations'], DB::table('laboratory_order_cash_operations')->count());
        $this->assertSame($before['audits'], DB::table('audit_logs')->where('event', 'cash.payment_recorded')->count());
        $this->assertSame($before['revision'], DB::table('laboratory_order_financial_states')->where('laboratory_order_id', $order->id)->value('revision'));
    }

    public function test_unknown_or_malformed_key_never_creates_financial_records(): void
    {
        [, , $order] = $this->context();
        $this->readAttempt($order, 'ficticio-attempt-key-02')->assertStatus(202)->assertExactJson(['data' => ['status' => 'unknown']]);
        $this->readAttempt($order, 'short')->assertUnprocessable()->assertJsonValidationErrors('Idempotency-Key');
        $this->assertDatabaseCount('laboratory_order_cash_movements', 0);
        $this->assertDatabaseCount('laboratory_order_cash_operations', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseHas('laboratory_order_financial_states', ['laboratory_order_id' => $order->id, 'revision' => 1]);
    }

    public function test_attempt_identity_is_scoped_to_actor_order_and_laboratory(): void
    {
        [$lab, $actor, $order] = $this->context();
        $this->postPayment($order)->assertCreated();
        $otherOrder = LaboratoryOrder::factory()->for($lab)->create();
        $this->readAttempt($otherOrder)->assertStatus(202)->assertExactJson(['data' => ['status' => 'unknown']]);

        $otherActor = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermissions($otherActor, $lab, [CashAuthority::VIEW, CashAuthority::CREATE]);
        $this->actingAs($otherActor)->withHeader('X-Laboratory-ID', (string) $lab->id);
        $this->readAttempt($order)->assertStatus(202)->assertExactJson(['data' => ['status' => 'unknown']]);

        $foreign = LaboratoryOrder::factory()->create();
        $this->readAttempt($foreign)->assertNotFound();
        $this->assertDatabaseCount('laboratory_order_cash_movements', 1);
        $this->assertDatabaseCount('laboratory_order_cash_operations', 1);
    }

    public function test_both_view_and_create_permissions_are_required_at_read_time(): void
    {
        $this->getJson('/api/v1/laboratory-orders/1/payment-attempts/ficticio-attempt-key-01')->assertUnauthorized();
        [, , $noViewOrder] = $this->context(false, true);
        $this->readAttempt($noViewOrder)->assertForbidden();

        [, , $noCreateOrder] = $this->context(true, false);
        $this->readAttempt($noCreateOrder)->assertForbidden();
    }
}

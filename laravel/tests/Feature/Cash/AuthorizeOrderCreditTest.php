<?php

namespace Tests\Feature\Cash;

use App\Cash\CashAuthority;
use App\Models\LaboratoryOrder;
use App\Models\Laboratory;
use App\Models\ReportDeliveryPolicy;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthorizeOrderCreditTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $policy = 'payment_or_credit'): array
    {
        $lab = Laboratory::factory()->create();
        ReportDeliveryPolicy::factory()->for($lab)->create(['mode' => $policy]);
        $order = LaboratoryOrder::factory()->for($lab)->create(['total' => '80.00', 'subtotal' => '80.00']);
        Subscription::factory()->for($lab)->create();
        $actor = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermission($actor, $lab, CashAuthority::CREDIT);
        $this->assignDirectLaboratoryPermission($actor, $lab, CashAuthority::VIEW);
        $this->actingAs($actor)->withHeader('X-Laboratory-ID', (string) $lab->id);
        return [$lab, $actor, $order];
    }

    private function authorize(LaboratoryOrder $order, array $input = [], string $key = 'ficticio-credit-authorization-01')
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/laboratory-orders/'.$order->id.'/credit-authorizations', array_replace(['expected_revision' => 1, 'amount' => '80.00', 'reason' => 'Autorización ficticia'], $input));
    }

    public function test_authorizes_sufficient_credit_without_reducing_debt_and_updates_summary(): void
    {
        [, $actor, $order] = $this->context();
        $response = $this->authorize($order)->assertCreated()->assertJsonPath('data.operation', 'authorize_credit')->assertJsonPath('data.resource.amount', '80.00');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->getJson('/api/v1/laboratory-orders/'.$order->id.'/cash-summary')->assertOk()
            ->assertJsonPath('data.balance_due', '80.00')->assertJsonPath('data.credit.amount', '80.00')->assertJsonPath('data.credit.sufficient', true);
        $this->assertDatabaseHas('laboratory_order_financial_states', ['laboratory_order_id' => $order->id, 'active_credit_authorization_id' => $response->json('data.resource.id'), 'revision' => 2]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'cash.credit_authorized', 'user_id' => $actor->id]);
    }

    public function test_replaces_credit_without_accumulating_and_can_revoke_it(): void
    {
        [, , $order] = $this->context();
        $first = $this->authorize($order)->assertCreated();
        $second = $this->authorize($order, ['expected_revision' => 2, 'amount' => '90.00'], 'ficticio-credit-authorization-02')->assertCreated();
        $this->assertSame($first->json('data.resource.id'), $second->json('data.resource.replaces_authorization_id'));
        $this->withHeader('Idempotency-Key', 'ficticio-credit-revocation-0001')->postJson('/api/v1/laboratory-orders/'.$order->id.'/credit-authorizations/'.$second->json('data.resource.id').'/revoke', ['expected_revision' => 3, 'reason' => 'Revocación ficticia'])->assertCreated()->assertJsonPath('data.operation', 'revoke_credit');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->getJson('/api/v1/laboratory-orders/'.$order->id.'/cash-summary')->assertOk()->assertJsonPath('data.credit', null);
        $this->getJson('/api/v1/laboratory-orders/'.$order->id.'/credit-authorizations')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_rejects_insufficient_or_incompatible_credit_without_effects(): void
    {
        [, , $order] = $this->context();
        $this->authorize($order, ['amount' => '79.99'])->assertUnprocessable()->assertJsonPath('code', 'cash_credit_insufficient');
        [, , $restricted] = $this->context('full_payment_required');
        $this->authorize($restricted)->assertConflict()->assertJsonPath('code', 'cash_credit_policy_incompatible');
        $this->assertDatabaseCount('laboratory_order_credit_events', 0);
    }
}

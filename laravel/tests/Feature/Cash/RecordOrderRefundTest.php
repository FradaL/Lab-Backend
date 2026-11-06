<?php

namespace Tests\Feature\Cash;

use App\Cash\CashAuthority;
use App\Cash\RecordOrderPayment;
use App\Cash\RecordOrderRefund;
use App\Models\LaboratoryOrder;
use App\Models\Subscription;
use App\Services\LaboratoryOrders\RecalculateLaboratoryOrderEconomics;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\HistoricalOrderFixture;
use Tests\TestCase;

class RecordOrderRefundTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $payment = '100.00', string $total = '80.00'): array
    {
        $order = LaboratoryOrder::factory()->create(['total' => $total, 'subtotal' => $total]);
        $lab = $order->laboratory;
        Subscription::factory()->for($lab)->create();
        $actor = $this->createLaboratoryMember($lab);
        foreach ([CashAuthority::CREATE, CashAuthority::REFUND, CashAuthority::VIEW] as $permission) {
            $this->assignDirectLaboratoryPermission($actor, $lab, $permission);
        }
        setPermissionsTeamId($lab->id);
        app(RecordOrderPayment::class)->execute($lab, $actor, $order->id,
            ['expected_revision' => 1, 'amount' => $payment, 'method' => 'card', 'reference' => 'COBRO-FICTICIO'], 'ficticio-refund-seed');
        $this->actingAs($actor)->withHeader('X-Laboratory-ID', (string) $lab->id);

        return [$lab, $actor, $order];
    }

    private function refund(LaboratoryOrder $order, array $overrides = [], string $key = 'ficticio-refund-0001')
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/laboratory-orders/'.$order->id.'/refunds',
            array_replace(['expected_revision' => 2, 'amount' => '10.00', 'method' => 'cash', 'reason' => 'Devolución FICTICIA'], $overrides));
    }

    #[DataProvider('validRefunds')]
    public function test_refund_preserves_exact_money_provenance_and_updates_reads(string $amount, string $method, ?string $reference): void
    {
        [$lab, $actor, $order] = $this->context();
        $response = $this->refund($order, compact('amount', 'method', 'reference') + ['reason' => ' Motivo FICTICIO ñ/01 '])
            ->assertCreated()->assertHeader('Idempotency-Replayed', 'false')
            ->assertJsonPath('data.operation', 'record_refund')->assertJsonPath('data.financial_revision', 3)
            ->assertJsonPath('data.resource.type', 'refund')->assertJsonPath('data.resource.amount', $amount)
            ->assertJsonPath('data.resource.created_by', $actor->id)->assertJsonPath('data.resource.currency', $order->currency)
            ->assertJsonPath('data.resource.reason', 'Motivo FICTICIO ñ/01')->assertJsonPath('data.resource.target_payment_id', null)
            ->assertJsonPath('data.resource.payment_status', null)
            ->assertJsonPath('data.resource.reference', $reference === null ? null : trim($reference));
        $this->assertMatchesRegularExpression('/Z$/', $response->json('data.resource.recorded_at'));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->assertDatabaseHas('audit_logs', ['event' => 'cash.refund_recorded', 'user_id' => $actor->id, 'auditable_id' => $response->json('data.resource.id')]);
        $this->assertDatabaseHas('laboratory_order_financial_revisions', ['laboratory_order_id' => $order->id, 'cause' => 'refund_recorded', 'revision' => 3]);
        $summary = $this->getJson('/api/v1/laboratory-orders/'.$order->id.'/cash-summary')->assertOk()
            ->assertJsonPath('data.financial_revision', 3)->assertJsonPath('data.valid_payments', '100.00')
            ->assertJsonPath('data.refunds', $amount)->assertJsonPath('data.balance_due', '0.00');
        $this->assertSame('80.00', $order->fresh()->total);
        $this->getJson('/api/v1/laboratory-orders/'.$order->id.'/cash-movements')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('data.0', fn ($actual) => $actual == $response->json('data.resource'))
            ->assertJsonPath('data.1.type', 'payment')->assertJsonPath('data.1.payment_status', 'active');
        $this->assertSame($amount === '20.00' ? '0.00' : ($amount === '0.01' ? '19.99' : '10.00'), $summary->json('data.surplus'));
    }

    public static function validRefunds(): array
    {
        return [['0.01', 'cash', null], ['10.00', 'cash', 'REF-FICTICIA'], ['10.00', 'card', ' REF-Ñ/01 '], ['20.00', 'bank_transfer', 'REF-FICTICIA']];
    }

    public function test_partial_then_total_refund_exhausts_surplus_and_rejects_more_without_writes(): void
    {
        [, , $order] = $this->context();
        $this->refund($order)->assertCreated();
        $this->refund($order, ['expected_revision' => 3], 'ficticio-refund-0002')->assertCreated();
        $this->refund($order, ['expected_revision' => 4, 'amount' => '0.01'], 'ficticio-refund-0003')
            ->assertUnprocessable()->assertJsonPath('code', 'cash_refund_exceeds_surplus')->assertJsonValidationErrors('amount');
        $this->assertDatabaseCount('laboratory_order_cash_movements', 3);
        $this->assertDatabaseCount('laboratory_order_cash_operations', 3);
        $this->assertDatabaseCount('laboratory_order_financial_revisions', 4);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    #[DataProvider('noSurplus')]
    public function test_without_surplus_refund_is_rejected(string $payment, string $total): void
    {
        [, , $order] = $this->context($payment, $total);
        $this->refund($order, ['amount' => '0.01'])->assertUnprocessable()->assertJsonPath('code', 'cash_refund_exceeds_surplus');
        $this->assertDatabaseCount('laboratory_order_cash_movements', 1);
        $this->assertDatabaseCount('laboratory_order_financial_revisions', 2);
    }

    public static function noSurplus(): array
    {
        return [['80.00', '80.00'], ['10.00', '80.00']];
    }

    public function test_maximum_amount_and_zero_total_are_supported(): void
    {
        [, , $order] = $this->context('9999999999.99', '0.00');
        $this->refund($order, ['amount' => '9999999999.99'])->assertCreated();
        $this->getJson('/api/v1/laboratory-orders/'.$order->id.'/cash-summary')->assertOk()
            ->assertJsonPath('data.net_payments', '0.00')->assertJsonPath('data.surplus', '0.00');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_replay_after_later_payment_or_total_change_returns_original_and_normalizes_text(): void
    {
        [$lab, $actor, $order] = $this->context();
        $first = $this->refund($order, ['reason' => ' Devolución FICTICIA ', 'reference' => null])->assertCreated();
        setPermissionsTeamId($lab->id);
        app(RecordOrderPayment::class)->execute($lab, $actor, $order->id,
            ['expected_revision' => 3, 'amount' => '5.00', 'method' => 'cash'], 'ficticio-payment-later');
        DB::transaction(function () use ($lab, $actor, $order): void {
            $locked = LaboratoryOrder::forLaboratory($lab)->whereKey($order->id)->lockForUpdate()->firstOrFail();
            app(RecalculateLaboratoryOrderEconomics::class)->execute($lab, $locked, $actor);
        });
        $replay = $this->refund($order)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($first->json(), $replay->json());
        $this->assertDatabaseCount('laboratory_order_cash_movements', 3);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'cash.refund_recorded')->count());
        $this->assertDatabaseHas('laboratory_order_financial_states', ['laboratory_order_id' => $order->id, 'revision' => 5]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_changed_content_actor_order_or_revision_cannot_recover_another_result(): void
    {
        [$lab, , $order] = $this->context();
        $this->refund($order)->assertCreated();
        foreach ([['amount' => '1.00'], ['reason' => 'Otro motivo'], ['method' => 'card', 'reference' => 'FICTICIO'], ['expected_revision' => 3]] as $input) {
            $this->refund($order, $input)->assertConflict()->assertJsonPath('code', 'cash_idempotency_conflict')->assertJsonMissingPath('data');
        }
        $other = LaboratoryOrder::factory()->for($lab)->create();
        $this->refund($other)->assertConflict()->assertJsonPath('code', 'cash_idempotency_conflict');
        $actor = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermission($actor, $lab, CashAuthority::REFUND);
        $this->actingAs($actor);
        $this->refund($order)->assertConflict()->assertJsonPath('code', 'cash_idempotency_conflict');
        $this->assertDatabaseCount('laboratory_order_cash_movements', 2);
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_input_is_rejected_without_financial_effect(array $input, string $field): void
    {
        [, , $order] = $this->context();
        $this->refund($order, $input)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('laboratory_order_cash_movements', 1);
        $this->assertDatabaseCount('laboratory_order_cash_operations', 1);
        $this->assertDatabaseCount('laboratory_order_financial_revisions', 2);
    }

    public static function invalidPayloads(): array
    {
        $cases = RecordOrderPaymentTest::invalidInput();
        $cases = array_values(array_filter($cases, fn ($case) => $case[1] !== 'reason'));
        foreach ([null, '', '  ', 12, [], str_repeat('a', 1001), str_repeat(' ', 1000).'a'] as $reason) {
            $cases[] = [compact('reason'), 'reason'];
        }

        return $cases;
    }

    #[DataProvider('invalidKeys')]
    public function test_invalid_identity_key_is_rejected(string $key): void
    {
        [, , $order] = $this->context();
        $this->refund($order, [], $key)->assertUnprocessable()->assertJsonValidationErrors('Idempotency-Key');
    }

    public static function invalidKeys(): array
    {
        return RecordOrderPaymentTest::invalidKeys();
    }

    public function test_missing_reason_and_float_json_revision_are_rejected(): void
    {
        [, , $order] = $this->context();
        $url = '/api/v1/laboratory-orders/'.$order->id.'/refunds';
        $this->withHeader('Idempotency-Key', 'ficticio-refund-missing')->postJson($url,
            ['expected_revision' => 2, 'amount' => '1.00', 'method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_LABORATORY_ID' => (string) $order->laboratory_id, 'HTTP_IDEMPOTENCY_KEY' => 'ficticio-refund-float'],
            '{"expected_revision":2.0,"amount":"1.00","method":"cash","reason":"FICTICIO"}')
            ->assertUnprocessable()->assertJsonValidationErrors('expected_revision');
    }

    public function test_permission_is_independent_rechecked_for_replay_and_order_is_tenant_scoped(): void
    {
        [$lab, $actor, $order] = $this->context();
        $this->refund($order)->assertCreated();
        setPermissionsTeamId($lab->id);
        $actor->revokePermissionTo(CashAuthority::REFUND);
        $this->refund($order)->assertForbidden();
        $this->assignDirectLaboratoryPermission($actor, $lab, CashAuthority::REFUND);
        $other = LaboratoryOrder::factory()->create();
        $this->refund($other)->assertNotFound();
        $this->refund($order, [], 'ficticio-refund-tenant')->assertConflict()->assertJsonPath('code', 'cash_revision_conflict');
        $this->assertDatabaseCount('laboratory_order_cash_movements', 2);
    }

    public function test_historical_context_and_stale_revision_do_not_write(): void
    {
        [$lab, , $order] = $this->context();
        $this->refund($order, ['expected_revision' => 1])->assertConflict()->assertJsonPath('code', 'cash_revision_conflict');
        $historical = HistoricalOrderFixture::create(fn () => LaboratoryOrder::factory()->for($lab)->create(['delivery_policy_snapshot' => null]));
        $this->refund($historical)->assertConflict()->assertJsonPath('code', 'cash_context_unavailable');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        DB::statement('ALTER TABLE laboratory_order_financial_states DISABLE TRIGGER protect_financial_state_delete');
        DB::table('laboratory_order_financial_states')->where('laboratory_order_id', $order->id)->delete();
        DB::statement('ALTER TABLE laboratory_order_financial_states ENABLE TRIGGER protect_financial_state_delete');
        $this->refund($order)->assertStatus(503)->assertJsonPath('code', 'cash_evidence_unavailable');
        $this->assertDatabaseCount('laboratory_order_cash_movements', 1);
    }

    public function test_failed_audit_rolls_back_every_write_and_same_key_can_retry(): void
    {
        [$lab, $actor, $order] = $this->context();
        DB::unprepared("CREATE FUNCTION reject_refund_audit() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'audit unavailable'; END \$\$; CREATE TRIGGER reject_refund_audit BEFORE INSERT ON audit_logs FOR EACH ROW WHEN (NEW.event='cash.refund_recorded') EXECUTE FUNCTION reject_refund_audit();");
        try {
            app(RecordOrderRefund::class)->execute($lab, $actor, $order->id,
                ['expected_revision' => 2, 'amount' => '10.00', 'method' => 'cash', 'reason' => 'FICTICIO'], 'ficticio-refund-rollback');
            $this->fail('Expected rollback');
        } catch (QueryException $error) {
            $this->assertStringContainsString('audit unavailable', $error->getMessage());
        }
        $this->assertDatabaseCount('laboratory_order_cash_movements', 1);
        $this->assertDatabaseCount('laboratory_order_cash_operations', 1);
        $this->assertDatabaseCount('laboratory_order_financial_revisions', 2);
        DB::unprepared('DROP TRIGGER reject_refund_audit ON audit_logs; DROP FUNCTION reject_refund_audit();');
        $this->refund($order, ['reason' => 'FICTICIO'], 'ficticio-refund-rollback')->assertCreated();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_refund_permission_does_not_require_collect_or_view_and_keys_are_scoped_by_operation(): void
    {
        [$lab, $actor, $order] = $this->context();
        setPermissionsTeamId($lab->id);
        $actor->revokePermissionTo([CashAuthority::CREATE, CashAuthority::VIEW]);
        $this->refund($order, [], 'ficticio-refund-seed')->assertCreated()->assertHeader('Idempotency-Replayed', 'false');
        $this->getJson('/api/v1/laboratory-orders/'.$order->id.'/cash-summary')->assertForbidden();
        $this->assertDatabaseCount('laboratory_order_cash_operations', 2);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_identical_refunds_with_new_keys_are_distinct_and_laboratories_have_independent_keys(): void
    {
        [, , $firstOrder] = $this->context();
        $first = $this->refund($firstOrder)->assertCreated();
        $second = $this->refund($firstOrder, ['expected_revision' => 3], 'ficticio-refund-0002')->assertCreated();
        $this->assertNotSame($first->json('data.operation_id'), $second->json('data.operation_id'));
        [, , $otherOrder] = $this->context();
        $other = $this->refund($otherOrder)->assertCreated()->assertHeader('Idempotency-Replayed', 'false');
        $this->assertNotSame($first->json('data.operation_id'), $other->json('data.operation_id'));
        $this->assertDatabaseCount('laboratory_order_cash_movements', 5);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }
}

<?php

namespace Tests\Feature\Cash;

use App\Cash\CashAuthority;
use App\Cash\OrderFinancialState;
use App\Cash\RecordOrderPayment;
use App\ClinicalExams\ClinicalConflict;
use App\Models\LaboratoryOrder;
use App\Models\Subscription;
use App\Services\LaboratoryOrders\RecalculateLaboratoryOrderEconomics;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\HistoricalOrderFixture;
use Tests\TestCase;

class RecordOrderPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function context(): array
    {
        $order = LaboratoryOrder::factory()->create(['total' => '80.00', 'subtotal' => '80.00']);
        $lab = $order->laboratory;
        Subscription::factory()->for($lab)->create();
        $actor = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermission($actor, $lab, CashAuthority::CREATE);
        $this->actingAs($actor)->withHeader('X-Laboratory-ID', (string) $lab->id);

        return [$lab, $actor, $order];
    }

    private function postPayment(LaboratoryOrder $order, array $overrides = [], string $key = 'ficticio-payment-0001')
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/laboratory-orders/'.$order->id.'/payments',
            array_replace(['expected_revision' => 1, 'amount' => '100.00', 'method' => 'cash'], $overrides));
    }

    #[DataProvider('validPayments')]
    public function test_payment_preserves_exact_money_and_server_provenance(string $amount, string $method, ?string $reference): void
    {
        [$lab, $actor, $order] = $this->context();
        $response = $this->postPayment($order, compact('amount', 'method', 'reference'))->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'false')
            ->assertJsonPath('data.operation', 'record_payment')->assertJsonPath('data.financial_revision', 2)
            ->assertJsonPath('data.resource.amount', $amount)->assertJsonPath('data.resource.currency', $order->currency)
            ->assertJsonPath('data.resource.created_by', $actor->id)->assertJsonPath('data.resource.reference', $reference === null ? null : trim($reference));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->assertDatabaseHas('laboratory_order_cash_movements', ['id' => $response->json('data.resource.id'), 'amount' => $amount]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'cash.payment_recorded', 'user_id' => $actor->id]);
        $this->assertDatabaseHas('laboratory_order_financial_states', ['laboratory_order_id' => $order->id, 'revision' => 2]);
        $this->assertSame('80.00', $order->fresh()->total);
    }

    public static function validPayments(): array
    {
        return [['0.01', 'cash', null], ['80.00', 'card', ' REF-Ñ-01 '], ['100.00', 'bank_transfer', 'REF-02'], ['9999999999.99', 'cash', 'Efectivo']];
    }

    public function test_replay_returns_original_body_after_another_payment_without_duplicating_audit_or_revision(): void
    {
        [, , $order] = $this->context();
        $first = $this->postPayment($order)->assertCreated();
        $this->postPayment($order, ['expected_revision' => 2], 'ficticio-payment-0002')->assertCreated();
        $replay = $this->postPayment($order)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($first->json(), $replay->json());
        $this->assertDatabaseCount('laboratory_order_cash_movements', 2);
        $this->assertSame(2, DB::table('audit_logs')->where('event', 'cash.payment_recorded')->count());
        $this->assertDatabaseHas('laboratory_order_financial_states', ['laboratory_order_id' => $order->id, 'revision' => 3]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_omitted_null_and_trimmed_reference_share_the_canonical_identity(): void
    {
        [, , $order] = $this->context();
        $first = $this->postPayment($order)->assertCreated();
        $this->assertSame($first->json(), $this->postPayment($order, ['reference' => null])->assertCreated()->json());
        $first = $this->postPayment($order, ['expected_revision' => 2, 'reference' => ' REF-ñ/01 '], 'ficticio-payment-0002')->assertCreated();
        $this->assertSame($first->json(), $this->postPayment($order, ['expected_revision' => 2, 'reference' => 'REF-ñ/01'], 'ficticio-payment-0002')->assertCreated()->json());
    }

    public function test_same_key_with_different_body_actor_order_or_revision_conflicts(): void
    {
        [$lab, , $order] = $this->context();
        $this->postPayment($order)->assertCreated();
        foreach ([['amount' => '90.00'], ['method' => 'card', 'reference' => 'FICTICIO'], ['expected_revision' => 2]] as $input) {
            $this->postPayment($order, $input)->assertConflict()->assertJsonPath('code', 'cash_idempotency_conflict')->assertJsonMissingPath('data');
        }
        $other = LaboratoryOrder::factory()->for($lab)->create();
        $this->postPayment($other)->assertConflict()->assertJsonPath('code', 'cash_idempotency_conflict');
        $actor = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermission($actor, $lab, CashAuthority::CREATE);
        $this->actingAs($actor);
        $this->postPayment($order)->assertConflict()->assertJsonPath('code', 'cash_idempotency_conflict');
        $this->assertDatabaseCount('laboratory_order_cash_movements', 1);
    }

    #[DataProvider('invalidInput')]
    public function test_invalid_payload_has_no_financial_effect(array $input, string $field): void
    {
        [, , $order] = $this->context();
        $this->postPayment($order, $input)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('laboratory_order_cash_movements', 0);
        $this->assertDatabaseCount('laboratory_order_cash_operations', 0);
        $this->assertDatabaseHas('laboratory_order_financial_states', ['laboratory_order_id' => $order->id, 'revision' => 1]);
    }

    public static function invalidInput(): array
    {
        $cases = [];
        foreach (['0.00', '-1.00', '1', '1.0', '1.001', '01.00', '1e2', '1,00', '10000000000.00', 1, 1.5, null, ' 1.00 '] as $value) {
            $cases[] = [['amount' => $value], 'amount'];
        }
        foreach (['1', 0, -1, 2147483648, null] as $value) {
            $cases[] = [['expected_revision' => $value], 'expected_revision'];
        }
        foreach (['card', 'bank_transfer'] as $method) {
            foreach ([null, '', '  '] as $reference) {
                $cases[] = [compact('method', 'reference'), 'reference'];
            }
        }
        foreach (['', '  ', str_repeat('a', 151), str_repeat(' ', 151).'R', 12] as $reference) {
            $cases[] = [compact('reference'), 'reference'];
        }
        foreach (['currency', 'laboratory_id', 'created_by', 'recorded_at', 'reason', 'financial_revision'] as $field) {
            $cases[] = [[$field => 'injected'], $field];
        }
        $cases[] = [['method' => 'bitcoin'], 'method'];

        return $cases;
    }

    #[DataProvider('invalidKeys')]
    public function test_invalid_key_is_rejected(string $key): void
    {
        [, , $order] = $this->context();
        $this->postPayment($order, [], $key)->assertUnprocessable()->assertJsonValidationErrors('Idempotency-Key');
    }

    public static function invalidKeys(): array
    {
        return [[''], ['short'], [str_repeat('a', 101)], ['invalid key with spaces']];
    }

    public function test_float_json_revision_is_rejected_without_coercion(): void
    {
        [, , $order] = $this->context();
        $this->call('POST', '/api/v1/laboratory-orders/'.$order->id.'/payments', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_LABORATORY_ID' => (string) $order->laboratory_id, 'HTTP_IDEMPOTENCY_KEY' => 'ficticio-payment-float'],
            '{"expected_revision":1.0,"amount":"10.00","method":"cash"}')
            ->assertUnprocessable()->assertJsonValidationErrors('expected_revision');
    }

    public function test_stale_revision_and_missing_context_do_not_write(): void
    {
        [$lab,$actor,$order] = $this->context();
        $this->postPayment($order, ['expected_revision' => 2])->assertConflict()->assertJsonPath('code', 'cash_revision_conflict');
        $historical = HistoricalOrderFixture::create(fn () => LaboratoryOrder::factory()->for($lab)->create(['delivery_policy_snapshot' => null]));
        $this->postPayment($historical)->assertConflict()->assertJsonPath('code', 'cash_context_unavailable');
        $this->assertDatabaseCount('laboratory_order_cash_movements', 0);
    }

    public function test_missing_new_context_is_unavailable_and_is_not_initialized_on_payment(): void
    {
        [, ,$order] = $this->context();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        DB::statement('ALTER TABLE laboratory_order_financial_states DISABLE TRIGGER protect_financial_state_delete');
        DB::table('laboratory_order_financial_states')->where('laboratory_order_id', $order->id)->delete();
        DB::statement('ALTER TABLE laboratory_order_financial_states ENABLE TRIGGER protect_financial_state_delete');
        $this->postPayment($order)->assertStatus(503)->assertJsonPath('code', 'cash_evidence_unavailable');
        $this->assertDatabaseCount('laboratory_order_financial_states', 0);
    }

    public function test_permission_revocation_blocks_replay_and_other_tenant_cannot_access_order(): void
    {
        [$lab,$actor,$order] = $this->context();
        $this->postPayment($order)->assertCreated();
        setPermissionsTeamId($lab->id);
        $actor->revokePermissionTo(CashAuthority::CREATE);
        $this->postPayment($order)->assertForbidden();
        $other = LaboratoryOrder::factory()->create();
        $this->assignDirectLaboratoryPermission($actor, $lab, CashAuthority::CREATE);
        $this->postPayment($other)->assertNotFound();
        $this->assertDatabaseCount('laboratory_order_cash_movements', 1);
    }

    public function test_failed_audit_rolls_back_and_same_identity_can_retry(): void
    {
        [$lab,$actor,$order] = $this->context();
        DB::unprepared("CREATE FUNCTION reject_payment_audit() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'audit unavailable'; END \$\$; CREATE TRIGGER reject_payment_audit BEFORE INSERT ON audit_logs FOR EACH ROW WHEN (NEW.event='cash.payment_recorded') EXECUTE FUNCTION reject_payment_audit();");
        setPermissionsTeamId($lab->id);
        try {
            app(RecordOrderPayment::class)->execute($lab, $actor, $order->id, ['expected_revision' => 1, 'amount' => '0.01', 'method' => 'cash'], 'ficticio-payment-0001');
            $this->fail('Expected audit rollback');
        } catch (QueryException $e) {
            $this->assertStringContainsString('audit unavailable', $e->getMessage());
        }
        $this->assertDatabaseCount('laboratory_order_cash_movements', 0);
        $this->assertDatabaseCount('laboratory_order_cash_operations', 0);
        $this->assertDatabaseCount('laboratory_order_financial_revisions', 1);
        DB::unprepared('DROP TRIGGER reject_payment_audit ON audit_logs; DROP FUNCTION reject_payment_audit();');
        $this->postPayment($order, ['amount' => '0.01'])->assertCreated()->assertJsonPath('data.financial_revision', 2);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_recalculation_advances_once_only_when_total_changes_and_preserves_replay(): void
    {
        [$lab,$actor,$order] = $this->context();
        $first = $this->postPayment($order)->assertCreated();
        DB::transaction(function () use ($lab, $actor, $order): void {
            $locked = LaboratoryOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            app(RecalculateLaboratoryOrderEconomics::class)->execute($lab, $locked, $actor);
            app(RecalculateLaboratoryOrderEconomics::class)->execute($lab, $locked, $actor);
        });
        $this->assertSame('0.00', $order->fresh()->total);
        $this->assertDatabaseHas('laboratory_order_financial_states', ['laboratory_order_id' => $order->id, 'revision' => 3]);
        $this->assertSame(1, DB::table('laboratory_order_financial_revisions')->where('cause', 'order_total_changed')->count());
        $this->assertSame($first->json(), $this->postPayment($order)->assertCreated()->json());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    #[DataProvider('immutableTables')]
    public function test_financial_history_rejects_direct_updates_and_deletes(string $table, bool $delete): void
    {
        [, ,$order] = $this->context();
        $this->postPayment($order)->assertCreated();
        $this->expectException(QueryException::class);
        DB::transaction(function () use ($table, $delete): void {
            if ($delete) {
                DB::table($table)->delete();
            } else {
                DB::table($table)->update(['recorded_at' => now()->toISOString()]);
            }
        });
    }

    public static function immutableTables(): array
    {
        $cases = [];
        foreach (['laboratory_order_cash_movements', 'laboratory_order_cash_operations', 'laboratory_order_financial_revisions'] as $table) {
            foreach ([true, false] as $delete) {
                $cases[] = [$table, $delete];
            }
        }

        return $cases;
    }

    public function test_financial_revision_limit_has_explicit_conflict(): void
    {
        $this->expectException(ClinicalConflict::class);
        $this->expectExceptionMessage('La revisión financiera alcanzó su límite.');
        app(OrderFinancialState::class)->next(2147483647);
    }

    #[DataProvider('partialWrites')]
    public function test_sql_rejects_partial_financial_writes_at_commit(string $kind): void
    {
        [$lab, $actor, $order] = $this->context();
        $this->expectException(QueryException::class);
        DB::transaction(function () use ($kind, $order): void {
            if ($kind === 'state') {
                DB::table('laboratory_order_financial_states')->where('laboratory_order_id', $order->id)->update(['revision' => 2]);
            } elseif ($kind === 'delete_state') {
                DB::table('laboratory_order_financial_states')->where('laboratory_order_id', $order->id)->delete();
            } elseif ($kind === 'currency') {
                DB::table('laboratory_orders')->where('id', $order->id)->update(['currency' => 'USD']);
            } else {
                $this->postPayment($order)->assertCreated();
                DB::table('audit_logs')->where('event', 'cash.payment_recorded')->delete();
            }
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
    }

    public static function partialWrites(): array
    {
        return [['state'], ['delete_state'], ['currency'], ['delete_audit']];
    }

    public function test_sql_cannot_reuse_a_payment_to_manufacture_another_revision(): void
    {
        [$lab, $actor, $order] = $this->context();
        $response = $this->postPayment($order)->assertCreated();
        $movement = $response->json('data.resource.id');
        $this->expectException(QueryException::class);
        DB::transaction(function () use ($order, $actor, $movement): void {
            $time = now()->toISOString();
            DB::table('laboratory_order_financial_states')->where('laboratory_order_id', $order->id)
                ->update(['revision' => 3, 'updated_at' => $time]);
            app(OrderFinancialState::class)->revision($order, 3, 2, 'payment_recorded', $actor->id, $time, $movement);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
    }

    public function test_idempotency_identity_is_independent_between_laboratories(): void
    {
        [, , $firstOrder] = $this->context();
        $first = $this->postPayment($firstOrder)->assertCreated();
        [, , $secondOrder] = $this->context();
        $second = $this->postPayment($secondOrder)->assertCreated()->assertHeader('Idempotency-Replayed', 'false');
        $this->assertNotSame($first->json('data.operation_id'), $second->json('data.operation_id'));
        $this->assertDatabaseCount('laboratory_order_cash_movements', 2);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_uncoordinated_total_change_is_rejected_by_deferred_integrity_guard(): void
    {
        [, ,$order] = $this->context();
        $this->expectException(QueryException::class);
        DB::transaction(function () use ($order): void {
            DB::table('laboratory_orders')->where('id', $order->id)->update(['total' => '99.00']);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
    }
}

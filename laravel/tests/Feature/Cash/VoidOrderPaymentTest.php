<?php

namespace Tests\Feature\Cash;

use App\Cash\CashAuthority;
use App\Cash\RecordOrderPayment;
use App\Cash\VoidOrderPayment;
use App\Models\LaboratoryOrder;
use App\Models\Subscription;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VoidOrderPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $order = LaboratoryOrder::factory()->create(['total' => '80.00', 'subtotal' => '80.00']);
        $lab = $order->laboratory;
        Subscription::factory()->for($lab)->create();
        $actor = $this->createLaboratoryMember($lab);
        foreach ([CashAuthority::CREATE, CashAuthority::VOID, CashAuthority::VIEW] as $permission) {
            $this->assignDirectLaboratoryPermission($actor, $lab, $permission);
        }
        setPermissionsTeamId($lab->id);
        $result = app(RecordOrderPayment::class)->execute($lab, $actor, $order->id,
            ['expected_revision' => 1, 'amount' => '100.00', 'method' => 'cash'], 'ficticio-original-payment');
        $this->actingAs($actor)->withHeader('X-Laboratory-ID', (string) $lab->id);

        return [$lab, $actor, $order, $result['body']['data']['resource']['id']];
    }

    private function voidPayment(LaboratoryOrder $order, int $payment, array $input = [], string $key = 'ficticio-void-payment-01')
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/laboratory-orders/'.$order->id.'/payments/'.$payment.'/void',
            array_replace(['expected_revision' => 2, 'reason' => 'Error de registro ficticio'], $input));
    }

    public function test_void_preserves_original_and_updates_summary_history_and_audit_once(): void
    {
        [$lab, $actor, $order, $payment] = $this->context();
        $original = DB::table('laboratory_order_cash_movements')->find($payment);
        $response = $this->voidPayment($order, $payment)->assertCreated()->assertHeader('Idempotency-Replayed', 'false')
            ->assertJsonPath('data.operation', 'void_payment')->assertJsonPath('data.financial_revision', 3)
            ->assertJsonPath('data.resource.type', 'payment_void')->assertJsonPath('data.resource.target_payment_id', $payment)
            ->assertJsonPath('data.resource.amount', '100.00')->assertJsonPath('data.resource.created_by', $actor->id)
            ->assertJsonPath('data.resource.method', null)->assertJsonPath('data.resource.reference', null)
            ->assertJsonPath('data.resource.payment_status', null);
        $this->assertEquals($original, DB::table('laboratory_order_cash_movements')->find($payment));
        $this->assertSame('80.00', $order->fresh()->total);
        $this->assertDatabaseCount('laboratory_order_cash_movements', 2);
        $this->assertDatabaseHas('audit_logs', ['event' => 'cash.payment_voided', 'auditable_id' => $response->json('data.resource.id'), 'user_id' => $actor->id]);
        $this->assertSame(0, DB::table('laboratory_order_cash_movements')->where('type', 'refund')->count());
        $this->getJson('/api/v1/laboratory-orders/'.$order->id.'/cash-summary')->assertOk()
            ->assertJsonPath('data.valid_payments', '0.00')->assertJsonPath('data.refunds', '0.00')
            ->assertJsonPath('data.net_payments', '0.00')->assertJsonPath('data.balance_due', '80.00')
            ->assertJsonPath('data.surplus', '0.00')->assertJsonPath('data.financial_revision', 3);
        $this->getJson('/api/v1/laboratory-orders/'.$order->id.'/cash-movements')->assertOk()
            ->assertJsonPath('data.0.type', 'payment_void')->assertJsonPath('data.1.id', $payment)->assertJsonPath('data.1.payment_status', 'voided');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_replay_survives_new_payment_and_does_not_repeat_effect(): void
    {
        [$lab, $actor, $order, $payment] = $this->context();
        $first = $this->voidPayment($order, $payment)->assertCreated();
        setPermissionsTeamId($lab->id);
        app(RecordOrderPayment::class)->execute($lab, $actor, $order->id,
            ['expected_revision' => 3, 'amount' => '80.00', 'method' => 'cash'], 'ficticio-correct-payment');
        $replay = $this->voidPayment($order, $payment)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($first->json(), $replay->json());
        $this->assertDatabaseCount('laboratory_order_cash_movements', 3);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'cash.payment_voided')->count());
        $this->getJson('/api/v1/laboratory-orders/'.$order->id.'/cash-summary')->assertOk()
            ->assertJsonPath('data.net_payments', '80.00')->assertJsonPath('data.balance_due', '0.00')->assertJsonPath('data.financial_revision', 4);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_new_key_cannot_void_again_and_changed_identity_cannot_replay(): void
    {
        [$lab, $actor, $order, $payment] = $this->context();
        $this->voidPayment($order, $payment)->assertCreated();
        $this->voidPayment($order, $payment, ['expected_revision' => 3], 'ficticio-other-void-key')->assertConflict()->assertJsonPath('code', 'cash_payment_already_voided');
        foreach ([['reason' => 'Otro motivo'], ['expected_revision' => 3]] as $input) {
            $this->voidPayment($order, $payment, $input)->assertConflict()->assertJsonPath('code', 'cash_idempotency_conflict');
        }
        $otherActor = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermission($otherActor, $lab, CashAuthority::VOID);
        $this->actingAs($otherActor);
        $this->voidPayment($order, $payment)->assertConflict()->assertJsonPath('code', 'cash_idempotency_conflict');
        $this->assertDatabaseCount('laboratory_order_cash_movements', 2);
    }

    public function test_replay_requires_current_permission_and_targets_belong_to_order_and_tenant(): void
    {
        [$lab, $actor, $order, $payment] = $this->context();
        $void = $this->voidPayment($order, $payment)->assertCreated()->json('data.resource.id');
        setPermissionsTeamId($lab->id);
        $actor->revokePermissionTo(CashAuthority::VOID);
        $this->voidPayment($order, $payment)->assertForbidden();
        $this->assignDirectLaboratoryPermission($actor, $lab, CashAuthority::VOID);
        $other = LaboratoryOrder::factory()->for($lab)->create();
        $foreign = LaboratoryOrder::factory()->create();
        $this->voidPayment($other, $payment)->assertNotFound();
        $this->voidPayment($foreign, $payment)->assertNotFound();
        $this->voidPayment($order, $void)->assertNotFound();
        $this->voidPayment($order, PHP_INT_MAX)->assertNotFound();
        $this->assertDatabaseCount('laboratory_order_cash_movements', 2);
    }

    #[DataProvider('invalidInput')]
    public function test_invalid_input_does_not_change_finances(array $input, string $field, string $key): void
    {
        [, , $order, $payment] = $this->context();
        $this->voidPayment($order, $payment, $input, $key)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('laboratory_order_cash_movements', 1);
        $this->assertDatabaseCount('laboratory_order_cash_operations', 1);
        $this->assertDatabaseHas('laboratory_order_financial_states', ['laboratory_order_id' => $order->id, 'revision' => 2]);
    }

    public static function invalidInput(): array
    {
        $cases = [];
        foreach ([null, '', '  ', 12, str_repeat('a', 1001), str_repeat(' ', 1000).'R'] as $reason) {
            $cases[] = [['reason' => $reason], 'reason', 'ficticio-void-payment-01'];
        }
        foreach ([null, '2', 0, -1, 2147483648] as $revision) {
            $cases[] = [['expected_revision' => $revision], 'expected_revision', 'ficticio-void-payment-01'];
        }
        foreach (['amount', 'method', 'reference', 'laboratory_id', 'created_by', 'recorded_at', 'target_payment_id'] as $field) {
            $cases[] = [[$field => 'injected'], $field, 'ficticio-void-payment-01'];
        }
        foreach (['', 'short', 'invalid key with spaces', str_repeat('a', 101)] as $key) {
            $cases[] = [[], 'Idempotency-Key', $key];
        }

        return $cases;
    }

    public function test_trimmed_reason_preserves_identity_and_float_revision_is_rejected(): void
    {
        [$lab, , $order, $payment] = $this->context();
        $url = '/api/v1/laboratory-orders/'.$order->id.'/payments/'.$payment.'/void';
        $this->call('POST', $url, [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_LABORATORY_ID' => (string) $lab->id, 'HTTP_IDEMPOTENCY_KEY' => 'ficticio-void-float-key'],
            '{"expected_revision":2.0,"reason":"Error ficticio"}')
            ->assertUnprocessable()->assertJsonValidationErrors('expected_revision');
        $first = $this->voidPayment($order, $payment, ['reason' => ' Error ficticio '])->assertCreated()->assertJsonPath('data.resource.reason', 'Error ficticio');
        $this->assertSame($first->json(), $this->voidPayment($order, $payment, ['reason' => 'Error ficticio'])->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->json());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_stale_revision_and_missing_required_fields_do_not_write(): void
    {
        [, , $order, $payment] = $this->context();
        $this->voidPayment($order, $payment, ['expected_revision' => 1])->assertConflict()->assertJsonPath('code', 'cash_revision_conflict');
        $url = '/api/v1/laboratory-orders/'.$order->id.'/payments/'.$payment.'/void';
        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['reason', 'expected_revision']);
        $this->assertDatabaseCount('laboratory_order_cash_movements', 1);
    }

    public function test_failed_audit_rolls_back_void_operation_and_revision(): void
    {
        [$lab, $actor, $order, $payment] = $this->context();
        DB::unprepared("CREATE FUNCTION reject_void_audit() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'audit unavailable'; END \$\$; CREATE TRIGGER reject_void_audit BEFORE INSERT ON audit_logs FOR EACH ROW WHEN (NEW.event='cash.payment_voided') EXECUTE FUNCTION reject_void_audit();");
        try {
            app(VoidOrderPayment::class)->execute($lab, $actor, $order->id, $payment,
                ['expected_revision' => 2, 'reason' => 'Error ficticio'], 'ficticio-void-payment-01');
            $this->fail('Expected audit rollback');
        } catch (QueryException $error) {
            $this->assertStringContainsString('audit unavailable', $error->getMessage());
        }
        $this->assertDatabaseCount('laboratory_order_cash_movements', 1);
        $this->assertDatabaseCount('laboratory_order_cash_operations', 1);
        $this->assertDatabaseHas('laboratory_order_financial_states', ['laboratory_order_id' => $order->id, 'revision' => 2]);
        DB::unprepared('DROP TRIGGER reject_void_audit ON audit_logs; DROP FUNCTION reject_void_audit();');
        $this->voidPayment($order, $payment)->assertCreated();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    #[DataProvider('immutableEvidence')]
    public function test_void_evidence_cannot_be_modified_or_deleted(string $table, bool $delete): void
    {
        [, , $order, $payment] = $this->context();
        $id = $this->voidPayment($order, $payment)->assertCreated()->json('data.resource.id');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->expectException(QueryException::class);
        DB::transaction(function () use ($table, $delete, $id): void {
            $query = DB::table($table);
            if ($table === 'audit_logs') {
                $query->where('event', 'cash.payment_voided');
            } elseif ($table === 'laboratory_order_cash_movements') {
                $query->where('id', $id);
            }
            $delete ? $query->delete() : $query->update($table === 'audit_logs' ? ['event' => 'changed'] : ['recorded_at' => now()->toISOString()]);
        });
    }

    public static function immutableEvidence(): array
    {
        $cases = [];
        foreach (['audit_logs', 'laboratory_order_cash_movements', 'laboratory_order_cash_operations', 'laboratory_order_financial_revisions'] as $table) {
            foreach ([false, true] as $delete) {
                $cases[] = [$table, $delete];
            }
        }

        return $cases;
    }

    public function test_migration_rollback_refuses_to_remove_void_evidence(): void
    {
        [, , $order, $payment] = $this->context();
        $this->voidPayment($order, $payment)->assertCreated();
        $migration = require database_path('migrations/2026_11_06_000003_enable_order_payment_voids.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No se puede retirar el soporte de anulaciones con evidencia existente.');
        $migration->down();
    }

    #[DataProvider('invalidSqlVoids')]
    public function test_sql_rejects_invalid_voids_even_without_the_api(array $overrides): void
    {
        [$lab, $actor, $order, $payment] = $this->context();
        $this->expectException(QueryException::class);
        DB::transaction(function () use ($lab, $actor, $order, $payment, $overrides): void {
            DB::table('laboratory_order_financial_states')->where('laboratory_order_id', $order->id)->update(['revision' => 3]);
            DB::table('laboratory_order_cash_movements')->insert(array_replace([
                'laboratory_id' => $lab->id, 'laboratory_order_id' => $order->id, 'operation_id' => 'SYNTHETIC-INVALID-VOID-001',
                'financial_revision' => 3, 'type' => 'payment_void', 'amount' => '100.00', 'currency' => $order->currency,
                'method' => null, 'reference' => null, 'reason' => 'Error ficticio', 'target_payment_id' => $payment,
                'created_by' => $actor->id, 'recorded_at' => now()->toISOString(),
            ], $overrides));
            // Even a well-shaped row cannot commit without its operation/revision/audit.
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
    }

    public static function invalidSqlVoids(): array
    {
        return [[['reason' => null]], [['reason' => '  ']], [['amount' => '1.00']], [['amount' => '101.00']],
            [['target_payment_id' => null]], [['target_payment_id' => PHP_INT_MAX]], [['method' => 'cash']],
            [['reference' => 'injected']], [['currency' => 'XYZ']], [['type' => 'refund']], [[]]];
    }

    #[DataProvider('priorRefunds')]
    public function test_prior_refunds_block_only_a_negative_result_on_a_synthetic_relation(string $extraPayment, bool $blocked): void
    {
        [$lab, $actor, $order, $payment] = $this->context();
        $revision = 2;
        if ($extraPayment !== '0.00') {
            app(RecordOrderPayment::class)->execute($lab, $actor, $order->id,
                ['expected_revision' => 2, 'amount' => $extraPayment, 'method' => 'cash'], 'ficticio-extra-payment');
            $revision = 3;
        }
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        // Session-local synthetic ledger: BE12 has no writer yet. No production
        // constraint is relaxed, and no real refund operation is enabled.
        DB::statement('CREATE TEMP TABLE laboratory_order_cash_movements (LIKE public.laboratory_order_cash_movements INCLUDING DEFAULTS)');
        DB::statement('INSERT INTO pg_temp.laboratory_order_cash_movements SELECT * FROM public.laboratory_order_cash_movements');
        try {
            DB::table('laboratory_order_cash_movements')->insert([
                'laboratory_id' => $lab->id, 'laboratory_order_id' => $order->id,
                'operation_id' => str_repeat('F', 26), 'financial_revision' => $revision,
                'type' => 'refund', 'amount' => '20.00', 'currency' => $order->currency,
                'method' => 'cash', 'reason' => 'Devolución sintética', 'created_by' => $actor->id, 'recorded_at' => now()->toISOString(),
            ]);
            $before = DB::table('laboratory_order_cash_movements')->count();
            $response = $this->voidPayment($order, $payment, ['expected_revision' => $revision]);
            if ($blocked) {
                $response->assertConflict()->assertJsonPath('code', 'cash_void_negative_net');
                $this->assertSame($before, DB::table('laboratory_order_cash_movements')->count());
                $this->assertDatabaseHas('laboratory_order_financial_states', ['laboratory_order_id' => $order->id, 'revision' => $revision]);
                $net = '80.00';
            } else {
                $response->assertCreated();
                $this->assertSame($before + 1, DB::table('laboratory_order_cash_movements')->count());
                $net = $extraPayment === '20.00' ? '0.00' : '30.00';
            }
            $this->getJson('/api/v1/laboratory-orders/'.$order->id.'/cash-summary')->assertOk()->assertJsonPath('data.net_payments', $net);
            $this->assertSame(1, DB::table('laboratory_order_cash_movements')->where('type', 'refund')->count());
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        } finally {
            DB::statement('DROP TABLE pg_temp.laboratory_order_cash_movements');
        }
    }

    public static function priorRefunds(): array
    {
        return [['0.00', true], ['20.00', false], ['50.00', false]];
    }

    public function test_migration_can_restore_and_reenable_support_without_void_evidence(): void
    {
        [, , $order, $payment] = $this->context();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $before = DB::table('laboratory_order_cash_movements')->find($payment);
        $migration = require database_path('migrations/2026_11_06_000003_enable_order_payment_voids.php');
        $migration->down();
        $migration->up();
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $this->assertEquals($before, DB::table('laboratory_order_cash_movements')->find($payment));
        $this->voidPayment($order, $payment)->assertCreated();
    }

    public function test_openapi_and_route_match_the_approved_void_contract(): void
    {
        $this->artisan('l5-swagger:generate')->assertSuccessful();
        $doc = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $path = '/api/v1/laboratory-orders/{order}/payments/{payment}/void';
        $this->assertSame([['sanctumCookie' => []]], $doc['paths'][$path]['post']['security']);
        $request = $doc['components']['schemas']['CashVoidPaymentRequest'];
        $this->assertFalse($request['additionalProperties']);
        $this->assertSame(['expected_revision', 'reason'], $request['required']);
        $this->assertSame(['expected_revision', 'reason'], array_keys($request['properties']));
        $route = collect(Route::getRoutes()->getRoutes())->first(fn ($route) => $route->uri() === ltrim($path, '/'));
        $this->assertContains('can:payments.void', $route->middleware());
        $this->assertContains('saas', $route->middleware());
    }
}

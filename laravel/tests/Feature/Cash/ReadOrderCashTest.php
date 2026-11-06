<?php

namespace Tests\Feature\Cash;

use App\Cash\CashAuthority;
use App\Models\LaboratoryOrder;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\HistoricalOrderFixture;
use Tests\TestCase;

class ReadOrderCashTest extends TestCase
{
    use RefreshDatabase;

    private function context(bool $view = true): array
    {
        $order = LaboratoryOrder::factory()->create(['total' => '80.00', 'subtotal' => '80.00']);
        $lab = $order->laboratory;
        Subscription::factory()->for($lab)->create();
        $actor = $this->createLaboratoryMember($lab);
        $this->assignDirectLaboratoryPermission($actor, $lab, CashAuthority::CREATE);
        if ($view) {
            $this->assignDirectLaboratoryPermission($actor, $lab, CashAuthority::VIEW);
        }
        $this->actingAs($actor)->withHeader('X-Laboratory-ID', (string) $lab->id);

        return [$lab, $actor, $order];
    }

    private function payment(LaboratoryOrder $order, int $revision, string $amount): void
    {
        $this->withHeader('Idempotency-Key', 'ficticio-read-payment-'.$revision)
            ->postJson('/api/v1/laboratory-orders/'.$order->id.'/payments', ['expected_revision' => $revision, 'amount' => $amount, 'method' => 'cash'])
            ->assertCreated();
    }

    private function read(LaboratoryOrder $order, string $endpoint = 'cash-summary', array $query = [])
    {
        return $this->getJson('/api/v1/laboratory-orders/'.$order->id.'/'.$endpoint.($query === [] ? '' : '?'.http_build_query($query)));
    }

    public function test_total_is_not_received_money_and_reads_do_not_write_or_advance_revision(): void
    {
        [, , $order] = $this->context();
        $before = DB::table('laboratory_order_financial_states')->first();
        $auditCount = DB::table('audit_logs')->count();
        $response = $this->read($order)->assertOk()->assertJsonPath('data.available', true)
            ->assertJsonPath('data.total', '80.00')->assertJsonPath('data.valid_payments', '0.00')
            ->assertJsonPath('data.refunds', '0.00')->assertJsonPath('data.net_payments', '0.00')
            ->assertJsonPath('data.balance_due', '80.00')->assertJsonPath('data.surplus', '0.00')
            ->assertJsonPath('data.credit', null)->assertJsonPath('data.financial_revision', 1)
            ->assertJsonPath('data.delivery_policy', $order->fresh()->delivery_policy_snapshot);
        $this->assertSame(['data'], array_keys($response->json()));
        $this->assertSame(['laboratory_order_id', 'currency', 'total', 'available', 'unavailable_reason', 'financial_revision', 'delivery_policy', 'valid_payments', 'refunds', 'net_payments', 'balance_due', 'surplus', 'credit'], array_keys($response->json('data')));
        $this->assertSame($response->json(), $this->read($order)->assertOk()->json());
        $this->read($order, 'cash-movements')->assertOk()->assertExactJson(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 15, 'total' => 0, 'financial_revision' => 1]]);
        $this->assertEquals($before, DB::table('laboratory_order_financial_states')->first());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $this->assertDatabaseCount('laboratory_order_financial_revisions', 1);
        $this->assertDatabaseCount('laboratory_order_cash_operations', 0);
    }

    public function test_partial_complete_and_surplus_are_exact_and_replay_does_not_change_summary(): void
    {
        [, , $order] = $this->context();
        foreach ([[1, '30.10', '30.10', '49.90', '0.00'], [2, '49.90', '80.00', '0.00', '0.00'], [3, '20.01', '100.01', '0.00', '20.01']] as [$revision,$amount,$net,$due,$surplus]) {
            $this->payment($order, $revision, $amount);
            $this->read($order)->assertOk()->assertJsonPath('data.net_payments', $net)
                ->assertJsonPath('data.balance_due', $due)->assertJsonPath('data.surplus', $surplus)
                ->assertJsonPath('data.financial_revision', $revision + 1);
        }
        $before = $this->read($order)->json();
        $this->payment($order, 1, '30.10');
        $this->assertSame($before, $this->read($order)->json());
        $this->assertDatabaseCount('laboratory_order_cash_movements', 3);
        $this->assertSame('80.00', $order->fresh()->total);
    }

    public function test_zero_total_and_cancelled_orders_keep_their_actual_money(): void
    {
        [$lab, , $order] = $this->context();
        $zero = LaboratoryOrder::factory()->for($lab)->create(['total' => '0.00']);
        $this->read($zero)->assertOk()->assertJsonPath('data.available', true)->assertJsonPath('data.balance_due', '0.00');
        $order->update(['status' => 'cancelled']);
        $this->read($order)->assertOk()->assertJsonPath('data.total', '80.00')->assertJsonPath('data.balance_due', '80.00');
        $this->assertDatabaseCount('laboratory_order_cash_movements', 0);
    }

    public function test_pagination_returns_server_provenance_reverse_revision_order_and_requested_empty_page(): void
    {
        [, $actor, $order] = $this->context();
        foreach ([1, 2, 3] as $revision) {
            $this->payment($order, $revision, '0.10');
        }
        $ids = DB::table('laboratory_order_cash_movements')->orderByDesc('financial_revision')->pluck('id')->all();
        $page = $this->read($order, 'cash-movements', ['page' => 1, 'per_page' => 2])->assertOk()
            ->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 2, 'per_page' => 2, 'total' => 3, 'financial_revision' => 4]);
        $this->assertSame(array_slice($ids, 0, 2), array_column($page->json('data'), 'id'));
        $page->assertJsonPath('data.0.created_by', $actor->id)->assertJsonPath('data.0.payment_status', 'active')
            ->assertJsonPath('data.0.currency', $order->currency)->assertJsonPath('data.0.amount', '0.10');
        $this->assertSame(['id', 'financial_revision', 'created_by', 'recorded_at', 'type', 'amount', 'currency', 'method', 'reference', 'reason', 'target_payment_id', 'payment_status'], array_keys($page->json('data.0')));
        $this->assertSame(array_slice($ids, 2), array_column($this->read($order, 'cash-movements', ['page' => 2, 'per_page' => 2])->json('data'), 'id'));
        foreach ([3, PHP_INT_MAX] as $page) {
            $this->read($order, 'cash-movements', ['page' => $page, 'per_page' => 2])->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.current_page', $page);
        }
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_or_unknown_query_parameters_are_rejected(string $endpoint, array $query, string $field): void
    {
        [, , $order] = $this->context();
        $this->read($order, $endpoint, $query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidQueries(): array
    {
        $cases = [];
        foreach (['0', '-1', '1.5', '1e2', 'abc', ['1'], '9223372036854775808'] as $value) {
            $cases[] = ['cash-movements', ['page' => $value], 'page'];
        }
        foreach (['0', '101', '1.5', ['1']] as $value) {
            $cases[] = ['cash-movements', ['per_page' => $value], 'per_page'];
        }
        foreach (['cash-summary', 'cash-movements'] as $endpoint) {
            foreach (['laboratory_id', 'type', 'sort', 'currency', 'expected_revision'] as $field) {
                $cases[] = [$endpoint, [$field => 'unexpected'], $field];
            }
        }
        $cases[] = ['cash-summary', ['page' => 1], 'page'];

        return $cases;
    }

    public function test_historical_summary_is_unknown_instead_of_zero_and_movements_are_unavailable(): void
    {
        [$lab, , $order] = $this->context();
        $historical = HistoricalOrderFixture::create(fn () => LaboratoryOrder::factory()->for($lab)->create(['delivery_policy_snapshot' => null, 'total' => '25.00']));
        $this->read($historical)->assertOk()->assertJsonPath('data.available', false)
            ->assertJsonPath('data.total', '25.00')->assertJsonPath('data.unavailable_reason', 'cash_context_unavailable');
        foreach (['financial_revision', 'delivery_policy', 'valid_payments', 'refunds', 'net_payments', 'balance_due', 'surplus', 'credit'] as $field) {
            $this->read($historical)->assertJsonPath('data.'.$field, null);
        }
        $this->read($historical, 'cash-movements')->assertConflict()->assertJsonPath('code', 'cash_context_unavailable');
        $this->assertDatabaseCount('laboratory_order_financial_states', 1);
    }

    public function test_new_order_without_context_returns_503_without_initializing_data(): void
    {
        [, , $order] = $this->context();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        DB::statement('ALTER TABLE laboratory_order_financial_states DISABLE TRIGGER protect_financial_state_delete');
        DB::table('laboratory_order_financial_states')->where('laboratory_order_id', $order->id)->delete();
        DB::statement('ALTER TABLE laboratory_order_financial_states ENABLE TRIGGER protect_financial_state_delete');
        foreach (['cash-summary', 'cash-movements'] as $endpoint) {
            $this->read($order, $endpoint)->assertStatus(503)->assertJsonPath('code', 'cash_evidence_unavailable');
        }
        $this->assertDatabaseCount('laboratory_order_financial_states', 0);
    }

    public function test_view_is_independent_from_create_and_is_revoked_on_subsequent_read(): void
    {
        [$lab,$actor,$order] = $this->context(false);
        foreach (['cash-summary', 'cash-movements'] as $endpoint) {
            $this->read($order, $endpoint)->assertForbidden();
        }
        $this->assignDirectLaboratoryPermission($actor, $lab, CashAuthority::VIEW);
        $this->read($order)->assertOk();
        setPermissionsTeamId($lab->id);
        $actor->revokePermissionTo(CashAuthority::VIEW);
        foreach (['cash-summary', 'cash-movements'] as $endpoint) {
            $this->read($order, $endpoint)->assertForbidden();
        }
    }

    public function test_view_permission_does_not_grant_payment_writes(): void
    {
        [$lab,$actor,$order] = $this->context();
        setPermissionsTeamId($lab->id);
        $actor->revokePermissionTo(CashAuthority::CREATE);
        $this->read($order)->assertOk();
        $this->withHeader('Idempotency-Key', 'ficticio-view-only-key')->postJson('/api/v1/laboratory-orders/'.$order->id.'/payments',
            ['expected_revision' => 1, 'amount' => '10.00', 'method' => 'cash'])->assertForbidden();
        $this->assertDatabaseCount('laboratory_order_cash_movements', 0);
    }

    public function test_session_and_saas_context_protect_both_reads(): void
    {
        foreach (['cash-summary', 'cash-movements'] as $endpoint) {
            $this->getJson('/api/v1/laboratory-orders/1/'.$endpoint)->assertUnauthorized();
        }
        [$lab,$actor,$order] = $this->context();
        $lab->subscriptions()->update(['status' => 'inactive']);
        foreach (['cash-summary', 'cash-movements'] as $endpoint) {
            $this->read($order, $endpoint)->assertForbidden();
        }
    }

    public function test_another_laboratory_is_not_visible_even_with_matching_permissions(): void
    {
        [, ,$own] = $this->context();
        $other = LaboratoryOrder::factory()->create();
        foreach (['cash-summary', 'cash-movements'] as $endpoint) {
            $this->read($other,$endpoint)->assertNotFound();
        }
        $this->read($own)->assertOk();
    }
}

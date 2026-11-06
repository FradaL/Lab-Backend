<?php

namespace Tests\Feature\OrderDeliveryPolicy;

use App\Audit\OrderAuditEvents;
use App\Models\Branch;
use App\Models\Laboratory;
use App\Models\LaboratoryOrder;
use App\Models\Patient;
use App\Models\PriceList;
use App\Models\ReportDeliveryPolicy;
use App\Models\Subscription;
use App\Models\User;
use App\ReportDelivery\ReportDeliveryPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderDeliveryPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function context(bool $configured = true, string $mode = 'full_payment_required', ?User $actor = null): array
    {
        $lab = Laboratory::factory()->create();
        $actor ??= User::factory()->create();
        $actor->laboratories()->attach($lab, ['is_active' => true]);
        Subscription::factory()->for($lab)->create(['starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
        $this->assignAllOrderPermissions($actor, $lab);
        $this->assignDirectLaboratoryPermission($actor, $lab, 'reports.delivery_policy.manage');
        if ($configured) {
            app(ReportDeliveryPolicyService::class)->update($lab, $actor, ['revision' => 0, 'mode' => $mode, 'reason' => 'FICTICIO — configuración inicial explícita']);
        }
        $payload = [
            'branch_id' => Branch::factory()->for($lab)->create()->id,
            'patient_id' => Patient::factory()->for($lab)->create()->id,
            'doctor_id' => null,
            'commercial_client_id' => null,
            'price_list_id' => PriceList::factory()->for($lab)->create()->id,
            'ordered_at' => '2026-11-06 12:00:00',
            'notes' => 'FICTICIO — prueba BE-D02-03',
        ];
        $this->actingAs($actor, 'web')->withHeader('X-Laboratory-ID', (string) $lab->id);

        return [$lab, $actor, $payload];
    }

    #[DataProvider('modes')]
    public function test_creation_returns_201_with_original_policy_and_audited_provenance(string $mode): void
    {
        $this->travelTo('2026-11-06 12:00:00 UTC');
        [$lab, $actor, $payload] = $this->context(true, $mode);

        $response = $this->postJson('/api/v1/laboratory-orders', $payload)->assertCreated();

        $expected = ['mode' => $mode, 'source_revision' => 1, 'source' => 'configured', 'configured_by' => $actor->id,
            'configured_at' => '2026-11-06T12:00:00.000000Z', 'captured_at' => '2026-11-06T12:00:00.000000Z'];
        foreach ($expected as $key => $value) {
            $response->assertJsonPath('data.delivery_policy.'.$key, $value);
        }
        ksort($expected);
        $order = LaboratoryOrder::findOrFail($response->json('data.id'));
        $this->assertSame($expected, collect($order->delivery_policy_snapshot)->sortKeys()->all());
        $audit = DB::table('audit_logs')->where('event', OrderAuditEvents::CREATED)->first();
        $this->assertSame($expected, collect(json_decode($audit->new_values, true)['delivery_policy'])->sortKeys()->all());
    }

    public static function modes(): array
    {
        return ['sin restricción' => ['unrestricted'], 'pago completo' => ['full_payment_required'], 'pago o crédito' => ['payment_or_credit']];
    }

    public function test_creation_returns_409_without_explicit_configuration_and_writes_nothing(): void
    {
        $this->context(); // Una configuración ajena no habilita el laboratorio actual.
        [$lab, $actor, $payload] = $this->context(false);
        $before = DB::table('audit_logs')->count();

        $this->postJson('/api/v1/laboratory-orders', $payload)->assertConflict()
            ->assertJsonPath('code', 'delivery_policy_not_configured');

        $this->assertDatabaseCount('laboratory_orders', 0);
        $this->assertDatabaseCount('laboratory_order_exams', 0);
        $this->assertSame($before, DB::table('audit_logs')->count());
        $this->assertFalse(ReportDeliveryPolicy::forLaboratory($lab)->exists());
    }

    public function test_configuration_change_affects_only_new_orders_and_list_and_detail_use_snapshot(): void
    {
        [$lab, $actor, $payload] = $this->context();
        $first = $this->postJson('/api/v1/laboratory-orders', $payload)->assertCreated();
        $snapshot = $first->json('data.delivery_policy');
        setPermissionsTeamId($lab->id);
        app(ReportDeliveryPolicyService::class)->update($lab, $actor, ['revision' => 1, 'mode' => 'unrestricted', 'reason' => 'FICTICIO — cambio posterior']);

        $this->postJson('/api/v1/laboratory-orders', $payload)->assertCreated()
            ->assertJsonPath('data.delivery_policy.mode', 'unrestricted')->assertJsonPath('data.delivery_policy.source_revision', 2);

        $this->getJson('/api/v1/laboratory-orders/'.$first->json('data.id'))->assertOk()->assertJsonPath('data.delivery_policy', $snapshot);
        $list = $this->getJson('/api/v1/laboratory-orders')->assertOk();
        $row = collect($list->json('data'))->firstWhere('id', $first->json('data.id'));
        $this->assertSame($snapshot, $row['delivery_policy']);
    }

    #[DataProvider('serverFields')]
    public function test_creation_rejects_client_supplied_policy_with_422(string $field): void
    {
        [$lab, $actor, $payload] = $this->context();

        $this->postJson('/api/v1/laboratory-orders', $payload + [$field => ['mode' => 'unrestricted']])
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('laboratory_orders', 0);
    }

    public static function serverFields(): array
    {
        return [['delivery_policy'], ['delivery_policy_snapshot']];
    }

    public function test_read_returns_404_for_order_of_another_laboratory(): void
    {
        [$lab, $actor, $payload] = $this->context();
        $order = $this->postJson('/api/v1/laboratory-orders', $payload)->assertCreated();
        [$otherLab] = $this->context(true, 'unrestricted', $actor);

        $this->getJson('/api/v1/laboratory-orders/'.$order->json('data.id'))->assertNotFound();

        $this->assertDatabaseCount('laboratory_orders', 1);
    }

    public function test_creation_returns_403_without_create_permission(): void
    {
        [$lab, $actor, $payload] = $this->context();
        setPermissionsTeamId($lab->id);
        $actor->revokePermissionTo('orders.create');

        $this->postJson('/api/v1/laboratory-orders', $payload)->assertForbidden();

        $this->assertDatabaseCount('laboratory_orders', 0);
    }

    public function test_exam_failure_rolls_back_header_snapshot_and_creation_audit(): void
    {
        [$lab, $actor, $payload] = $this->context();
        $before = DB::table('audit_logs')->count();

        $this->postJson('/api/v1/laboratory-orders', $payload + ['exams' => [['laboratory_exam_id' => 999999999]]])->assertUnprocessable();

        $this->assertDatabaseCount('laboratory_orders', 0);
        $this->assertSame($before, DB::table('audit_logs')->count());
    }

    public function test_creation_returns_503_when_configuration_lacks_verifiable_history(): void
    {
        [$lab, $actor, $payload] = $this->context(false);
        DB::table('report_delivery_policies')->insert([
            'laboratory_id' => $lab->id, 'revision' => 1, 'mode' => 'unrestricted',
            'updated_by' => $actor->id, 'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/laboratory-orders', $payload)->assertStatus(503)
            ->assertJsonPath('code', 'cash_evidence_unavailable');

        $this->assertDatabaseCount('laboratory_orders', 0);
    }
}

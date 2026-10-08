<?php

namespace Tests\Feature\Audit;

use App\Audit\CommercialAuditEvents;
use App\Audit\OrderAuditEvents;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CommercialClient;
use App\Models\Doctor;
use App\Models\Laboratory;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Models\Patient;
use App\Models\PriceList;
use App\Models\PriceListExam;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class LaboratoryOrderAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_audits_only_allowlisted_state_with_creator_and_tenant(): void
    {
        [$actor, $laboratory, $branch, $patient, $doctor, $client, $priceList] = $this->creationContext();

        $response = $this->request($actor, $laboratory)->postJson('/api/v1/laboratory-orders', [
            'branch_id' => $branch->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'commercial_client_id' => $client->id,
            'price_list_id' => $priceList->id,
            'ordered_at' => '2026-10-03 14:30:00',
            'notes' => 'PII/medical content excluded from audit',
        ])->assertCreated();

        $order = LaboratoryOrder::query()->sole();
        $log = AuditLog::query()->sole();
        $this->assertSame(OrderAuditEvents::CREATED, $log->event);
        $this->assertSame(OrderAuditEvents::SUBJECT_ORDER, $log->auditable_type);
        $this->assertSame($response->json('data.id'), $log->auditable_id);
        $this->assertSame($laboratory->id, $log->laboratory_id);
        $this->assertSame($actor->id, $log->user_id);
        $this->assertSame($order->created_by, $log->user_id);
        $this->assertNull($log->old_values);
        $this->assertEquals([
            'code' => $order->code,
            'branch_id' => $branch->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'commercial_client_id' => $client->id,
            'price_list_id' => $priceList->id,
            'ordered_at' => '2026-10-03 14:30:00',
            'status' => LaboratoryOrder::STATUS_PENDING,
            'currency' => 'GTQ',
        ], $log->new_values);
        $this->assertNull($log->metadata);
        $this->assertArrayNotHasKey('notes', $log->new_values);
        $this->assertArrayNotHasKey('commercial_client_name', $log->new_values);
    }

    public function test_catalog_price_change_is_audited_without_rewriting_order_line_snapshots(): void
    {
        [$actor, $laboratory, $order, $exam, $priceList, $price] = $this->orderContext();

        $first = $this->addExam($actor, $laboratory, $order, $exam)->assertCreated()->json('data.id');
        $exam->update(['code' => 'GLU-2', 'name' => 'Glucosa nueva']);
        $priceList->update(['name' => 'Lista nueva']);
        $this->request($actor, $laboratory)
            ->putJson("/api/v1/price-lists/{$priceList->id}/exams/{$exam->id}", ['price' => '40.00'])
            ->assertOk();
        $second = $this->addExam($actor, $laboratory, $order, $exam)->assertCreated()->json('data.id');

        $logs = AuditLog::query()->orderBy('id')->get();
        $added = $logs->where('event', OrderAuditEvents::EXAM_ADDED)->values();
        $changed = $logs->where('event', CommercialAuditEvents::EXAM_PRICE_CHANGED)->sole();

        $this->assertNotSame($first, $second);
        $this->assertSame([$first, $second], $added->pluck('auditable_id')->all());
        $this->assertSame([
            OrderAuditEvents::EXAM_ADDED,
            CommercialAuditEvents::EXAM_PRICE_CHANGED,
            OrderAuditEvents::EXAM_ADDED,
        ], $logs->pluck('event')->all());
        $this->assertEquals([
            'laboratory_order_id' => $order->id,
            'laboratory_exam_id' => $exam->id,
            'price_list_id' => $priceList->id,
            'exam_code' => 'GLU',
            'exam_name' => 'Glucosa',
            'price_list_name' => 'Precio particular',
            'unit_price' => '35.00',
        ], $added[0]->new_values);
        $this->assertSame(['price' => '35.00'], $changed->old_values);
        $this->assertSame(['price' => '40.00'], $changed->new_values);
        $this->assertSame($price->id, $changed->auditable_id);
        $this->assertSame('40.00', $added[1]->new_values['unit_price']);
        $this->assertSame('GLU-2', $added[1]->new_values['exam_code']);
        $this->assertSame([$actor->id], $logs->pluck('user_id')->unique()->values()->all());
        $this->assertSame([$laboratory->id], $logs->pluck('laboratory_id')->unique()->values()->all());
        $this->assertSame($actor->id, $order->fresh()->created_by);
    }

    public function test_remove_audit_retains_historical_line_after_physical_delete(): void
    {
        [$actor, $laboratory, $order, $exam, $priceList] = $this->orderContext();
        $lineId = $this->addExam($actor, $laboratory, $order, $exam)->assertCreated()->json('data.id');
        $exam->update(['code' => 'NOW', 'name' => 'Current exam']);
        $priceList->update(['name' => 'Current list']);

        $this->request($actor, $laboratory)
            ->deleteJson("/api/v1/laboratory-orders/{$order->id}/exams/{$lineId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('laboratory_order_exams', ['id' => $lineId]);
        $removed = AuditLog::query()->where('event', OrderAuditEvents::EXAM_REMOVED)->sole();
        $this->assertSame(OrderAuditEvents::SUBJECT_ORDER_EXAM, $removed->auditable_type);
        $this->assertSame($lineId, $removed->auditable_id);
        $this->assertEquals([
            'laboratory_order_id' => $order->id,
            'laboratory_exam_id' => $exam->id,
            'price_list_id' => $priceList->id,
            'exam_code' => 'GLU',
            'exam_name' => 'Glucosa',
            'price_list_name' => 'Precio particular',
            'unit_price' => '35.00',
        ], $removed->old_values);
        $this->assertNull($removed->new_values);
        $this->assertSame('0.00', $order->fresh()->total);
    }

    public function test_discount_audit_preserves_intent_clamp_updates_removal_and_noops(): void
    {
        [$actor, $laboratory, $order, $exam] = $this->orderContext();
        $this->line($laboratory, $order, $exam, '150.00');
        $order->update(['subtotal' => '150.00', 'total' => '150.00']);
        $url = "/api/v1/laboratory-orders/{$order->id}/discount";

        $this->request($actor, $laboratory)->putJson($url, ['type' => 'percentage', 'value' => '10'])->assertOk();
        $this->request($actor, $laboratory)->putJson($url, ['type' => 'amount', 'value' => '200'])->assertOk();
        $this->request($actor, $laboratory)->putJson($url, ['type' => 'amount', 'value' => '200.00'])->assertOk();
        $this->request($actor, $laboratory)->deleteJson($url)->assertOk();
        $this->request($actor, $laboratory)->deleteJson($url)->assertOk();

        $logs = AuditLog::query()->orderBy('id')->get();
        $this->assertSame([
            OrderAuditEvents::DISCOUNT_SET,
            OrderAuditEvents::DISCOUNT_SET,
            OrderAuditEvents::DISCOUNT_REMOVED,
        ], $logs->pluck('event')->all());
        $this->assertEquals([
            'discount_type' => null, 'discount_value' => null, 'discount' => '0.00',
        ], $logs[0]->old_values);
        $this->assertEquals([
            'discount_type' => 'percentage', 'discount_value' => '10.00', 'discount' => '15.00',
        ], $logs[0]->new_values);
        $this->assertEquals([
            'discount_type' => 'amount', 'discount_value' => '200.00', 'discount' => '150.00',
        ], $logs[1]->new_values);
        $this->assertEquals($logs[1]->new_values, $logs[2]->old_values);
        $this->assertEquals([
            'discount_type' => null, 'discount_value' => null, 'discount' => '0.00',
        ], $logs[2]->new_values);
        $this->assertSame($actor->id, $order->fresh()->created_by);
    }

    #[DataProvider('validTransitionProvider')]
    public function test_real_status_transitions_audit_exact_deltas(string $from, string $to): void
    {
        [$actor, $laboratory, $order] = $this->orderContext(['status' => $from]);

        $this->request($actor, $laboratory)
            ->patchJson("/api/v1/laboratory-orders/{$order->id}/status", ['status' => $to])
            ->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertSame(OrderAuditEvents::STATUS_CHANGED, $log->event);
        $this->assertSame(OrderAuditEvents::SUBJECT_ORDER, $log->auditable_type);
        $this->assertSame($order->id, $log->auditable_id);
        $this->assertSame(['status' => $from], $log->old_values);
        $this->assertSame(['status' => $to], $log->new_values);
        $this->assertSame($actor->id, $log->user_id);
        $this->assertSame($laboratory->id, $log->laboratory_id);
    }

    /** @return array<string, array{string, string}> */
    public static function validTransitionProvider(): array
    {
        return [
            'pending to in process' => ['pending', 'in_process'],
            'pending to cancelled' => ['pending', 'cancelled'],
            'in process to completed' => ['in_process', 'completed'],
            'in process to cancelled' => ['in_process', 'cancelled'],
        ];
    }

    public function test_status_same_state_invalid_terminal_and_cross_tenant_requests_write_nothing(): void
    {
        $actor = User::factory()->create();
        [$actor, $laboratory, $pending] = $this->orderContext(user: $actor);
        [, $foreignLaboratory, $foreign] = $this->orderContext(user: $actor);

        $this->requestStatus($actor, $laboratory, $pending, 'pending')->assertOk();
        $this->requestStatus($actor, $laboratory, $pending, 'completed')->assertUnprocessable();
        $terminal = LaboratoryOrder::factory()->for($laboratory)->create(['status' => 'completed']);
        $this->requestStatus($actor, $laboratory, $terminal, 'cancelled')->assertUnprocessable();
        $this->requestStatus($actor, $laboratory, $foreign, 'in_process')->assertNotFound();

        $this->assertNotSame($laboratory->id, $foreignLaboratory->id);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_non_pending_wrong_order_and_spoofed_actor_write_no_events(): void
    {
        [$actor, $laboratory, $order, $exam] = $this->orderContext(['status' => 'in_process']);
        $spoofed = User::factory()->create();

        $this->addExam($actor, $laboratory, $order, $exam)->assertUnprocessable();
        $this->request($actor, $laboratory)
            ->putJson("/api/v1/laboratory-orders/{$order->id}/discount", ['type' => 'amount', 'value' => '1.00'])
            ->assertUnprocessable();
        $this->request($actor, $laboratory)
            ->patchJson("/api/v1/laboratory-orders/{$order->id}/status", [
                'status' => 'cancelled', 'updated_by' => $spoofed->id,
            ])->assertUnprocessable()->assertJsonValidationErrors('updated_by');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_multi_membership_uses_effective_request_tenant(): void
    {
        $actor = User::factory()->create();
        [, $first] = $this->activeTenant($actor);
        [, $second, $order] = $this->orderContext(user: $actor);

        $this->requestStatus($actor, $second, $order, 'in_process')->assertOk();

        $log = AuditLog::query()->sole();
        $this->assertSame($second->id, $log->laboratory_id);
        $this->assertNotSame($first->id, $log->laboratory_id);
    }

    public function test_audit_failures_roll_back_all_mutations_and_economics(): void
    {
        [$actor, $laboratory, $branch, $patient, $doctor, $client, $priceList] = $this->creationContext();
        [, , $addOrder, $exam] = $this->orderContext(user: $actor, laboratory: $laboratory);
        $existing = $this->line($laboratory, $addOrder, $exam, '20.00');
        $addOrder->update(['subtotal' => '20.00', 'total' => '20.00']);
        [, , $removeOrder, $removeExam] = $this->orderContext(user: $actor, laboratory: $laboratory);
        $removed = $this->line($laboratory, $removeOrder, $removeExam, '50.00');
        $removeOrder->update(['subtotal' => '50.00', 'discount_type' => 'percentage', 'discount_value' => '10.00', 'discount' => '5.00', 'total' => '45.00']);
        [, , $discountOrder, $discountExam] = $this->orderContext(user: $actor, laboratory: $laboratory);
        $this->line($laboratory, $discountOrder, $discountExam, '100.00');
        $discountOrder->update(['subtotal' => '100.00', 'total' => '100.00']);
        [, , $statusOrder] = $this->orderContext(user: $actor, laboratory: $laboratory);
        $orderCount = LaboratoryOrder::query()->count();
        AuditLog::creating(static fn (): never => throw new RuntimeException('audit unavailable'));
        $this->withoutExceptionHandling();

        try {
            $this->expectAuditFailure(fn () => $this->request($actor, $laboratory)->postJson('/api/v1/laboratory-orders', [
                'branch_id' => $branch->id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id,
                'commercial_client_id' => $client->id, 'price_list_id' => $priceList->id,
                'ordered_at' => '2026-10-03 14:30:00', 'notes' => null,
            ]));
            $this->assertSame($orderCount, LaboratoryOrder::query()->count());

            $this->expectAuditFailure(fn () => $this->addExam($actor, $laboratory, $addOrder, $exam));
            $this->assertSame([$existing->id], $addOrder->orderExams()->pluck('id')->all());
            $this->assertSame('20.00', $addOrder->fresh()->total);

            $this->expectAuditFailure(fn () => $this->request($actor, $laboratory)
                ->deleteJson("/api/v1/laboratory-orders/{$removeOrder->id}/exams/{$removed->id}"));
            $this->assertDatabaseHas('laboratory_order_exams', ['id' => $removed->id, 'exam_name' => $removed->exam_name]);
            $this->assertSame(['50.00', '5.00', '45.00'], array_values($removeOrder->fresh()->only(['subtotal', 'discount', 'total'])));

            $this->expectAuditFailure(fn () => $this->request($actor, $laboratory)
                ->putJson("/api/v1/laboratory-orders/{$discountOrder->id}/discount", ['type' => 'amount', 'value' => '25.00']));
            $this->assertSame([null, null, '0.00', '100.00'], array_values($discountOrder->fresh()->only(['discount_type', 'discount_value', 'discount', 'total'])));

            $this->expectAuditFailure(fn () => $this->requestStatus($actor, $laboratory, $statusOrder, 'in_process'));
            $this->assertSame('pending', $statusOrder->fresh()->status);
        } finally {
            AuditLog::flushEventListeners();
        }

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_integrated_history_has_exact_sequence_and_reads_add_nothing(): void
    {
        [$actor, $laboratory, $branch, $patient, $doctor, $client, $priceList] = $this->creationContext();
        $exam = LaboratoryExam::factory()->for($laboratory)->create(['status' => 'active']);
        PriceListExam::factory()->for($laboratory)->create([
            'price_list_id' => $priceList->id, 'laboratory_exam_id' => $exam->id,
            'price' => '50.00', 'status' => 'active',
        ]);
        $orderId = $this->request($actor, $laboratory)->postJson('/api/v1/laboratory-orders', [
            'branch_id' => $branch->id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id,
            'commercial_client_id' => $client->id, 'price_list_id' => $priceList->id,
            'ordered_at' => '2026-10-03 14:30:00', 'notes' => null,
        ])->assertCreated()->json('data.id');
        $order = LaboratoryOrder::query()->findOrFail($orderId);
        $first = $this->addExam($actor, $laboratory, $order, $exam)->assertCreated()->json('data.id');
        $second = $this->addExam($actor, $laboratory, $order, $exam)->assertCreated()->json('data.id');
        $this->request($actor, $laboratory)->putJson("/api/v1/laboratory-orders/{$orderId}/discount", ['type' => 'percentage', 'value' => '10.00'])->assertOk();
        $this->request($actor, $laboratory)->deleteJson("/api/v1/laboratory-orders/{$orderId}/exams/{$first}")->assertNoContent();
        $this->requestStatus($actor, $laboratory, $order, 'in_process')->assertOk();
        $this->request($actor, $laboratory)->getJson('/api/v1/laboratory-orders')->assertOk();
        $this->request($actor, $laboratory)->getJson("/api/v1/laboratory-orders/{$orderId}")->assertOk();
        $this->request($actor, $laboratory)->getJson("/api/v1/laboratory-orders/{$orderId}/exams")->assertOk();

        $logs = AuditLog::query()->orderBy('id')->get();
        $this->assertSame([
            OrderAuditEvents::CREATED, OrderAuditEvents::EXAM_ADDED, OrderAuditEvents::EXAM_ADDED,
            OrderAuditEvents::DISCOUNT_SET, OrderAuditEvents::EXAM_REMOVED, OrderAuditEvents::STATUS_CHANGED,
        ], $logs->pluck('event')->all());
        $this->assertSame([$first, $second], $logs->where('event', OrderAuditEvents::EXAM_ADDED)->pluck('auditable_id')->values()->all());
        $this->assertNotSame($first, $second);
        $this->assertSame($actor->id, $order->fresh()->created_by);
    }

    /** @return array{User, Laboratory} */
    private function activeTenant(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'trial_ends_at' => null,
        ]);

        return [$user, $laboratory];
    }

    /** @return array{User, Laboratory, Branch, Patient, Doctor, CommercialClient, PriceList} */
    private function creationContext(): array
    {
        [$user, $laboratory] = $this->activeTenant();

        return [$user, $laboratory, Branch::factory()->for($laboratory)->create(),
            Patient::factory()->for($laboratory)->create(), Doctor::factory()->for($laboratory)->create(),
            CommercialClient::factory()->for($laboratory)->create(), PriceList::factory()->for($laboratory)->create([
                'name' => 'Precio particular', 'currency' => 'GTQ', 'status' => 'active',
            ])];
    }

    /** @param array<string, mixed> $attributes */
    private function orderContext(array $attributes = [], ?User $user = null, ?Laboratory $laboratory = null): array
    {
        if ($laboratory === null) {
            [$user, $laboratory] = $this->activeTenant($user);
        }
        $user ??= User::factory()->create();
        $priceListCount = PriceList::forLaboratory($laboratory)->count();
        $priceList = PriceList::factory()->for($laboratory)->create([
            'name' => $priceListCount === 0 ? 'Precio particular' : 'Precio audit '.($priceListCount + 1),
            'currency' => 'GTQ', 'status' => 'active',
        ]);
        $order = LaboratoryOrder::factory()->for($laboratory)->create(array_replace([
            'price_list_id' => $priceList->id, 'price_list_name' => $priceList->name,
            'currency' => 'GTQ', 'status' => 'pending', 'created_by' => $user->id,
        ], $attributes));
        $examCount = LaboratoryExam::forLaboratory($laboratory)->count();
        $exam = LaboratoryExam::factory()->for($laboratory)->create([
            'code' => $examCount === 0 ? 'GLU' : 'GLU-'.($examCount + 1),
            'name' => $examCount === 0 ? 'Glucosa' : 'Glucosa '.($examCount + 1),
            'status' => 'active',
        ]);
        $price = PriceListExam::factory()->for($laboratory)->create([
            'price_list_id' => $priceList->id, 'laboratory_exam_id' => $exam->id,
            'price' => '35.00', 'status' => 'active',
        ]);

        return [$user, $laboratory, $order, $exam, $priceList, $price];
    }

    private function line(Laboratory $laboratory, LaboratoryOrder $order, LaboratoryExam $exam, string $price): LaboratoryOrderExam
    {
        return LaboratoryOrderExam::factory()->for($laboratory)->create([
            'laboratory_order_id' => $order->id, 'laboratory_exam_id' => $exam->id,
            'price_list_id' => $order->price_list_id, 'unit_price' => $price,
        ]);
    }

    private function request(User $user, Laboratory $laboratory): static
    {
        $this->assignDirectLaboratoryPermission($user, $laboratory, 'exam_prices.manage');
        $this->assignAllOrderPermissions($user, $laboratory);

        return $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', (string) $laboratory->id);
    }

    private function addExam(User $user, Laboratory $laboratory, LaboratoryOrder $order, LaboratoryExam $exam): TestResponse
    {
        return $this->request($user, $laboratory)
            ->postJson("/api/v1/laboratory-orders/{$order->id}/exams", ['laboratory_exam_id' => $exam->id]);
    }

    private function requestStatus(User $user, Laboratory $laboratory, LaboratoryOrder $order, string $status): TestResponse
    {
        return $this->request($user, $laboratory)
            ->patchJson("/api/v1/laboratory-orders/{$order->id}/status", ['status' => $status]);
    }

    private function expectAuditFailure(callable $operation): void
    {
        try {
            $operation();
            $this->fail('The audit failure was not propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        }
    }
}

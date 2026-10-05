<?php

namespace Tests\Feature\Api\V1\LaboratoryOrders;

use App\Models\Branch;
use App\Models\CommercialClient;
use App\Models\Laboratory;
use App\Models\LaboratoryExam;
use App\Models\LaboratoryOrder;
use App\Models\LaboratoryOrderExam;
use App\Models\Patient;
use App\Models\PriceList;
use App\Models\PriceListExam;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class LaboratoryOrderEconomicWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_order_discount_and_frozen_workflow_stays_persistently_consistent(): void
    {
        [$user, $laboratory, $priceList, $exams, , $commercialClient] = $this->tenantWithPricedExams(['100.00', '50.00', '25.00']);
        $order = $this->createOrder($user, $laboratory, $priceList, $commercialClient);
        $commercialSnapshots = $order->only([
            'created_by', 'commercial_client_id', 'commercial_client_name', 'commercial_client_type', 'price_list_name', 'currency',
        ]);
        $this->assertPersistedEconomics($order, '0.00', null, null, '0.00', '0.00');

        $line100 = $this->addLine($user, $laboratory, $order, $exams[0]);
        $this->assertPersistedEconomics($order, '100.00', null, null, '0.00', '100.00');
        $line50 = $this->addLine($user, $laboratory, $order, $exams[1]);
        $this->assertPersistedEconomics($order, '150.00', null, null, '0.00', '150.00');

        $this->putDiscount($user, $laboratory, $order, 'percentage', '10.00')
            ->assertOk()->assertJsonPath('data.discount', '15.00')->assertJsonPath('data.total', '135.00');
        $this->assertPersistedEconomics($order, '150.00', 'percentage', '10.00', '15.00', '135.00');

        $line25 = $this->addLine($user, $laboratory, $order, $exams[2]);
        $this->assertPersistedEconomics($order, '175.00', 'percentage', '10.00', '17.50', '157.50');
        $this->deleteExam($user, $laboratory, $order, $line50);
        $this->assertPersistedEconomics($order, '125.00', 'percentage', '10.00', '12.50', '112.50');

        $this->putDiscount($user, $laboratory, $order, 'amount', '30.00')
            ->assertOk()->assertJsonPath('data.discount', '30.00')->assertJsonPath('data.total', '95.00');
        $this->assertPersistedEconomics($order, '125.00', 'amount', '30.00', '30.00', '95.00');
        $this->deleteDiscount($user, $laboratory, $order)
            ->assertOk()->assertJsonPath('data.discount_type', null)->assertJsonPath('data.total', '125.00');
        $this->assertPersistedEconomics($order, '125.00', null, null, '0.00', '125.00');

        $this->putDiscount($user, $laboratory, $order, 'percentage', '20.00')
            ->assertOk()->assertJsonPath('data.discount', '25.00')->assertJsonPath('data.total', '100.00');
        $beforeTransition = $order->fresh()->only([
            'subtotal', 'discount_type', 'discount_value', 'discount', 'taxes', 'total', 'created_by',
            'commercial_client_id', 'commercial_client_name', 'commercial_client_type', 'price_list_name', 'currency',
        ]);
        $this->request($user, $laboratory, 'patch', "/api/v1/laboratory-orders/{$order->id}/status", ['status' => 'in_process'])
            ->assertOk()->assertJsonPath('data.total', '100.00');
        $this->assertSame($beforeTransition, $order->fresh()->only(array_keys($beforeTransition)));

        $this->addExam($user, $laboratory, $order, $exams[0])->assertUnprocessable();
        $this->deleteExam($user, $laboratory, $order, $line25)->assertUnprocessable();
        $this->putDiscount($user, $laboratory, $order, 'amount', '5.00')->assertUnprocessable();
        $this->deleteDiscount($user, $laboratory, $order)->assertUnprocessable();
        $this->assertPersistedEconomics($order, '125.00', 'percentage', '20.00', '25.00', '100.00');
        $this->assertSame($commercialSnapshots, $order->fresh()->only(array_keys($commercialSnapshots)));
        $this->assertDatabaseHas('laboratory_order_exams', ['id' => $line100->id, 'unit_price' => '100.00']);
        $this->assertDatabaseHas('laboratory_order_exams', ['id' => $line25->id, 'unit_price' => '25.00']);

        $this->request($user, $laboratory, 'get', "/api/v1/laboratory-orders/{$order->id}")
            ->assertOk()->assertJsonPath('data.discount_value', '20.00')->assertJsonPath('data.total', '100.00')
            ->assertJsonMissingPath('data.exams');
        $this->request($user, $laboratory, 'get', "/api/v1/laboratory-orders/{$order->id}/exams")
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_amount_intent_recovers_after_clamp_and_can_be_removed_from_empty_order(): void
    {
        [$user, $laboratory, $priceList, $exams, , $commercialClient] = $this->tenantWithPricedExams(['70.00', '80.00', '150.00']);
        $order = $this->createOrder($user, $laboratory, $priceList, $commercialClient);
        $this->putDiscount($user, $laboratory, $order, 'amount', '100.00')->assertOk();
        $this->assertPersistedEconomics($order, '0.00', 'amount', '100.00', '0.00', '0.00');

        $line70 = $this->addLine($user, $laboratory, $order, $exams[0]);
        $this->assertPersistedEconomics($order, '70.00', 'amount', '100.00', '70.00', '0.00');
        $line80 = $this->addLine($user, $laboratory, $order, $exams[1]);
        $this->assertPersistedEconomics($order, '150.00', 'amount', '100.00', '100.00', '50.00');
        $this->deleteExam($user, $laboratory, $order, $line80)->assertNoContent();
        $this->assertPersistedEconomics($order, '70.00', 'amount', '100.00', '70.00', '0.00');
        $this->deleteExam($user, $laboratory, $order, $line70)->assertNoContent();
        $this->assertPersistedEconomics($order, '0.00', 'amount', '100.00', '0.00', '0.00');
        $this->addExam($user, $laboratory, $order, $exams[2]);
        $this->assertPersistedEconomics($order, '150.00', 'amount', '100.00', '100.00', '50.00');

        $empty = $this->createOrder($user, $laboratory, $priceList, $commercialClient);
        $this->putDiscount($user, $laboratory, $empty, 'amount', '100.00')->assertOk();
        $this->deleteDiscount($user, $laboratory, $empty)->assertOk();
        $this->assertPersistedEconomics($empty, '0.00', null, null, '0.00', '0.00');
    }

    public function test_repeated_snapshot_prices_rounding_zero_price_and_many_lines_remain_exact(): void
    {
        [$user, $laboratory, $priceList, $exams, $prices, $commercialClient] = $this->tenantWithPricedExams(['35.00', '10.05']);
        $order = $this->createOrder($user, $laboratory, $priceList, $commercialClient);
        $exam35 = $exams[0];
        $price35 = $prices[0];

        $first35 = $this->addLine($user, $laboratory, $order, $exam35);
        $exam35->update(['name' => 'Nombre nuevo', 'code' => 'NEW']);
        $priceList->update(['name' => 'Lista nueva']);
        $price35->update(['price' => '40.00']);
        $second35 = $this->addLine($user, $laboratory, $order, $exam35);
        $price35->update(['price' => '1000.00']);
        $this->putDiscount($user, $laboratory, $order, 'percentage', '10.00')->assertOk();
        $this->assertPersistedEconomics($order, '75.00', 'percentage', '10.00', '7.50', '67.50');

        $this->deleteExam($user, $laboratory, $order, $first35)->assertNoContent();
        $this->assertPersistedEconomics($order, '40.00', 'percentage', '10.00', '4.00', '36.00');
        $this->assertDatabaseHas('laboratory_order_exams', [
            'id' => $second35->id, 'unit_price' => '40.00', 'exam_name' => 'Nombre nuevo', 'exam_code' => 'NEW',
            'price_list_name' => 'Lista nueva',
        ]);
        $this->assertSame('Lista inicial', $order->fresh()->price_list_name);
        $this->assertSame('Cliente ejemplo', $order->fresh()->commercial_client_name);
        $this->assertSame(CommercialClient::TYPE_COMPANY, $order->fresh()->commercial_client_type);

        $this->deleteExam($user, $laboratory, $order, $second35)->assertNoContent();
        $ten05 = $this->addLine($user, $laboratory, $order, $exams[1]);
        $this->putDiscount($user, $laboratory, $order, 'percentage', '10.00')
            ->assertOk()->assertJsonPath('data.subtotal', '10.05')->assertJsonPath('data.discount', '1.01')
            ->assertJsonPath('data.total', '9.04');
        $this->addExam($user, $laboratory, $order, $exams[1])->assertCreated();
        $this->assertPersistedEconomics($order, '20.10', 'percentage', '10.00', '2.01', '18.09');
        $this->deleteExam($user, $laboratory, $order, $ten05)->assertNoContent();
        $this->assertPersistedEconomics($order, '10.05', 'percentage', '10.00', '1.01', '9.04');

        $order->orderExams()->delete();
        $zeroPrice = $prices[1];
        $zeroPrice->update(['price' => '0.00']);
        $this->addExam($user, $laboratory, $order, $exams[1])->assertCreated();
        $zeroLine = $order->orderExams()->latest('id')->firstOrFail();
        $this->assertPersistedEconomics($order, '0.00', 'percentage', '10.00', '0.00', '0.00');

        $zeroPrice->update(['price' => '100.00']);
        $this->addExam($user, $laboratory, $order, $exams[1])->assertCreated();
        $this->assertPersistedEconomics($order, '100.00', 'percentage', '10.00', '10.00', '90.00');
        $this->deleteExam($user, $laboratory, $order, $order->orderExams()->whereKeyNot($zeroLine->id)->firstOrFail())
            ->assertNoContent();
        $this->assertPersistedEconomics($order, '0.00', 'percentage', '10.00', '0.00', '0.00');

        $bulkOrder = $this->createOrder($user, $laboratory, $priceList, $commercialClient);
        LaboratoryOrderExam::factory()->count(100)->for($laboratory)->create([
            'laboratory_order_id' => $bulkOrder->id,
            'laboratory_exam_id' => $exam35->id,
            'price_list_id' => $priceList->id,
            'unit_price' => '1.00',
        ]);
        $lineSelects = 0;
        DB::listen(function (QueryExecuted $query) use (&$lineSelects): void {
            if (str_contains(strtolower($query->sql), 'from "laboratory_order_exams"')) {
                $lineSelects++;
            }
        });
        $this->putDiscount($user, $laboratory, $bulkOrder, 'percentage', '10.00')
            ->assertOk()->assertJsonPath('data.subtotal', '100.00')->assertJsonPath('data.discount', '10.00')
            ->assertJsonPath('data.total', '90.00');
        $this->assertSame(1, $lineSelects, 'A 100-line recalculation must load snapshots with one set-based query.');
        $this->assertPersistedEconomics($bulkOrder, '100.00', 'percentage', '10.00', '10.00', '90.00');
    }

    private function tenantWithPricedExams(array $prices): array
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'trial_ends_at' => null,
        ]);
        $priceList = PriceList::factory()->for($laboratory)->create([
            'name' => 'Lista inicial', 'currency' => 'GTQ', 'status' => PriceList::STATUS_ACTIVE,
        ]);
        $commercialClient = CommercialClient::factory()->for($laboratory)->company()->create([
            'name' => 'Cliente ejemplo',
        ]);
        $exams = [];
        $priceEntries = [];

        foreach ($prices as $index => $price) {
            $exam = LaboratoryExam::factory()->for($laboratory)->create([
                'code' => 'Q'.($index + 1), 'name' => 'Examen '.($index + 1), 'status' => LaboratoryExam::STATUS_ACTIVE,
            ]);
            $exams[] = $exam;
            $priceEntries[] = PriceListExam::factory()->for($laboratory)->create([
                'price_list_id' => $priceList->id,
                'laboratory_exam_id' => $exam->id,
                'price' => $price,
                'status' => PriceListExam::STATUS_ACTIVE,
            ]);
        }

        return [$user, $laboratory, $priceList, $exams, $priceEntries, $commercialClient];
    }

    private function createOrder(
        User $user,
        Laboratory $laboratory,
        PriceList $priceList,
        CommercialClient $commercialClient,
    ): LaboratoryOrder {
        $branch = Branch::factory()->for($laboratory)->create();
        $patient = Patient::factory()->for($laboratory)->create();
        $response = $this->request($user, $laboratory, 'post', '/api/v1/laboratory-orders', [
            'branch_id' => $branch->id,
            'patient_id' => $patient->id,
            'doctor_id' => null,
            'commercial_client_id' => $commercialClient->id,
            'price_list_id' => $priceList->id,
            'ordered_at' => '2026-10-05 10:00:00',
            'notes' => null,
        ])->assertCreated()->assertJsonPath('data.subtotal', '0.00')->assertJsonPath('data.discount', '0.00')
            ->assertJsonPath('data.taxes', '0.00')->assertJsonPath('data.total', '0.00');

        return LaboratoryOrder::query()->findOrFail($response->json('data.id'));
    }

    private function addExam(User $user, Laboratory $laboratory, LaboratoryOrder $order, LaboratoryExam $exam): TestResponse
    {
        return $this->request($user, $laboratory, 'post', "/api/v1/laboratory-orders/{$order->id}/exams", [
            'laboratory_exam_id' => $exam->id,
        ]);
    }

    private function addLine(User $user, Laboratory $laboratory, LaboratoryOrder $order, LaboratoryExam $exam): LaboratoryOrderExam
    {
        $response = $this->addExam($user, $laboratory, $order, $exam)->assertCreated();

        return LaboratoryOrderExam::query()->findOrFail($response->json('data.id'));
    }

    private function deleteExam(User $user, Laboratory $laboratory, LaboratoryOrder $order, LaboratoryOrderExam $line): TestResponse
    {
        return $this->request(
            $user,
            $laboratory,
            'delete',
            "/api/v1/laboratory-orders/{$order->id}/exams/{$line->id}",
        );
    }

    private function putDiscount(User $user, Laboratory $laboratory, LaboratoryOrder $order, string $type, string $value): TestResponse
    {
        return $this->request($user, $laboratory, 'put', "/api/v1/laboratory-orders/{$order->id}/discount", [
            'type' => $type, 'value' => $value,
        ]);
    }

    private function deleteDiscount(User $user, Laboratory $laboratory, LaboratoryOrder $order): TestResponse
    {
        return $this->request($user, $laboratory, 'delete', "/api/v1/laboratory-orders/{$order->id}/discount");
    }

    private function request(User $user, Laboratory $laboratory, string $method, string $uri, array $payload = []): TestResponse
    {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->json($method, $uri, $payload);
    }

    private function assertPersistedEconomics(
        LaboratoryOrder $order,
        string $subtotal,
        ?string $type,
        ?string $value,
        string $discount,
        string $total,
    ): void {
        $persisted = LaboratoryOrder::query()->findOrFail($order->id);
        $this->assertSame($subtotal, $persisted->subtotal);
        $this->assertSame($type, $persisted->discount_type);
        $this->assertSame($value, $persisted->discount_value);
        $this->assertSame($discount, $persisted->discount);
        $this->assertSame('0.00', $persisted->taxes);
        $this->assertSame($total, $persisted->total);

        $snapshotCents = LaboratoryOrderExam::query()
            ->where('laboratory_order_id', $order->id)
            ->pluck('unit_price')
            ->sum(fn (string $price): int => $this->toCents($price));
        $this->assertSame($subtotal, $this->fromCents($snapshotCents));
    }

    private function toCents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '00');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function fromCents(int $amount): string
    {
        return intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}

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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class LaboratoryOrderHistoricalSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_changes_and_inactivation_do_not_change_historical_lines_or_economics(): void
    {
        [$user, $laboratory, $branch, $patient, $client, $priceList, $exam, $catalogPrice] = $this->catalogContext();
        $orderId = $this->request($user, $laboratory, 'post', '/api/v1/laboratory-orders', [
            'branch_id' => $branch->id,
            'patient_id' => $patient->id,
            'doctor_id' => null,
            'commercial_client_id' => $client->id,
            'price_list_id' => $priceList->id,
            'ordered_at' => '2026-10-05 10:00:00',
            'notes' => null,
        ])->assertCreated()->json('data.id');

        $firstLineId = $this->addExam($user, $laboratory, $orderId, $exam)->json('data.id');
        $catalogPrice->update(['price' => '40.00']);
        $secondLineId = $this->addExam($user, $laboratory, $orderId, $exam)->json('data.id');

        $this->request($user, $laboratory, 'put', "/api/v1/laboratory-orders/{$orderId}/discount", [
            'type' => LaboratoryOrder::DISCOUNT_TYPE_PERCENTAGE,
            'value' => '10.00',
        ])->assertOk()
            ->assertJsonPath('data.subtotal', '75.00')
            ->assertJsonPath('data.discount', '7.50')
            ->assertJsonPath('data.taxes', '0.00')
            ->assertJsonPath('data.total', '67.50');

        $exam->update([
            'code' => 'GLU2',
            'name' => 'Glucosa en sangre',
            'status' => LaboratoryExam::STATUS_INACTIVE,
        ]);
        $priceList->update([
            'name' => 'Lista General 2027',
            'status' => PriceList::STATUS_INACTIVE,
        ]);
        $catalogPrice->update([
            'price' => '1000.00',
            'status' => PriceListExam::STATUS_INACTIVE,
        ]);
        $client->update([
            'name' => 'Cliente Renombrado',
            'type' => CommercialClient::TYPE_AGREEMENT,
            'status' => CommercialClient::STATUS_INACTIVE,
        ]);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->request($user, $laboratory, 'get', "/api/v1/laboratory-orders/{$orderId}")
            ->assertOk()
            ->assertJsonPath('data.commercial_client.name', 'Cliente Original')
            ->assertJsonPath('data.commercial_client.type', CommercialClient::TYPE_COMPANY)
            ->assertJsonPath('data.price_list.name', 'Lista General')
            ->assertJsonPath('data.price_list.currency', 'GTQ')
            ->assertJsonPath('data.currency', 'GTQ')
            ->assertJsonPath('data.subtotal', '75.00')
            ->assertJsonPath('data.discount_type', LaboratoryOrder::DISCOUNT_TYPE_PERCENTAGE)
            ->assertJsonPath('data.discount_value', '10.00')
            ->assertJsonPath('data.discount', '7.50')
            ->assertJsonPath('data.taxes', '0.00')
            ->assertJsonPath('data.total', '67.50')
            ->assertJsonMissingPath('data.exams');

        $this->request($user, $laboratory, 'get', "/api/v1/laboratory-orders/{$orderId}/exams")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $firstLineId)
            ->assertJsonPath('data.0.exam.id', $exam->id)
            ->assertJsonPath('data.0.exam.code', 'GLU')
            ->assertJsonPath('data.0.exam.name', 'Glucosa')
            ->assertJsonPath('data.0.price_list.id', $priceList->id)
            ->assertJsonPath('data.0.price_list.name', 'Lista General')
            ->assertJsonPath('data.0.unit_price', '35.00')
            ->assertJsonPath('data.1.id', $secondLineId)
            ->assertJsonPath('data.1.exam.id', $exam->id)
            ->assertJsonPath('data.1.exam.code', 'GLU')
            ->assertJsonPath('data.1.exam.name', 'Glucosa')
            ->assertJsonPath('data.1.price_list.id', $priceList->id)
            ->assertJsonPath('data.1.price_list.name', 'Lista General')
            ->assertJsonPath('data.1.unit_price', '40.00');

        $historicalReads = collect($queries);
        $this->assertCount(1, $historicalReads->filter(
            fn (string $sql): bool => str_contains($sql, 'from "laboratory_order_exams"'),
        ));
        foreach (['laboratory_exams', 'price_lists', 'price_list_exams', 'commercial_clients'] as $table) {
            $this->assertFalse($historicalReads->contains(
                fn (string $sql): bool => str_contains($sql, 'from "'.$table.'"'),
            ), "Historical reads must not query {$table}.");
        }

        $this->assertNotSame($firstLineId, $secondLineId);
        $this->assertDatabaseHas('laboratory_order_exams', [
            'id' => $firstLineId,
            'laboratory_exam_id' => $exam->id,
            'exam_code' => 'GLU',
            'exam_name' => 'Glucosa',
            'price_list_name' => 'Lista General',
            'unit_price' => '35.00',
        ]);
        $this->assertDatabaseHas('laboratory_order_exams', [
            'id' => $secondLineId,
            'laboratory_exam_id' => $exam->id,
            'exam_code' => 'GLU',
            'exam_name' => 'Glucosa',
            'price_list_name' => 'Lista General',
            'unit_price' => '40.00',
        ]);
    }

    #[DataProvider('historicalStatusProvider')]
    public function test_order_header_and_lines_remain_readable_in_every_workflow_status(string $status): void
    {
        [$user, $laboratory, , , , $priceList, $exam] = $this->catalogContext();
        $order = LaboratoryOrder::factory()->for($laboratory)->create([
            'price_list_id' => $priceList->id,
            'price_list_name' => 'Lista General',
            'currency' => 'GTQ',
            'status' => $status,
            'created_by' => $user->id,
            'subtotal' => '35.00',
            'total' => '35.00',
        ]);
        LaboratoryOrderExam::factory()->for($laboratory)->create([
            'laboratory_order_id' => $order->id,
            'laboratory_exam_id' => $exam->id,
            'price_list_id' => $priceList->id,
            'exam_code' => 'GLU',
            'exam_name' => 'Glucosa',
            'price_list_name' => 'Lista General',
            'unit_price' => '35.00',
        ]);

        $this->request($user, $laboratory, 'get', "/api/v1/laboratory-orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.status', $status)
            ->assertJsonPath('data.subtotal', '35.00')
            ->assertJsonMissingPath('data.exams');
        $this->request($user, $laboratory, 'get', "/api/v1/laboratory-orders/{$order->id}/exams")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.unit_price', '35.00');
    }

    /** @return array<string, array{string}> */
    public static function historicalStatusProvider(): array
    {
        return [
            'pending' => [LaboratoryOrder::STATUS_PENDING],
            'in process' => [LaboratoryOrder::STATUS_IN_PROCESS],
            'completed' => [LaboratoryOrder::STATUS_COMPLETED],
            'cancelled' => [LaboratoryOrder::STATUS_CANCELLED],
        ];
    }

    public function test_historical_endpoints_never_resolve_a_foreign_tenant_order(): void
    {
        [$user, $laboratoryA, , , , $priceList, $exam] = $this->catalogContext();
        $laboratoryB = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratoryB, ['is_active' => true]);
        Subscription::factory()->for($laboratoryB)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ]);
        $order = LaboratoryOrder::factory()->for($laboratoryA)->create([
            'price_list_id' => $priceList->id,
            'price_list_name' => 'Lista General',
            'currency' => 'GTQ',
            'created_by' => $user->id,
        ]);
        LaboratoryOrderExam::factory()->for($laboratoryA)->create([
            'laboratory_order_id' => $order->id,
            'laboratory_exam_id' => $exam->id,
            'price_list_id' => $priceList->id,
            'unit_price' => '35.00',
        ]);

        $this->request($user, $laboratoryB, 'get', "/api/v1/laboratory-orders/{$order->id}")
            ->assertNotFound()
            ->assertJsonMissingPath('data');
        $this->request($user, $laboratoryB, 'get', "/api/v1/laboratory-orders/{$order->id}/exams")
            ->assertNotFound()
            ->assertJsonMissingPath('data');
    }

    /**
     * @return array{User, Laboratory, Branch, Patient, CommercialClient, PriceList, LaboratoryExam, PriceListExam}
     */
    private function catalogContext(): array
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ]);
        $branch = Branch::factory()->for($laboratory)->create();
        $patient = Patient::factory()->for($laboratory)->create();
        $client = CommercialClient::factory()->for($laboratory)->company()->create([
            'name' => 'Cliente Original',
            'status' => CommercialClient::STATUS_ACTIVE,
        ]);
        $priceList = PriceList::factory()->for($laboratory)->create([
            'name' => 'Lista General',
            'currency' => 'GTQ',
            'status' => PriceList::STATUS_ACTIVE,
        ]);
        $exam = LaboratoryExam::factory()->for($laboratory)->create([
            'code' => 'GLU',
            'name' => 'Glucosa',
            'status' => LaboratoryExam::STATUS_ACTIVE,
        ]);
        $catalogPrice = PriceListExam::factory()->for($laboratory)->create([
            'price_list_id' => $priceList->id,
            'laboratory_exam_id' => $exam->id,
            'price' => '35.00',
            'status' => PriceListExam::STATUS_ACTIVE,
        ]);

        return [$user, $laboratory, $branch, $patient, $client, $priceList, $exam, $catalogPrice];
    }

    private function addExam(
        User $user,
        Laboratory $laboratory,
        int $orderId,
        LaboratoryExam $exam,
    ): TestResponse {
        return $this->request($user, $laboratory, 'post', "/api/v1/laboratory-orders/{$orderId}/exams", [
            'laboratory_exam_id' => $exam->id,
        ])->assertCreated();
    }

    /** @param array<string, mixed> $payload */
    private function request(
        User $user,
        Laboratory $laboratory,
        string $method,
        string $uri,
        array $payload = [],
    ): TestResponse {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->json($method, $uri, $payload);
    }
}

<?php

namespace Tests\Feature\Api\V1\LaboratoryOrders;

use App\Models\Branch;
use App\Models\CommercialClient;
use App\Models\Laboratory;
use App\Models\LaboratoryExam;
use App\Models\Patient;
use App\Models\PriceList;
use App\Models\PriceListExam;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class LaboratoryOrderCommercialContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_changes_do_not_rewrite_order_commercial_context(): void
    {
        [$user, $laboratory, $branch, $patient, $client, $priceList] = $this->context();
        $order = $this->createOrder($user, $laboratory, $branch, $patient, $client, $priceList)
            ->assertCreated()
            ->assertJsonPath('data.commercial_client.name', 'Cliente original')
            ->assertJsonPath('data.commercial_client.type', CommercialClient::TYPE_INSURANCE)
            ->assertJsonPath('data.price_list.name', 'Lista original')
            ->assertJsonPath('data.price_list.currency', 'GTQ')
            ->json('data.id');

        $client->update([
            'name' => 'Cliente renombrado',
            'type' => CommercialClient::TYPE_COMPANY,
            'status' => CommercialClient::STATUS_INACTIVE,
        ]);
        $priceList->update([
            'name' => 'Lista renombrada',
            'currency' => 'USD',
            'status' => PriceList::STATUS_INACTIVE,
        ]);

        $this->showOrder($user, $laboratory, $order)
            ->assertOk()
            ->assertJsonPath('data.commercial_client.id', $client->id)
            ->assertJsonPath('data.commercial_client.name', 'Cliente original')
            ->assertJsonPath('data.commercial_client.type', CommercialClient::TYPE_INSURANCE)
            ->assertJsonPath('data.price_list.id', $priceList->id)
            ->assertJsonPath('data.price_list.name', 'Lista original')
            ->assertJsonPath('data.price_list.currency', 'GTQ')
            ->assertJsonPath('data.currency', 'GTQ');
        $this->assertDatabaseHas('laboratory_orders', [
            'id' => $order,
            'commercial_client_name' => 'Cliente original',
            'commercial_client_type' => CommercialClient::TYPE_INSURANCE,
            'price_list_name' => 'Lista original',
            'currency' => 'GTQ',
        ]);
    }

    public function test_same_patient_can_have_commercial_and_particular_orders_with_distinct_contexts(): void
    {
        [$user, $laboratory, $branch, $patient, $client, $priceList] = $this->context();
        $commercialOrder = $this->createOrder($user, $laboratory, $branch, $patient, $client, $priceList)
            ->assertCreated()->json('data.id');
        $particularPriceList = PriceList::factory()->for($laboratory)->create([
            'name' => 'Lista particular',
            'currency' => 'GTQ',
            'status' => PriceList::STATUS_ACTIVE,
        ]);
        $particularOrder = $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson('/api/v1/laboratory-orders', [
                'branch_id' => $branch->id,
                'patient_id' => $patient->id,
                'doctor_id' => null,
                'commercial_client_id' => null,
                'price_list_id' => $particularPriceList->id,
                'ordered_at' => '2026-10-04 11:00:00',
                'notes' => null,
            ])->assertCreated()->json('data.id');

        $this->showOrder($user, $laboratory, $commercialOrder)
            ->assertJsonPath('data.patient.id', $patient->id)
            ->assertJsonPath('data.commercial_client.id', $client->id)
            ->assertJsonPath('data.price_list.id', $priceList->id);
        $this->showOrder($user, $laboratory, $particularOrder)
            ->assertJsonPath('data.patient.id', $patient->id)
            ->assertJsonPath('data.commercial_client', null)
            ->assertJsonPath('data.price_list.id', $particularPriceList->id)
            ->assertJsonPath('data.price_list.name', 'Lista particular');
    }

    public function test_two_orders_capture_different_catalog_moments_for_the_same_patient(): void
    {
        [$user, $laboratory, $branch, $patient, $client, $priceList] = $this->context();
        $first = $this->createOrder($user, $laboratory, $branch, $patient, $client, $priceList)
            ->assertCreated()->json('data.id');

        $client->update(['name' => 'Cliente segundo', 'type' => CommercialClient::TYPE_AGREEMENT]);
        $priceList->update(['name' => 'Lista segunda']);
        $second = $this->createOrder($user, $laboratory, $branch, $patient, $client, $priceList)
            ->assertCreated()->json('data.id');

        $this->showOrder($user, $laboratory, $first)
            ->assertJsonPath('data.patient.id', $patient->id)
            ->assertJsonPath('data.commercial_client.name', 'Cliente original')
            ->assertJsonPath('data.price_list.name', 'Lista original');
        $this->showOrder($user, $laboratory, $second)
            ->assertJsonPath('data.patient.id', $patient->id)
            ->assertJsonPath('data.commercial_client.name', 'Cliente segundo')
            ->assertJsonPath('data.commercial_client.type', CommercialClient::TYPE_AGREEMENT)
            ->assertJsonPath('data.price_list.name', 'Lista segunda');
    }

    public function test_order_and_exam_line_keep_independent_price_list_name_snapshots(): void
    {
        [$user, $laboratory, $branch, $patient, $client, $priceList] = $this->context();
        $orderId = $this->createOrder($user, $laboratory, $branch, $patient, $client, $priceList)
            ->assertCreated()->json('data.id');
        $exam = LaboratoryExam::factory()->for($laboratory)->create([
            'code' => 'GLU',
            'name' => 'Glucosa',
            'status' => LaboratoryExam::STATUS_ACTIVE,
        ]);
        PriceListExam::factory()->for($laboratory)->create([
            'price_list_id' => $priceList->id,
            'laboratory_exam_id' => $exam->id,
            'price' => '35.00',
            'status' => PriceListExam::STATUS_ACTIVE,
        ]);
        $priceList->update(['name' => 'Lista para la línea']);

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson("/api/v1/laboratory-orders/{$orderId}/exams", [
                'laboratory_exam_id' => $exam->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.price_list.name', 'Lista para la línea');

        $this->showOrder($user, $laboratory, $orderId)
            ->assertJsonPath('data.price_list.name', 'Lista original');
        $this->assertDatabaseHas('laboratory_order_exams', [
            'laboratory_order_id' => $orderId,
            'price_list_name' => 'Lista para la línea',
        ]);
    }

    /** @return array{User, Laboratory, Branch, Patient, CommercialClient, PriceList} */
    private function context(): array
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ]);

        return [
            $user,
            $laboratory,
            Branch::factory()->for($laboratory)->create(['status' => 'active']),
            Patient::factory()->for($laboratory)->create(['status' => Patient::STATUS_ACTIVE]),
            CommercialClient::factory()->for($laboratory)->create([
                'name' => 'Cliente original',
                'type' => CommercialClient::TYPE_INSURANCE,
                'status' => CommercialClient::STATUS_ACTIVE,
            ]),
            PriceList::factory()->for($laboratory)->create([
                'name' => 'Lista original',
                'currency' => 'GTQ',
                'status' => PriceList::STATUS_ACTIVE,
            ]),
        ];
    }

    private function createOrder(
        User $user,
        Laboratory $laboratory,
        Branch $branch,
        Patient $patient,
        CommercialClient $client,
        PriceList $priceList,
    ): TestResponse {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->postJson('/api/v1/laboratory-orders', [
                'branch_id' => $branch->id,
                'patient_id' => $patient->id,
                'doctor_id' => null,
                'commercial_client_id' => $client->id,
                'price_list_id' => $priceList->id,
                'ordered_at' => '2026-10-04 10:00:00',
                'notes' => null,
            ]);
    }

    private function showOrder(User $user, Laboratory $laboratory, int $orderId): TestResponse
    {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson("/api/v1/laboratory-orders/{$orderId}");
    }
}

<?php

namespace Tests\Feature\Api\V1\CommercialClients;

use App\Models\CommercialClient;
use App\Models\CommercialClientPriceList;
use App\Models\Laboratory;
use App\Models\PriceList;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CommercialClientPriceListUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_update_changes_only_requested_attributes_and_returns_exact_resource(): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $newPriceList = PriceList::factory()->for($laboratory)->create();
        $assignment = $this->assignment($laboratory, $client, $priceList);
        $createdAt = $assignment->getRawOriginal('created_at');

        $response = $this->request($user, $laboratory, $client->id, $assignment->id, [
            'price_list_id' => $newPriceList->id,
            'starts_at' => '2026-02-01',
            'ends_at' => '2026-11-30',
        ])->assertOk();

        $response->assertJsonPath('data.id', $assignment->id)
            ->assertJsonPath('data.commercial_client.id', $client->id)
            ->assertJsonPath('data.price_list.id', $newPriceList->id)
            ->assertJsonPath('data.starts_at', '2026-02-01')
            ->assertJsonPath('data.ends_at', '2026-11-30')
            ->assertJsonPath('data.status', CommercialClientPriceList::STATUS_ACTIVE);
        $this->assertSame([
            'id', 'commercial_client', 'price_list', 'starts_at', 'ends_at',
            'status', 'created_at', 'updated_at',
        ], array_keys($response->json('data')));

        $assignment = $assignment->fresh();
        $this->assertSame($laboratory->id, $assignment->laboratory_id);
        $this->assertSame($client->id, $assignment->commercial_client_id);
        $this->assertSame($newPriceList->id, $assignment->price_list_id);
        $this->assertSame(CommercialClientPriceList::STATUS_ACTIVE, $assignment->status);
        $this->assertSame($createdAt, $assignment->getRawOriginal('created_at'));
    }

    public function test_inactive_client_and_inactive_assignment_can_be_edited_without_changing_status(): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $client->update(['status' => CommercialClient::STATUS_INACTIVE]);
        $assignment = $this->assignment($laboratory, $client, $priceList, [
            'status' => CommercialClientPriceList::STATUS_INACTIVE,
        ]);

        $this->request($user, $laboratory, $client->id, $assignment->id, ['starts_at' => '2025-12-01'])
            ->assertOk()
            ->assertJsonPath('data.starts_at', '2025-12-01')
            ->assertJsonPath('data.status', CommercialClientPriceList::STATUS_INACTIVE);

        $this->assertDatabaseHas('commercial_client_price_lists', [
            'id' => $assignment->id,
            'status' => CommercialClientPriceList::STATUS_INACTIVE,
            'starts_at' => '2025-12-01 00:00:00',
        ]);
    }

    #[DataProvider('unscopedAssignmentProvider')]
    public function test_client_and_assignment_scope_return_neutral_404_before_payload_validation(string $case): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $assignment = $this->assignment($laboratory, $client, $priceList);
        $requestedClient = $client->id;
        $requestedAssignment = $assignment->id;

        if ($case === 'cross tenant client') {
            $requestedClient = CommercialClient::factory()->create()->id;
        } elseif ($case === 'other client') {
            $otherClient = CommercialClient::factory()->for($laboratory)->create();
            $requestedAssignment = $this->assignment($laboratory, $otherClient, $priceList)->id;
        } elseif ($case === 'cross tenant assignment') {
            $otherLaboratory = Laboratory::factory()->create();
            $foreignClient = CommercialClient::factory()->for($otherLaboratory)->create();
            $foreignPriceList = PriceList::factory()->for($otherLaboratory)->create();
            $requestedAssignment = $this->assignment($otherLaboratory, $foreignClient, $foreignPriceList)->id;
        } else {
            $requestedAssignment = 999999;
        }

        $this->request($user, $laboratory, $requestedClient, $requestedAssignment, ['foo' => 'invalid'])
            ->assertNotFound()
            ->assertJsonMissingValidationErrors(['foo', 'payload']);
    }

    /** @return array<string, array{string}> */
    public static function unscopedAssignmentProvider(): array
    {
        return [
            'cross tenant client' => ['cross tenant client'],
            'assignment for another client' => ['other client'],
            'cross tenant assignment' => ['cross tenant assignment'],
            'nonexistent assignment' => ['missing'],
        ];
    }

    #[DataProvider('invalidPayloadProvider')]
    public function test_partial_payload_is_strict_and_resulting_period_must_be_valid(array $payload, string $error): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $assignment = $this->assignment($laboratory, $client, $priceList);
        $original = $assignment->getAttributes();

        $this->request($user, $laboratory, $client->id, $assignment->id, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$error]);

        $this->assertEqualsCanonicalizing($original, $assignment->fresh()->getAttributes());
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidPayloadProvider(): array
    {
        return [
            'empty payload' => [[], 'payload'],
            'string identifier' => [['price_list_id' => '1'], 'price_list_id'],
            'null identifier' => [['price_list_id' => null], 'price_list_id'],
            'null start' => [['starts_at' => null], 'starts_at'],
            'short start' => [['starts_at' => '2026-2-01'], 'starts_at'],
            'impossible start' => [['starts_at' => '2026-02-30'], 'starts_at'],
            'datetime start' => [['starts_at' => '2026-02-01T00:00:00Z'], 'starts_at'],
            'array start' => [['starts_at' => ['2026-02-01']], 'starts_at'],
            'impossible end' => [['ends_at' => '2026-02-30'], 'ends_at'],
            'new start after persisted end' => [['starts_at' => '2027-01-01'], 'ends_at'],
            'new end before persisted start' => [['ends_at' => '2025-12-31'], 'ends_at'],
            'result assembled from two fields' => [['starts_at' => '2026-08-01', 'ends_at' => '2026-07-31'], 'ends_at'],
        ];
    }

    #[DataProvider('unknownFieldProvider')]
    public function test_unknown_and_server_owned_fields_are_rejected(string $field): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $assignment = $this->assignment($laboratory, $client, $priceList);

        $this->request($user, $laboratory, $client->id, $assignment->id, [$field => 'injected'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    }

    /** @return array<string, array{string}> */
    public static function unknownFieldProvider(): array
    {
        return collect([
            'status', 'laboratory_id', 'commercial_client_id', 'id', 'is_default',
            'price', 'currency', 'discount', 'branch_id', 'patient_id', 'doctor_id',
            'order_id', 'foo', 'created_at', 'updated_at',
        ])->mapWithKeys(fn (string $field): array => [$field => [$field]])->all();
    }

    public function test_explicit_null_opens_period_and_omitted_dates_remain_unchanged(): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $newPriceList = PriceList::factory()->for($laboratory)->create();
        $assignment = $this->assignment($laboratory, $client, $priceList);

        $this->request($user, $laboratory, $client->id, $assignment->id, ['ends_at' => null])
            ->assertOk()->assertJsonPath('data.ends_at', null);
        $this->request($user, $laboratory, $client->id, $assignment->id, ['price_list_id' => $newPriceList->id])
            ->assertOk()->assertJsonPath('data.starts_at', '2026-01-01')->assertJsonPath('data.ends_at', null);
    }

    public function test_same_historical_inactive_price_list_is_allowed_but_a_changed_list_must_be_active_and_tenant_scoped(): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $assignment = $this->assignment($laboratory, $client, $priceList);
        $priceList->update(['status' => PriceList::STATUS_INACTIVE]);

        $this->request($user, $laboratory, $client->id, $assignment->id, ['price_list_id' => $priceList->id])
            ->assertOk()->assertJsonPath('data.price_list.id', $priceList->id);

        $inactive = PriceList::factory()->for($laboratory)->inactive()->create();
        $foreign = PriceList::factory()->create();
        foreach ([$inactive->id, $foreign->id, 999999] as $priceListId) {
            $this->request($user, $laboratory, $client->id, $assignment->id, ['price_list_id' => $priceListId])
                ->assertUnprocessable()->assertJsonValidationErrors(['price_list_id']);
        }
    }

    public function test_exact_no_op_performs_no_update_or_overlap_query_and_preserves_timestamp(): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $assignment = $this->assignment($laboratory, $client, $priceList);
        $updatedAt = $assignment->getRawOriginal('updated_at');
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->request($user, $laboratory, $client->id, $assignment->id, [
            'price_list_id' => $priceList->id,
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-12-31',
        ])->assertOk();

        $queries = collect(DB::getQueryLog())->pluck('query')->map(strtolower(...));
        $this->assertFalse($queries->contains(fn (string $sql): bool => str_starts_with($sql, 'update "commercial_client_price_lists"')));
        $this->assertFalse($queries->contains(fn (string $sql): bool => str_contains($sql, 'select exists') && str_contains($sql, 'commercial_client_price_lists')));
        $this->assertSame($updatedAt, $assignment->fresh()->getRawOriginal('updated_at'));
    }

    public function test_real_update_advances_timestamp_and_preserves_ownership_and_status(): void
    {
        $this->travelTo('2026-10-02 10:00:00');
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $assignment = $this->assignment($laboratory, $client, $priceList);
        $this->travelTo('2026-10-02 10:00:01');

        $this->request($user, $laboratory, $client->id, $assignment->id, ['ends_at' => null])->assertOk();

        $updated = $assignment->fresh();
        $this->assertSame('2026-10-02 10:00:01', $updated->getRawOriginal('updated_at'));
        $this->assertSame($laboratory->id, $updated->laboratory_id);
        $this->assertSame($client->id, $updated->commercial_client_id);
        $this->assertSame(CommercialClientPriceList::STATUS_ACTIVE, $updated->status);
    }

    public function test_active_period_update_excludes_self_but_rejects_inclusive_overlap_even_with_another_list(): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $otherPriceList = PriceList::factory()->for($laboratory)->create();
        $first = $this->assignment($laboratory, $client, $priceList, [
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-06-30',
        ]);
        $second = $this->assignment($laboratory, $client, $otherPriceList, [
            'starts_at' => '2026-07-01',
            'ends_at' => '2026-12-31',
        ]);

        $this->request($user, $laboratory, $client->id, $first->id, ['ends_at' => '2026-06-30'])
            ->assertOk();
        $this->request($user, $laboratory, $client->id, $first->id, ['ends_at' => '2026-07-01'])
            ->assertUnprocessable()->assertJsonValidationErrors(['period']);
        $this->request($user, $laboratory, $client->id, $second->id, ['starts_at' => '2026-06-30'])
            ->assertUnprocessable()->assertJsonValidationErrors(['period']);

        $this->assertSame('2026-07-01', $second->fresh()->starts_at->toDateString());
        $this->assertSame('2026-06-30', $first->fresh()->ends_at?->toDateString());
    }

    public function test_open_ended_period_conflicts_with_a_same_day_update(): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $otherPriceList = PriceList::factory()->for($laboratory)->create();
        $assignment = $this->assignment($laboratory, $client, $priceList, [
            'starts_at' => '2025-01-01',
            'ends_at' => '2025-12-31',
        ]);
        $this->assignment($laboratory, $client, $otherPriceList, [
            'starts_at' => '2026-06-15',
            'ends_at' => null,
        ]);

        $this->request($user, $laboratory, $client->id, $assignment->id, [
            'starts_at' => '2026-06-15',
            'ends_at' => '2026-06-15',
        ])->assertUnprocessable()->assertJsonValidationErrors(['period']);
    }

    public function test_inactive_assignment_skips_overlap_checks_and_can_share_an_active_period(): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $otherPriceList = PriceList::factory()->for($laboratory)->create();
        $this->assignment($laboratory, $client, $priceList, [
            'starts_at' => '2026-01-01',
            'ends_at' => null,
        ]);
        $inactive = $this->assignment($laboratory, $client, $otherPriceList, [
            'starts_at' => '2025-01-01',
            'ends_at' => '2025-12-31',
            'status' => CommercialClientPriceList::STATUS_INACTIVE,
        ]);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->request($user, $laboratory, $client->id, $inactive->id, [
            'starts_at' => '2026-06-15',
            'ends_at' => '2026-06-15',
        ])->assertOk();

        $queries = collect(DB::getQueryLog())->pluck('query')->map(strtolower(...));
        $this->assertFalse($queries->contains(fn (string $sql): bool => str_contains($sql, 'select exists') && str_contains($sql, 'commercial_client_price_lists')));
    }

    public function test_unique_collision_is_translated_to_period_422_without_mutating_row(): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $otherPriceList = PriceList::factory()->for($laboratory)->create();
        $first = $this->assignment($laboratory, $client, $priceList, [
            'status' => CommercialClientPriceList::STATUS_INACTIVE,
        ]);
        $second = $this->assignment($laboratory, $client, $otherPriceList, [
            'status' => CommercialClientPriceList::STATUS_INACTIVE,
        ]);

        $this->request($user, $laboratory, $client->id, $first->id, ['price_list_id' => $otherPriceList->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['period']);

        $this->assertSame($priceList->id, $first->fresh()->price_list_id);
        $this->assertSame($otherPriceList->id, $second->fresh()->price_list_id);
    }

    public function test_unrelated_database_errors_are_rethrown(): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $assignment = $this->assignment($laboratory, $client, $priceList);
        $expected = new QueryException(
            'sqlite',
            'update commercial_client_price_lists set starts_at = ?',
            ['2026-02-01'],
            new \RuntimeException('unrelated database failure'),
        );
        CommercialClientPriceList::updating(function () use ($expected): never {
            throw $expected;
        });
        $this->withoutExceptionHandling();

        try {
            $this->request($user, $laboratory, $client->id, $assignment->id, ['starts_at' => '2026-02-01']);
            $this->fail('The unrelated database exception was swallowed.');
        } catch (QueryException $exception) {
            $this->assertSame($expected, $exception);
        }
    }

    public function test_saas_pipeline_and_both_numeric_route_constraints_are_enforced(): void
    {
        $this->patchJson('/api/v1/commercial-clients/1/price-list-assignments/1', ['ends_at' => null])
            ->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user, 'web')
            ->patchJson('/api/v1/commercial-clients/1/price-list-assignments/1', ['ends_at' => null])
            ->assertBadRequest();
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', '999999')
            ->patchJson('/api/v1/commercial-clients/1/price-list-assignments/1', ['ends_at' => null])
            ->assertNotFound();
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', '1')
            ->patchJson('/api/v1/commercial-clients/abc/price-list-assignments/1', ['ends_at' => null])
            ->assertNotFound();
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', '1')
            ->patchJson('/api/v1/commercial-clients/1/price-list-assignments/abc', ['ends_at' => null])
            ->assertNotFound();
    }

    public function test_update_queries_only_assignment_domain_tables(): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $assignment = $this->assignment($laboratory, $client, $priceList);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->request($user, $laboratory, $client->id, $assignment->id, ['starts_at' => '2026-02-01'])
            ->assertOk();

        $queries = collect(DB::getQueryLog())->pluck('query')->map(strtolower(...));
        foreach (['patients', 'doctors', 'branches', 'orders', 'price_list_exams'] as $table) {
            $this->assertFalse($queries->contains(fn (string $sql): bool => str_contains($sql, $table)));
        }
    }

    /** @return array{User, Laboratory, CommercialClient, PriceList} */
    private function activeContext(): array
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
            CommercialClient::factory()->for($laboratory)->create(),
            PriceList::factory()->for($laboratory)->create(),
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function assignment(
        Laboratory $laboratory,
        CommercialClient $client,
        PriceList $priceList,
        array $overrides = [],
    ): CommercialClientPriceList {
        return CommercialClientPriceList::factory()->for($laboratory)->create($overrides + [
            'commercial_client_id' => $client->id,
            'price_list_id' => $priceList->id,
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-12-31',
        ]);
    }

    private function request(
        User $user,
        Laboratory $laboratory,
        int $commercialClient,
        int $assignment,
        array $payload,
    ): TestResponse {
        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson(
                "/api/v1/commercial-clients/{$commercialClient}/price-list-assignments/{$assignment}",
                $payload,
            );
    }
}

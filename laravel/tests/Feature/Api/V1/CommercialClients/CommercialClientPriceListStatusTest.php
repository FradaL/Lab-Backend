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

class CommercialClientPriceListStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_assignment_can_be_deactivated_and_only_status_and_timestamp_change(): void
    {
        $this->travelTo('2026-10-02 10:00:00');
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $assignment = $this->assignment($laboratory, $client, $priceList);
        $before = $assignment->getAttributes();
        $otherPriceList = PriceList::factory()->for($laboratory)->create();
        $otherAssignment = $this->assignment($laboratory, $client, $otherPriceList, [
            'starts_at' => '2027-01-01',
            'ends_at' => null,
            'status' => CommercialClientPriceList::STATUS_INACTIVE,
        ]);
        $otherBefore = $otherAssignment->getAttributes();
        $this->travelTo('2026-10-02 10:00:01');

        $response = $this->request($user, $laboratory, $client->id, $assignment->id, 'inactive')
            ->assertOk();

        $response->assertJsonPath('data.id', $assignment->id)
            ->assertJsonPath('data.commercial_client.id', $client->id)
            ->assertJsonPath('data.commercial_client.name', $client->name)
            ->assertJsonPath('data.commercial_client.type', $client->type)
            ->assertJsonPath('data.price_list.id', $priceList->id)
            ->assertJsonPath('data.price_list.name', $priceList->name)
            ->assertJsonPath('data.price_list.currency', $priceList->currency)
            ->assertJsonPath('data.starts_at', '2026-01-01')
            ->assertJsonPath('data.ends_at', '2026-12-31')
            ->assertJsonPath('data.status', CommercialClientPriceList::STATUS_INACTIVE);
        $this->assertSame([
            'id', 'commercial_client', 'price_list', 'starts_at', 'ends_at',
            'status', 'created_at', 'updated_at',
        ], array_keys($response->json('data')));
        $this->assertArrayNotHasKey('laboratory_id', $response->json('data'));

        $updated = $assignment->fresh();
        foreach (['laboratory_id', 'commercial_client_id', 'price_list_id', 'starts_at', 'ends_at', 'created_at'] as $field) {
            $this->assertEquals($before[$field], $updated->getRawOriginal($field));
        }
        $this->assertSame(CommercialClientPriceList::STATUS_INACTIVE, $updated->status);
        $this->assertSame('2026-10-02 10:00:01', $updated->getRawOriginal('updated_at'));
        $this->assertEqualsCanonicalizing($otherBefore, $otherAssignment->fresh()->getAttributes());
    }

    public function test_inactive_assignment_can_be_reactivated_without_overlap(): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $this->assignment($laboratory, $client, $priceList, [
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-12-31',
        ]);
        $otherPriceList = PriceList::factory()->for($laboratory)->create();
        $target = $this->assignment($laboratory, $client, $otherPriceList, [
            'starts_at' => '2027-01-01',
            'ends_at' => null,
            'status' => CommercialClientPriceList::STATUS_INACTIVE,
        ]);

        $this->request($user, $laboratory, $client->id, $target->id, 'active')
            ->assertOk()
            ->assertJsonPath('data.status', CommercialClientPriceList::STATUS_ACTIVE)
            ->assertJsonPath('data.ends_at', null);

        $this->assertSame(CommercialClientPriceList::STATUS_ACTIVE, $target->fresh()->status);
    }

    #[DataProvider('noOpStatusProvider')]
    public function test_same_status_is_a_true_no_op_without_update_overlap_or_price_list_query(string $status): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $assignment = $this->assignment($laboratory, $client, $priceList, ['status' => $status]);
        $otherPriceList = PriceList::factory()->for($laboratory)->create();
        $otherAssignment = $this->assignment($laboratory, $client, $otherPriceList, [
            'status' => CommercialClientPriceList::STATUS_INACTIVE,
        ]);
        $otherBefore = $otherAssignment->getAttributes();
        $updatedAt = $assignment->getRawOriginal('updated_at');
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->request($user, $laboratory, $client->id, $assignment->id, $status)
            ->assertOk()->assertJsonPath('data.status', $status);

        $queries = collect(DB::getQueryLog())->pluck('query')->map(strtolower(...));
        $this->assertFalse($queries->contains(fn (string $sql): bool => str_starts_with($sql, 'update "commercial_client_price_lists"')));
        $this->assertFalse($queries->contains(fn (string $sql): bool => str_contains($sql, 'select exists') && str_contains($sql, 'commercial_client_price_lists')));
        $this->assertFalse($queries->contains(fn (string $sql): bool => str_contains($sql, 'from "price_lists"')));
        $this->assertSame($updatedAt, $assignment->fresh()->getRawOriginal('updated_at'));
        $this->assertEqualsCanonicalizing($otherBefore, $otherAssignment->fresh()->getAttributes());
    }

    /** @return array<string, array{string}> */
    public static function noOpStatusProvider(): array
    {
        return [
            'active to active' => [CommercialClientPriceList::STATUS_ACTIVE],
            'inactive to inactive' => [CommercialClientPriceList::STATUS_INACTIVE],
        ];
    }

    #[DataProvider('invalidStatusProvider')]
    public function test_status_is_a_strict_json_enum(mixed $status): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $assignment = $this->assignment($laboratory, $client, $priceList);
        $before = $assignment->getAttributes();

        $this->request($user, $laboratory, $client->id, $assignment->id, $status)
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $this->assertEqualsCanonicalizing($before, $assignment->fresh()->getAttributes());
    }

    /** @return array<string, array{mixed}> */
    public static function invalidStatusProvider(): array
    {
        return [
            'title active' => ['Active'],
            'uppercase active' => ['ACTIVE'],
            'title inactive' => ['Inactive'],
            'uppercase inactive' => ['INACTIVE'],
            'enabled' => ['enabled'],
            'disabled' => ['disabled'],
            'pending' => ['pending'],
            'expired' => ['expired'],
            'leading and trailing whitespace' => [' active '],
            'inactive whitespace' => [' inactive '],
            'empty' => [''],
            'null' => [null],
            'true' => [true],
            'false' => [false],
            'zero' => [0],
            'one' => [1],
            'array' => [['active']],
            'object' => [(object) ['status' => 'active']],
        ];
    }

    public function test_missing_status_is_rejected(): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $assignment = $this->assignment($laboratory, $client, $priceList);

        $this->requestPayload($user, $laboratory, $client->id, $assignment->id, [])
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);
    }

    #[DataProvider('unknownFieldProvider')]
    public function test_unknown_and_server_owned_fields_are_rejected(string $field): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $assignment = $this->assignment($laboratory, $client, $priceList);

        $this->requestPayload($user, $laboratory, $client->id, $assignment->id, [
            'status' => 'inactive',
            $field => 'injected',
        ])->assertUnprocessable()->assertJsonValidationErrors([$field]);

        $this->assertSame(CommercialClientPriceList::STATUS_ACTIVE, $assignment->fresh()->status);
    }

    /** @return array<string, array{string}> */
    public static function unknownFieldProvider(): array
    {
        return collect([
            'id', 'laboratory_id', 'commercial_client_id', 'price_list_id', 'starts_at',
            'ends_at', 'price', 'currency', 'is_default', 'discount', 'branch_id',
            'patient_id', 'doctor_id', 'order_id', 'created_at', 'updated_at', 'foo',
        ])->mapWithKeys(fn (string $field): array => [$field => [$field]])->all();
    }

    #[DataProvider('unscopedAssignmentProvider')]
    public function test_lookup_precedes_validation_for_unscoped_resources(string $case): void
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
        } elseif ($case === 'missing client') {
            $requestedClient = 999999;
        } else {
            $requestedAssignment = 999999;
        }

        $this->requestPayload($user, $laboratory, $requestedClient, $requestedAssignment, ['foo' => 'invalid'])
            ->assertNotFound()
            ->assertJsonMissingValidationErrors(['status', 'foo']);
    }

    /** @return array<string, array{string}> */
    public static function unscopedAssignmentProvider(): array
    {
        return [
            'cross tenant client' => ['cross tenant client'],
            'missing client' => ['missing client'],
            'assignment for another client' => ['other client'],
            'cross tenant assignment' => ['cross tenant assignment'],
            'missing assignment' => ['missing assignment'],
        ];
    }

    public function test_inactive_commercial_client_does_not_block_deactivation_or_reactivation(): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $client->update(['status' => CommercialClient::STATUS_INACTIVE]);
        $assignment = $this->assignment($laboratory, $client, $priceList);

        $this->request($user, $laboratory, $client->id, $assignment->id, 'inactive')->assertOk();
        $this->request($user, $laboratory, $client->id, $assignment->id, 'active')->assertOk();

        $this->assertSame(CommercialClient::STATUS_INACTIVE, $client->fresh()->status);
        $this->assertSame(CommercialClientPriceList::STATUS_ACTIVE, $assignment->fresh()->status);
    }

    public function test_inactive_historical_price_list_does_not_block_any_status_transition(): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $assignment = $this->assignment($laboratory, $client, $priceList);
        $priceList->update(['status' => PriceList::STATUS_INACTIVE]);

        $this->request($user, $laboratory, $client->id, $assignment->id, 'inactive')->assertOk();
        $this->request($user, $laboratory, $client->id, $assignment->id, 'inactive')->assertOk();
        $this->request($user, $laboratory, $client->id, $assignment->id, 'active')->assertOk();

        $this->assertSame(PriceList::STATUS_INACTIVE, $priceList->fresh()->status);
        $this->assertSame($priceList->id, $assignment->fresh()->price_list_id);
    }

    public function test_reactivation_overlap_is_rejected_without_mutating_either_assignment(): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $active = $this->assignment($laboratory, $client, $priceList);
        $otherPriceList = PriceList::factory()->for($laboratory)->create();
        $target = $this->assignment($laboratory, $client, $otherPriceList, [
            'starts_at' => '2026-06-01',
            'ends_at' => '2026-08-01',
            'status' => CommercialClientPriceList::STATUS_INACTIVE,
        ]);
        $activeBefore = $active->getAttributes();
        $targetBefore = $target->getAttributes();

        $this->request($user, $laboratory, $client->id, $target->id, 'active')
            ->assertUnprocessable()->assertJsonValidationErrors(['period']);

        $this->assertEqualsCanonicalizing($activeBefore, $active->fresh()->getAttributes());
        $this->assertEqualsCanonicalizing($targetBefore, $target->fresh()->getAttributes());
    }

    public function test_inclusive_boundary_conflicts_but_the_next_day_is_allowed(): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $this->assignment($laboratory, $client, $priceList);
        $otherPriceList = PriceList::factory()->for($laboratory)->create();
        $boundary = $this->assignment($laboratory, $client, $otherPriceList, [
            'starts_at' => '2026-12-31',
            'ends_at' => '2027-12-31',
            'status' => CommercialClientPriceList::STATUS_INACTIVE,
        ]);
        $allowedPriceList = PriceList::factory()->for($laboratory)->create();
        $allowed = $this->assignment($laboratory, $client, $allowedPriceList, [
            'starts_at' => '2027-01-01',
            'ends_at' => '2027-12-31',
            'status' => CommercialClientPriceList::STATUS_INACTIVE,
        ]);

        $this->request($user, $laboratory, $client->id, $boundary->id, 'active')
            ->assertUnprocessable()->assertJsonValidationErrors(['period']);
        $this->request($user, $laboratory, $client->id, $allowed->id, 'active')->assertOk();
    }

    public function test_open_ended_and_same_day_periods_use_inclusive_semantics(): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $this->assignment($laboratory, $client, $priceList, [
            'starts_at' => '2026-06-15',
            'ends_at' => null,
        ]);
        $otherPriceList = PriceList::factory()->for($laboratory)->create();
        $sameDay = $this->assignment($laboratory, $client, $otherPriceList, [
            'starts_at' => '2026-06-15',
            'ends_at' => '2026-06-15',
            'status' => CommercialClientPriceList::STATUS_INACTIVE,
        ]);

        $this->request($user, $laboratory, $client->id, $sameDay->id, 'active')
            ->assertUnprocessable()->assertJsonValidationErrors(['period']);

        $freePriceList = PriceList::factory()->for($laboratory)->create();
        $freeDay = $this->assignment($laboratory, $client, $freePriceList, [
            'starts_at' => '2026-06-14',
            'ends_at' => '2026-06-14',
            'status' => CommercialClientPriceList::STATUS_INACTIVE,
        ]);
        $this->request($user, $laboratory, $client->id, $freeDay->id, 'active')->assertOk();
    }

    public function test_assignments_for_other_clients_and_laboratories_do_not_interfere(): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $this->assignment($laboratory, $client, $priceList);
        $otherClient = CommercialClient::factory()->for($laboratory)->create();
        $otherPriceList = PriceList::factory()->for($laboratory)->create();
        $otherClientTarget = $this->assignment($laboratory, $otherClient, $otherPriceList, [
            'status' => CommercialClientPriceList::STATUS_INACTIVE,
        ]);

        $this->request($user, $laboratory, $otherClient->id, $otherClientTarget->id, 'active')->assertOk();

        $otherLaboratory = Laboratory::factory()->create();
        $foreignClient = CommercialClient::factory()->for($otherLaboratory)->create();
        $foreignPriceList = PriceList::factory()->for($otherLaboratory)->create();
        $this->assignment($otherLaboratory, $foreignClient, $foreignPriceList);
        $currentClient = CommercialClient::factory()->for($laboratory)->create();
        $currentPriceList = PriceList::factory()->for($laboratory)->create();
        $currentTarget = $this->assignment($laboratory, $currentClient, $currentPriceList, [
            'status' => CommercialClientPriceList::STATUS_INACTIVE,
        ]);

        $this->request($user, $laboratory, $currentClient->id, $currentTarget->id, 'active')->assertOk();
    }

    #[DataProvider('queryTransitionProvider')]
    public function test_real_transition_query_plan_has_no_unrelated_or_standalone_price_list_queries(
        string $initialStatus,
        string $requestedStatus,
        bool $expectsOverlapQuery,
    ): void {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $assignment = $this->assignment($laboratory, $client, $priceList, ['status' => $initialStatus]);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->request($user, $laboratory, $client->id, $assignment->id, $requestedStatus)->assertOk();

        $queries = collect(DB::getQueryLog())->pluck('query')->map(strtolower(...));
        $overlapQueries = $queries->filter(fn (string $sql): bool => str_contains($sql, 'select exists') && str_contains($sql, 'commercial_client_price_lists'));
        $this->assertCount($expectsOverlapQuery ? 1 : 0, $overlapQueries);
        $this->assertFalse($queries->contains(fn (string $sql): bool => str_contains($sql, 'from "price_lists"')));
        foreach (['patients', 'doctors', 'branches', 'orders', 'price_list_exams'] as $table) {
            $this->assertFalse($queries->contains(fn (string $sql): bool => str_contains($sql, $table)));
        }
    }

    /** @return array<string, array{string, string, bool}> */
    public static function queryTransitionProvider(): array
    {
        return [
            'deactivate' => ['active', 'inactive', false],
            'reactivate' => ['inactive', 'active', true],
        ];
    }

    public function test_unrelated_database_errors_are_rethrown(): void
    {
        [$user, $laboratory, $client, $priceList] = $this->activeContext();
        $assignment = $this->assignment($laboratory, $client, $priceList);
        $expected = new QueryException(
            'sqlite',
            'update commercial_client_price_lists set status = ?',
            ['inactive'],
            new \RuntimeException('unrelated database failure'),
        );
        CommercialClientPriceList::updating(function () use ($expected): never {
            throw $expected;
        });
        $this->withoutExceptionHandling();

        try {
            $this->request($user, $laboratory, $client->id, $assignment->id, 'inactive');
            $this->fail('The unrelated database exception was swallowed.');
        } catch (QueryException $exception) {
            $this->assertSame($expected, $exception);
        }
    }

    public function test_saas_pipeline_and_both_numeric_route_constraints_are_enforced(): void
    {
        $url = '/api/v1/commercial-clients/1/price-list-assignments/1/status';
        $this->patchJson($url, ['status' => 'inactive'])->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->patchJson($url, ['status' => 'inactive'])->assertBadRequest();
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', 'abc')
            ->patchJson($url, ['status' => 'inactive'])
            ->assertBadRequest()->assertJsonPath('code', 'INVALID_LABORATORY_CONTEXT');
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', '999999')
            ->patchJson($url, ['status' => 'inactive'])->assertNotFound();
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', '1')
            ->patchJson('/api/v1/commercial-clients/abc/price-list-assignments/1/status', ['status' => 'inactive'])
            ->assertNotFound();
        $this->actingAs($user, 'web')->withHeader('X-Laboratory-ID', '1')
            ->patchJson('/api/v1/commercial-clients/1/price-list-assignments/abc/status', ['status' => 'inactive'])
            ->assertNotFound();
    }

    public function test_saas_pipeline_rejects_invalid_laboratory_membership_and_subscription_states(): void
    {
        $user = User::factory()->create();
        $payload = ['foo' => 'invalid'];

        $withoutMembership = Laboratory::factory()->create();
        Subscription::factory()->for($withoutMembership)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ]);
        $this->requestPayload($user, $withoutMembership, 1, 1, $payload)
            ->assertForbidden()->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $inactiveMembership = Laboratory::factory()->create();
        $user->laboratories()->attach($inactiveMembership, ['is_active' => false]);
        Subscription::factory()->for($inactiveMembership)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ]);
        $this->requestPayload($user, $inactiveMembership, 1, 1, $payload)
            ->assertForbidden()->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');

        $inactiveLaboratory = Laboratory::factory()->create(['is_active' => false]);
        $user->laboratories()->attach($inactiveLaboratory, ['is_active' => true]);
        Subscription::factory()->for($inactiveLaboratory)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ]);
        $this->requestPayload($user, $inactiveLaboratory, 1, 1, $payload)
            ->assertForbidden()->assertJsonPath('code', 'LABORATORY_INACTIVE');

        $withoutSubscription = Laboratory::factory()->create();
        $user->laboratories()->attach($withoutSubscription, ['is_active' => true]);
        $this->requestPayload($user, $withoutSubscription, 1, 1, $payload)
            ->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
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
        mixed $status,
    ): TestResponse {
        return $this->requestPayload($user, $laboratory, $commercialClient, $assignment, [
            'status' => $status,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function requestPayload(
        User $user,
        Laboratory $laboratory,
        int $commercialClient,
        int $assignment,
        array $payload,
    ): TestResponse {
        $this->assignDirectLaboratoryPermission($user, $laboratory, 'commercial_price_assignments.manage');

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->patchJson(
                "/api/v1/commercial-clients/{$commercialClient}/price-list-assignments/{$assignment}/status",
                $payload,
            );
    }
}

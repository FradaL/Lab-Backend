<?php

namespace Tests\Feature\Api\V1\SampleTypes;

use App\Models\Laboratory;
use App\Models\SampleType;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SampleTypeIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00:00', 'UTC'));
    }

    public function test_empty_sample_type_list_returns_standard_pagination_metadata(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->sampleTypeRequest($user, $laboratory)
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonStructure([
                'data',
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_resource_exposes_exact_list_contract(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $sampleType = SampleType::factory()->for($laboratory)->create([
            'name' => 'Sangre',
            'status' => SampleType::STATUS_ACTIVE,
        ]);

        $response = $this->sampleTypeRequest($user, $laboratory)
            ->assertOk()
            ->assertJsonPath('data.0.id', $sampleType->id)
            ->assertJsonPath('data.0.name', 'Sangre')
            ->assertJsonPath('data.0.status', SampleType::STATUS_ACTIVE)
            ->assertJsonPath('data.0.created_at', '2026-09-24T12:00:00.000000Z')
            ->assertJsonMissingPath('data.0.laboratory_id')
            ->assertJsonMissingPath('data.0.updated_at')
            ->assertJsonMissingPath('data.0.laboratory')
            ->assertJsonMissingPath('data.0.exams');

        $this->assertEqualsCanonicalizing(
            ['id', 'name', 'status', 'created_at'],
            array_keys($response->json('data.0')),
        );
    }

    public function test_collection_is_symmetrically_isolated_and_context_switching_has_no_residue(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $bloodA = SampleType::factory()->for($labA)->create(['name' => 'Sangre']);
        $urineA = SampleType::factory()->inactive()->for($labA)->create(['name' => 'Orina']);
        $bloodB = SampleType::factory()->for($labB)->create(['name' => 'Sangre']);
        $serumB = SampleType::factory()->for($labB)->create(['name' => 'Suero']);

        $expectedA = [$urineA->id, $bloodA->id];
        $expectedB = [$bloodB->id, $serumB->id];

        $this->sampleTypeRequest($user, $labA)
            ->assertOk()
            ->assertJsonPath('data.*.id', $expectedA);
        $this->sampleTypeRequest($user, $labB)
            ->assertOk()
            ->assertJsonPath('data.*.id', $expectedB);
        $this->sampleTypeRequest($user, $labA)
            ->assertOk()
            ->assertJsonPath('data.*.id', $expectedA);
    }

    public function test_search_is_partial_case_insensitive_trimmed_and_empty_search_does_not_filter(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $blood = SampleType::factory()->for($laboratory)->create(['name' => 'Sangre']);
        SampleType::factory()->for($laboratory)->create(['name' => 'Orina']);

        foreach (['Sangre', 'sang', 'ANG', '  Sangre  '] as $search) {
            $this->sampleTypeRequest($user, $laboratory, ['search' => $search])
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $blood->id);
        }

        foreach (['', '   '] as $search) {
            $this->sampleTypeRequest($user, $laboratory, ['search' => $search])
                ->assertOk()
                ->assertJsonCount(2, 'data')
                ->assertJsonPath('meta.total', 2);
        }
    }

    public function test_search_only_matches_name_inside_the_current_laboratory(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        SampleType::factory()->inactive()->for($labA)->create(['name' => 'Orina']);
        SampleType::factory()->for($labB)->create(['name' => 'Sangre Especial']);

        foreach (['Sangre', 'inactive'] as $search) {
            $this->sampleTypeRequest($user, $labA, ['search' => $search])
                ->assertOk()
                ->assertJsonCount(0, 'data')
                ->assertJsonPath('meta.total', 0);
        }
    }

    public function test_status_filter_supports_both_states_and_defaults_to_all_within_tenant(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $active = SampleType::factory()->for($labA)->create(['name' => 'Sangre']);
        $inactive = SampleType::factory()->inactive()->for($labA)->create(['name' => 'Orina']);
        SampleType::factory()->for($labB)->create(['name' => 'Suero']);

        $this->sampleTypeRequest($user, $labA)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);

        $this->sampleTypeRequest($user, $labA, ['status' => 'active'])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id);

        $this->sampleTypeRequest($user, $labA, ['status' => 'inactive'])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inactive->id);
    }

    public function test_sorting_supports_exact_whitelist_directions_and_stable_id_tiebreaker(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $first = SampleType::factory()->for($laboratory)->create([
            'name' => 'Sangre',
            'created_at' => '2026-09-20 12:00:00',
        ]);
        $second = SampleType::factory()->for($laboratory)->create([
            'name' => 'Orina',
            'created_at' => '2026-09-20 12:00:00',
        ]);
        $third = SampleType::factory()->for($laboratory)->create([
            'name' => 'Suero',
            'created_at' => '2026-09-22 12:00:00',
        ]);

        $this->sampleTypeRequest($user, $laboratory)
            ->assertJsonPath('data.*.id', [$second->id, $first->id, $third->id]);
        $this->sampleTypeRequest($user, $laboratory, ['sort' => 'name', 'direction' => 'asc'])
            ->assertJsonPath('data.*.id', [$second->id, $first->id, $third->id]);
        $this->sampleTypeRequest($user, $laboratory, ['sort' => 'name', 'direction' => 'desc'])
            ->assertJsonPath('data.*.id', [$third->id, $first->id, $second->id]);
        $this->sampleTypeRequest($user, $laboratory, ['sort' => 'created_at', 'direction' => 'asc'])
            ->assertJsonPath('data.*.id', [$first->id, $second->id, $third->id]);
        $this->sampleTypeRequest($user, $laboratory, ['sort' => 'created_at', 'direction' => 'desc'])
            ->assertJsonPath('data.*.id', [$third->id, $first->id, $second->id]);
    }

    public function test_pagination_uses_default_custom_pages_and_maximum_size(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        foreach (range(1, 18) as $number) {
            SampleType::factory()->for($laboratory)->create([
                'name' => sprintf('Sample Type %02d', $number),
            ]);
        }

        $this->sampleTypeRequest($user, $laboratory)
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 18);
        $this->sampleTypeRequest($user, $laboratory, ['page' => 2])
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.current_page', 2);
        $this->sampleTypeRequest($user, $laboratory, ['per_page' => 5, 'page' => 2])
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 5);
        $this->sampleTypeRequest($user, $laboratory, ['per_page' => 100])
            ->assertJsonCount(18, 'data')
            ->assertJsonPath('meta.per_page', 100);
    }

    #[DataProvider('invalidQueryProvider')]
    public function test_invalid_prohibited_and_unknown_query_parameters_are_rejected(
        array $query,
        string $field,
    ): void {
        [$user, $laboratory] = $this->activeTenant();

        $this->sampleTypeRequest($user, $laboratory, $query)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidQueryProvider(): array
    {
        return [
            'invalid status' => [['status' => 'enabled'], 'status'],
            'uppercase status' => [['status' => 'ACTIVE'], 'status'],
            'title case status' => [['status' => 'Active'], 'status'],
            'numeric status' => [['status' => 1], 'status'],
            'status with whitespace' => [['status' => ' active '], 'status'],
            'sort by status' => [['sort' => 'status'], 'sort'],
            'sort by id' => [['sort' => 'id'], 'sort'],
            'sort by laboratory' => [['sort' => 'laboratory_id'], 'sort'],
            'sort by updated at' => [['sort' => 'updated_at'], 'sort'],
            'random sort' => [['sort' => 'random'], 'sort'],
            'sql injection sort' => [['sort' => 'name desc; drop table sample_types'], 'sort'],
            'long direction' => [['direction' => 'ascending'], 'direction'],
            'descending direction' => [['direction' => 'descending'], 'direction'],
            'uppercase direction' => [['direction' => 'DESC'], 'direction'],
            'invalid direction' => [['direction' => 'foo'], 'direction'],
            'per page above maximum' => [['per_page' => 101], 'per_page'],
            'per page zero' => [['per_page' => 0], 'per_page'],
            'per page negative' => [['per_page' => -1], 'per_page'],
            'per page non numeric' => [['per_page' => 'abc'], 'per_page'],
            'page zero' => [['page' => 0], 'page'],
            'page negative' => [['page' => -1], 'page'],
            'page non numeric' => [['page' => 'abc'], 'page'],
            'page decimal' => [['page' => '1.5'], 'page'],
            'laboratory id injection' => [['laboratory_id' => 999], 'laboratory_id'],
            'laboratory injection' => [['laboratory' => 999], 'laboratory'],
            'lab injection' => [['lab' => 999], 'lab'],
            'tenant injection' => [['tenant' => 999], 'tenant'],
            'institution injection' => [['institution_id' => 999], 'institution_id'],
            'unknown foo query' => [['foo' => 'bar'], 'foo'],
            'name query' => [['name' => 'Sangre'], 'name'],
            'active query' => [['active' => true], 'active'],
        ];
    }

    public function test_guest_is_rejected_without_sample_type_data(): void
    {
        $this->getJson('/api/v1/sample-types')
            ->assertUnauthorized()
            ->assertJsonMissingPath('data');
    }

    public function test_missing_laboratory_header_is_rejected_without_sample_type_data(): void
    {
        $this->actingAs(User::factory()->create(), 'web')
            ->getJson('/api/v1/sample-types')
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED')
            ->assertJsonMissingPath('data');
    }

    public function test_missing_membership_is_rejected_without_sample_type_data_or_writes(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $this->createCurrentSubscription($laboratory);
        SampleType::factory()->for($laboratory)->create();

        $this->sampleTypeRequest($user, $laboratory)
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED')
            ->assertJsonMissingPath('data');

        $this->assertDatabaseCount('sample_types', 1);
    }

    public function test_inactive_membership_is_rejected_without_sample_type_data_or_writes(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => false]);
        $this->createCurrentSubscription($laboratory);
        SampleType::factory()->for($laboratory)->create();

        $this->sampleTypeRequest($user, $laboratory)
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED')
            ->assertJsonMissingPath('data');

        $this->assertDatabaseCount('sample_types', 1);
    }

    public function test_missing_subscription_is_rejected_without_sample_type_data_or_writes(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        SampleType::factory()->for($laboratory)->create();

        $this->sampleTypeRequest($user, $laboratory)
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED')
            ->assertJsonMissingPath('data');

        $this->assertDatabaseCount('sample_types', 1);
    }

    public function test_index_uses_only_tenant_scoped_read_queries_and_does_not_mutate_data(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $sampleType = SampleType::factory()->for($laboratory)->create(['name' => 'Sangre']);
        $original = $sampleType->getAttributes();
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'sample_types')) {
                $queries[] = $query;
            }
        });

        $this->sampleTypeRequest($user, $laboratory, [
            'search' => 'sang',
            'status' => 'active',
        ])->assertOk()->assertJsonCount(1, 'data');

        $this->assertCount(2, $queries);
        $this->assertTrue(collect($queries)->every(
            fn (QueryExecuted $query): bool => str_starts_with(strtolower($query->sql), 'select')
                && str_contains($query->sql, 'laboratory_id')
                && ! str_contains(strtolower($query->sql), ' join '),
        ));
        $this->assertEquals($original, $sampleType->fresh()->getAttributes());
        $this->assertDatabaseCount('sample_types', 1);
    }

    /**
     * @return array{User, Laboratory}
     */
    private function activeTenant(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        $this->createCurrentSubscription($laboratory);

        return [$user, $laboratory];
    }

    private function createCurrentSubscription(Laboratory $laboratory): Subscription
    {
        return Subscription::factory()->for($laboratory)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'trial_ends_at' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function sampleTypeRequest(
        User $user,
        Laboratory $laboratory,
        array $query = [],
    ): TestResponse {
        $uri = '/api/v1/sample-types';

        if ($query !== []) {
            $uri .= '?'.http_build_query($query);
        }

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson($uri);
    }
}

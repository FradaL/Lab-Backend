<?php

namespace Tests\Feature\Api\V1\LaboratoryAreas;

use App\Models\Laboratory;
use App\Models\LaboratoryArea;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LaboratoryAreaIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC'));
    }

    public function test_empty_laboratory_area_list_returns_standard_pagination_metadata(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->areaRequest($user, $laboratory)
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

    public function test_resource_exposes_exact_list_contract_and_preserves_null_description(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $area = LaboratoryArea::factory()->for($laboratory)->create([
            'code' => 'HEM',
            'name' => 'Hematología',
            'description' => null,
            'status' => LaboratoryArea::STATUS_ACTIVE,
        ]);

        $response = $this->areaRequest($user, $laboratory)
            ->assertOk()
            ->assertJsonPath('data.0.id', $area->id)
            ->assertJsonPath('data.0.code', 'HEM')
            ->assertJsonPath('data.0.name', 'Hematología')
            ->assertJsonPath('data.0.description', null)
            ->assertJsonPath('data.0.status', LaboratoryArea::STATUS_ACTIVE)
            ->assertJsonPath('data.0.created_at', '2026-09-23T12:00:00.000000Z')
            ->assertJsonMissingPath('data.0.laboratory_id')
            ->assertJsonMissingPath('data.0.updated_at')
            ->assertJsonMissingPath('data.0.laboratory')
            ->assertJsonMissingPath('data.0.exams');

        $this->assertEqualsCanonicalizing(
            ['id', 'code', 'name', 'description', 'status', 'created_at'],
            array_keys($response->json('data.0')),
        );
    }

    public function test_collection_is_symmetrically_isolated_to_the_current_laboratory(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        LaboratoryArea::factory()->for($labA)->create(['code' => 'HEM', 'name' => 'Hematología']);
        LaboratoryArea::factory()->for($labA)->create(['code' => 'QUI', 'name' => 'Química Clínica']);
        LaboratoryArea::factory()->for($labB)->create(['code' => 'HEM', 'name' => 'Hematología Especial']);
        LaboratoryArea::factory()->for($labB)->create(['code' => 'MIC', 'name' => 'Microbiología']);

        $responseA = $this->areaRequest($user, $labA)->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(['HEM', 'QUI'], $responseA->json('data.*.code'));

        $responseB = $this->areaRequest($user, $labB)->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(['HEM', 'MIC'], $responseB->json('data.*.code'));
    }

    public function test_grouped_search_never_leaks_code_or_name_from_another_laboratory(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        LaboratoryArea::factory()->for($labA)->create(['code' => 'HEM', 'name' => 'Hematología']);
        LaboratoryArea::factory()->for($labB)->create(['code' => 'XYZ', 'name' => 'Hematología Especial']);

        foreach (['especial', 'XYZ'] as $search) {
            $this->areaRequest($user, $labA, ['search' => $search])
                ->assertOk()
                ->assertJsonCount(0, 'data')
                ->assertJsonPath('meta.total', 0);
        }
    }

    public function test_search_supports_code_name_partial_case_insensitivity_trim_and_empty_values(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $hematology = LaboratoryArea::factory()->for($laboratory)->create([
            'code' => 'HEM',
            'name' => 'Hematología',
        ]);
        $chemistry = LaboratoryArea::factory()->for($laboratory)->create([
            'code' => 'QUI',
            'name' => 'Química Clínica',
        ]);

        foreach (['HEM', 'hem', 'EM', '  hema  ', 'HEMATOLOGÍA', 'matol'] as $search) {
            $this->areaRequest($user, $laboratory, ['search' => $search])
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $hematology->id);
        }

        $this->areaRequest($user, $laboratory, ['search' => 'química'])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $chemistry->id);

        foreach (['', '   '] as $search) {
            $this->areaRequest($user, $laboratory, ['search' => $search])
                ->assertOk()
                ->assertJsonCount(2, 'data')
                ->assertJsonPath('meta.total', 2);
        }
    }

    public function test_search_does_not_match_description_or_status(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        LaboratoryArea::factory()->for($laboratory)->create([
            'code' => 'HEM',
            'name' => 'Hematología',
            'description' => 'Marcador ultrasecreto',
            'status' => LaboratoryArea::STATUS_INACTIVE,
        ]);

        foreach (['ultrasecreto', 'inactive'] as $search) {
            $this->areaRequest($user, $laboratory, ['search' => $search])
                ->assertOk()
                ->assertJsonCount(0, 'data');
        }
    }

    public function test_status_filter_supports_both_states_and_defaults_to_all(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $active = LaboratoryArea::factory()->for($laboratory)->create([
            'status' => LaboratoryArea::STATUS_ACTIVE,
        ]);
        $inactive = LaboratoryArea::factory()->for($laboratory)->create([
            'status' => LaboratoryArea::STATUS_INACTIVE,
        ]);

        $this->areaRequest($user, $laboratory)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);

        $this->areaRequest($user, $laboratory, ['status' => 'active'])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id);

        $this->areaRequest($user, $laboratory, ['status' => 'inactive'])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inactive->id);
    }

    public function test_search_and_status_are_combined_inside_the_tenant(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $expected = LaboratoryArea::factory()->for($labA)->create([
            'code' => 'HEM',
            'name' => 'Hematología',
            'status' => LaboratoryArea::STATUS_ACTIVE,
        ]);
        LaboratoryArea::factory()->for($labA)->create([
            'code' => 'HEM-I',
            'name' => 'Hematología Inactiva',
            'status' => LaboratoryArea::STATUS_INACTIVE,
        ]);
        LaboratoryArea::factory()->for($labB)->create([
            'code' => 'HEM-B',
            'name' => 'Hematología Externa',
            'status' => LaboratoryArea::STATUS_ACTIVE,
        ]);

        $this->areaRequest($user, $labA, ['search' => 'hema', 'status' => 'active'])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $expected->id)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_sorting_supports_whitelisted_columns_direction_and_stable_id_tiebreaker(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $first = LaboratoryArea::factory()->for($laboratory)->create([
            'code' => 'URO',
            'name' => 'Urología',
            'created_at' => '2026-09-20 12:00:00',
        ]);
        $second = LaboratoryArea::factory()->for($laboratory)->create([
            'code' => 'HEM',
            'name' => 'Hematología',
            'created_at' => '2026-09-20 12:00:00',
        ]);
        $third = LaboratoryArea::factory()->for($laboratory)->create([
            'code' => 'MIC',
            'name' => 'Microbiología',
            'created_at' => '2026-09-22 12:00:00',
        ]);

        $this->areaRequest($user, $laboratory)
            ->assertJsonPath('data.*.id', [$second->id, $third->id, $first->id]);

        $this->areaRequest($user, $laboratory, ['sort' => 'code', 'direction' => 'desc'])
            ->assertJsonPath('data.*.id', [$first->id, $third->id, $second->id]);

        $this->areaRequest($user, $laboratory, ['sort' => 'name', 'direction' => 'desc'])
            ->assertJsonPath('data.*.id', [$first->id, $third->id, $second->id]);

        $this->areaRequest($user, $laboratory, ['sort' => 'created_at', 'direction' => 'asc'])
            ->assertJsonPath('data.*.id', [$first->id, $second->id, $third->id]);

        $this->areaRequest($user, $laboratory, ['sort' => 'created_at', 'direction' => 'desc'])
            ->assertJsonPath('data.*.id', [$third->id, $first->id, $second->id]);
    }

    public function test_pagination_uses_laravel_defaults_custom_pages_and_maximum_size(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        foreach (range(1, 18) as $number) {
            LaboratoryArea::factory()->for($laboratory)->create([
                'code' => sprintf('A%02d', $number),
                'name' => sprintf('Area %02d', $number),
            ]);
        }

        $this->areaRequest($user, $laboratory)
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 18);

        $this->areaRequest($user, $laboratory, ['page' => 2])
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.current_page', 2);

        $this->areaRequest($user, $laboratory, ['per_page' => 5, 'page' => 2])
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 5);

        $this->areaRequest($user, $laboratory, ['per_page' => 100])
            ->assertJsonCount(18, 'data')
            ->assertJsonPath('meta.per_page', 100);
    }

    #[DataProvider('invalidQueryProvider')]
    public function test_invalid_prohibited_and_unknown_query_parameters_are_rejected(
        array $query,
        string $field,
    ): void {
        [$user, $laboratory] = $this->activeTenant();

        $this->areaRequest($user, $laboratory, $query)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidQueryProvider(): array
    {
        return [
            'invalid status' => [['status' => 'pending'], 'status'],
            'uppercase status' => [['status' => 'ACTIVE'], 'status'],
            'invalid sort' => [['sort' => 'laboratory_id'], 'sort'],
            'sql injection sort' => [['sort' => 'name desc; drop table laboratory_areas'], 'sort'],
            'invalid direction' => [['direction' => 'ascending'], 'direction'],
            'uppercase direction' => [['direction' => 'DESC'], 'direction'],
            'per page above maximum' => [['per_page' => 101], 'per_page'],
            'per page zero' => [['per_page' => 0], 'per_page'],
            'per page negative' => [['per_page' => -1], 'per_page'],
            'page zero' => [['page' => 0], 'page'],
            'page negative' => [['page' => -1], 'page'],
            'page non numeric' => [['page' => 'abc'], 'page'],
            'laboratory id query' => [['laboratory_id' => 999], 'laboratory_id'],
            'laboratory alias query' => [['lab' => 999], 'lab'],
            'unknown foo query' => [['foo' => 'bar'], 'foo'],
            'code query' => [['code' => 'HEM'], 'code'],
            'name query' => [['name' => 'Hematología'], 'name'],
            'description query' => [['description' => 'texto'], 'description'],
        ];
    }

    public function test_guest_is_rejected_without_area_data(): void
    {
        $this->getJson('/api/v1/laboratory-areas')
            ->assertUnauthorized()
            ->assertJsonMissingPath('data');
    }

    public function test_missing_laboratory_header_is_rejected_without_area_data(): void
    {
        $this->actingAs(User::factory()->create(), 'web')
            ->getJson('/api/v1/laboratory-areas')
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED')
            ->assertJsonMissingPath('data');
    }

    public function test_inactive_membership_is_rejected_without_area_data(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => false]);
        $this->createCurrentSubscription($laboratory);
        LaboratoryArea::factory()->for($laboratory)->create();

        $this->areaRequest($user, $laboratory)
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED')
            ->assertJsonMissingPath('data');
    }

    public function test_missing_subscription_is_rejected_without_area_data(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);
        LaboratoryArea::factory()->for($laboratory)->create();

        $this->areaRequest($user, $laboratory)
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED')
            ->assertJsonMissingPath('data');
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
    private function areaRequest(
        User $user,
        Laboratory $laboratory,
        array $query = [],
    ): TestResponse {
        $uri = '/api/v1/laboratory-areas';

        if ($query !== []) {
            $uri .= '?'.http_build_query($query);
        }

        $this->assignDirectLaboratoryPermission($user, $laboratory, 'laboratory_areas.view');

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson($uri);
    }
}

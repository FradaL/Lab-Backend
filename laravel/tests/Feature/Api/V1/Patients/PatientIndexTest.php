<?php

namespace Tests\Feature\Api\V1\Patients;

use App\Models\Laboratory;
use App\Models\Patient;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PatientIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-22 12:00:00', 'UTC'));
    }

    public function test_empty_patient_list_returns_standard_pagination_metadata(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->patientRequest($user, $laboratory)
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

    public function test_patient_resource_exposes_only_the_list_contract_with_explicit_dates(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $patient = Patient::factory()->for($laboratory)->create([
            'first_names' => 'Daniel',
            'last_names' => 'Lara',
            'birth_date' => '1990-01-01',
            'gender' => 'male',
            'phone' => null,
            'mobile' => '55555555',
            'email' => null,
            'affiliation_number' => null,
            'status' => Patient::STATUS_ACTIVE,
        ]);

        $this->patientRequest($user, $laboratory)
            ->assertOk()
            ->assertJsonPath('data.0.id', $patient->id)
            ->assertJsonPath('data.0.first_names', 'Daniel')
            ->assertJsonPath('data.0.last_names', 'Lara')
            ->assertJsonPath('data.0.birth_date', '1990-01-01')
            ->assertJsonPath('data.0.created_at', '2026-09-22T12:00:00.000000Z')
            ->assertJsonMissingPath('data.0.laboratory_id')
            ->assertJsonMissingPath('data.0.address')
            ->assertJsonMissingPath('data.0.weight')
            ->assertJsonMissingPath('data.0.height')
            ->assertJsonMissingPath('data.0.notes')
            ->assertJsonMissingPath('data.0.updated_at');
    }

    public function test_search_is_grouped_and_never_escapes_the_current_laboratory(): void
    {
        [$user, $labA] = $this->activeTenant();
        [, $labB] = $this->activeTenant();

        $danielA = Patient::factory()->for($labA)->create([
            'first_names' => 'Daniel',
            'last_names' => 'Lara',
        ]);
        Patient::factory()->for($labA)->create([
            'first_names' => 'Ana',
            'last_names' => 'Lopez',
        ]);
        Patient::factory()->for($labB)->create([
            'first_names' => 'Daniel',
            'last_names' => 'Ramirez',
        ]);
        Patient::factory()->for($labB)->create([
            'first_names' => 'Pedro',
            'last_names' => 'Lara',
        ]);

        $this->patientRequest($user, $labA, ['search' => 'Daniel'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $danielA->id)
            ->assertJsonPath('meta.total', 1);

        $this->patientRequest($user, $labA, ['search' => 'Lara'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $danielA->id);
    }

    public function test_same_user_can_switch_laboratories_without_residual_context(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $patientA = Patient::factory()->for($labA)->create();
        $patientB = Patient::factory()->for($labB)->create();

        $this->patientRequest($user, $labA)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $patientA->id);

        $this->patientRequest($user, $labB)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $patientB->id);
    }

    public function test_guest_is_rejected_by_the_saas_pipeline(): void
    {
        $this->getJson('/api/v1/patients')->assertUnauthorized();
    }

    public function test_missing_laboratory_header_is_rejected_by_the_saas_pipeline(): void
    {
        $this->actingAs(User::factory()->create(), 'web')
            ->getJson('/api/v1/patients')
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');
    }

    public function test_missing_membership_is_rejected_by_the_saas_pipeline(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $this->createCurrentSubscription($laboratory);

        $this->patientRequest($user, $laboratory)
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');
    }

    public function test_missing_subscription_is_rejected_by_the_saas_pipeline(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);

        $this->patientRequest($user, $laboratory)
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_search_supports_names_last_names_case_insensitivity_trimming_and_empty_values(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $daniel = Patient::factory()->for($laboratory)->create([
            'first_names' => 'Daniel',
            'last_names' => 'Lara',
        ]);
        $ana = Patient::factory()->for($laboratory)->create([
            'first_names' => 'Ana',
            'last_names' => 'Lopez',
        ]);
        Patient::factory()->for($laboratory)->create([
            'first_names' => 'Carlos',
            'last_names' => 'Perez',
        ]);

        $this->patientRequest($user, $laboratory, ['search' => '  dAnIeL  '])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $daniel->id);

        $this->patientRequest($user, $laboratory, ['search' => 'lOpEz'])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ana->id);

        $this->patientRequest($user, $laboratory, ['search' => 'Nobody'])
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);

        $this->patientRequest($user, $laboratory, ['search' => '   '])
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3);
    }

    public function test_status_filter_supports_both_states_and_defaults_to_all(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $active = Patient::factory()->for($laboratory)->create([
            'status' => Patient::STATUS_ACTIVE,
        ]);
        $inactive = Patient::factory()->for($laboratory)->create([
            'status' => Patient::STATUS_INACTIVE,
        ]);

        $this->patientRequest($user, $laboratory)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);

        $this->patientRequest($user, $laboratory, ['status' => 'active'])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id);

        $this->patientRequest($user, $laboratory, ['status' => 'inactive'])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inactive->id);
    }

    public function test_sorting_uses_whitelisted_columns_direction_and_stable_id_tiebreaker(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $first = Patient::factory()->for($laboratory)->create([
            'first_names' => 'Ana',
            'last_names' => 'Same',
            'created_at' => '2026-09-20 12:00:00',
        ]);
        $second = Patient::factory()->for($laboratory)->create([
            'first_names' => 'Zoe',
            'last_names' => 'Same',
            'created_at' => '2026-09-22 11:00:00',
        ]);
        $third = Patient::factory()->for($laboratory)->create([
            'first_names' => 'Carlos',
            'last_names' => 'Alpha',
            'created_at' => '2026-09-21 12:00:00',
        ]);

        $this->patientRequest($user, $laboratory)
            ->assertJsonPath('data.0.id', $third->id)
            ->assertJsonPath('data.1.id', $first->id)
            ->assertJsonPath('data.2.id', $second->id);

        $this->patientRequest($user, $laboratory, [
            'sort' => 'first_names',
            'direction' => 'desc',
        ])
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.1.id', $third->id)
            ->assertJsonPath('data.2.id', $first->id);

        $this->patientRequest($user, $laboratory, [
            'sort' => 'created_at',
            'direction' => 'desc',
        ])
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.1.id', $third->id)
            ->assertJsonPath('data.2.id', $first->id);
    }

    public function test_pagination_uses_laravel_defaults_custom_pages_and_maximum_size(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        foreach (range(1, 18) as $number) {
            Patient::factory()->for($laboratory)->create([
                'last_names' => sprintf('Patient %02d', $number),
            ]);
        }

        $this->patientRequest($user, $laboratory)
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 18);

        $this->patientRequest($user, $laboratory, ['page' => 2])
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.current_page', 2);

        $this->patientRequest($user, $laboratory, [
            'per_page' => 5,
            'page' => 2,
        ])
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 5);

        $this->patientRequest($user, $laboratory, ['per_page' => 100])
            ->assertJsonCount(18, 'data')
            ->assertJsonPath('meta.per_page', 100);
    }

    #[DataProvider('invalidQueryProvider')]
    public function test_invalid_query_parameters_are_rejected(
        array $query,
        string $field,
    ): void {
        [$user, $laboratory] = $this->activeTenant();

        $this->patientRequest($user, $laboratory, $query)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidQueryProvider(): array
    {
        return [
            'invalid status' => [['status' => 'deleted'], 'status'],
            'invalid sort' => [['sort' => 'email'], 'sort'],
            'invalid direction' => [['direction' => 'sideways'], 'direction'],
            'per page above maximum' => [['per_page' => 101], 'per_page'],
            'per page zero' => [['per_page' => 0], 'per_page'],
            'per page negative' => [['per_page' => -1], 'per_page'],
            'per page non numeric' => [['per_page' => 'abc'], 'per_page'],
            'page zero' => [['page' => 0], 'page'],
            'page negative' => [['page' => -1], 'page'],
            'laboratory id query' => [['laboratory_id' => 999], 'laboratory_id'],
            'laboratory alias query' => [['lab' => 999], 'lab'],
        ];
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
    private function patientRequest(
        User $user,
        Laboratory $laboratory,
        array $query = [],
    ): TestResponse {
        $uri = '/api/v1/patients';

        if ($query !== []) {
            $uri .= '?'.http_build_query($query);
        }

        $this->assignDirectLaboratoryPermission($user, $laboratory, 'patients.view');

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson($uri);
    }
}

<?php

namespace Tests\Feature\Api\V1\Doctors;

use App\Models\Doctor;
use App\Models\Laboratory;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DoctorIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC'));
    }

    public function test_empty_doctor_list_returns_standard_pagination_metadata(): void
    {
        [$user, $laboratory] = $this->activeTenant();

        $this->doctorRequest($user, $laboratory)
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

    public function test_doctor_resource_exposes_only_the_list_contract_and_preserves_nulls(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $doctor = Doctor::factory()->for($laboratory)->create([
            'first_names' => 'Juan',
            'last_names' => 'Pérez',
            'specialty' => null,
            'phone' => null,
            'email' => null,
            'license_number' => null,
            'status' => Doctor::STATUS_ACTIVE,
            'notes' => 'Dato privado del detalle.',
        ]);

        $this->doctorRequest($user, $laboratory)
            ->assertOk()
            ->assertJsonPath('data.0.id', $doctor->id)
            ->assertJsonPath('data.0.first_names', 'Juan')
            ->assertJsonPath('data.0.last_names', 'Pérez')
            ->assertJsonPath('data.0.specialty', null)
            ->assertJsonPath('data.0.phone', null)
            ->assertJsonPath('data.0.email', null)
            ->assertJsonPath('data.0.license_number', null)
            ->assertJsonPath('data.0.status', Doctor::STATUS_ACTIVE)
            ->assertJsonPath('data.0.created_at', '2026-09-23T12:00:00.000000Z')
            ->assertJsonMissingPath('data.0.laboratory_id')
            ->assertJsonMissingPath('data.0.notes')
            ->assertJsonMissingPath('data.0.updated_at')
            ->assertJsonMissingPath('data.0.laboratory')
            ->assertJsonMissingPath('data.0.patients')
            ->assertJsonMissingPath('data.0.orders');
    }

    public function test_collection_is_isolated_to_the_current_laboratory(): void
    {
        $user = User::factory()->create();
        [, $labA] = $this->activeTenant($user);
        [, $labB] = $this->activeTenant($user);
        $doctorA1 = Doctor::factory()->for($labA)->create(['last_names' => 'Alfa']);
        $doctorA2 = Doctor::factory()->for($labA)->create(['last_names' => 'Beta']);
        Doctor::factory()->for($labB)->create(['last_names' => 'Secreto']);

        $this->doctorRequest($user, $labA)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $doctorA1->id)
            ->assertJsonPath('data.1.id', $doctorA2->id)
            ->assertJsonPath('meta.total', 2);
    }

    public function test_grouped_search_never_leaks_last_name_or_license_from_another_laboratory(): void
    {
        [$user, $labA] = $this->activeTenant();
        [, $labB] = $this->activeTenant();

        Doctor::factory()->for($labA)->create([
            'first_names' => 'Carlos',
            'last_names' => 'López',
            'license_number' => 'A-001',
        ]);
        Doctor::factory()->for($labB)->create([
            'first_names' => 'Pedro',
            'last_names' => 'SECRETO',
            'license_number' => 'SECRET-999',
        ]);

        foreach (['SECRETO', 'SECRET-999'] as $search) {
            $this->doctorRequest($user, $labA, ['search' => $search])
                ->assertOk()
                ->assertJsonCount(0, 'data')
                ->assertJsonPath('meta.total', 0);
        }
    }

    public function test_search_supports_names_last_names_license_case_insensitivity_trimming_and_empty_values(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $juan = Doctor::factory()->for($laboratory)->create([
            'first_names' => 'Juan Carlos',
            'last_names' => 'Méndez',
            'license_number' => 'COL-12345',
        ]);
        $ana = Doctor::factory()->for($laboratory)->create([
            'first_names' => 'Ana',
            'last_names' => 'PEREZ LOPEZ',
            'license_number' => 'MED-900',
        ]);
        Doctor::factory()->for($laboratory)->create([
            'first_names' => 'Carlos',
            'last_names' => 'Ramírez',
            'license_number' => null,
        ]);

        foreach (['  jUaN  ', 'MÉNDEZ', 'COL-12345', '12345'] as $search) {
            $response = $this->doctorRequest($user, $laboratory, ['search' => $search]);

            $response->assertOk();
            $this->assertSame(
                [$juan->id],
                $response->json('data.*.id'),
                "Unexpected result for search [{$search}].",
            );
        }

        $this->doctorRequest($user, $laboratory, ['search' => 'perez'])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ana->id);

        $this->doctorRequest($user, $laboratory, ['search' => '   '])
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3);
    }

    public function test_specialty_filter_is_trimmed_exact_case_insensitive_and_excludes_null(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $pediatrician = Doctor::factory()->for($laboratory)->create([
            'specialty' => 'Pediatría',
        ]);
        Doctor::factory()->for($laboratory)->create([
            'specialty' => 'Pediatría Neonatal',
        ]);
        Doctor::factory()->for($laboratory)->create(['specialty' => null]);

        $this->doctorRequest($user, $laboratory, ['specialty' => '  PEDIATRÍA  '])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pediatrician->id)
            ->assertJsonPath('meta.total', 1);

        $this->doctorRequest($user, $laboratory, ['specialty' => 'Pediatría Neonatal'])
            ->assertJsonCount(1, 'data');

        $this->doctorRequest($user, $laboratory, ['specialty' => 'Neonatal'])
            ->assertJsonCount(0, 'data');
    }

    public function test_status_filter_supports_both_states_and_defaults_to_all(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $active = Doctor::factory()->for($laboratory)->create([
            'status' => Doctor::STATUS_ACTIVE,
        ]);
        $inactive = Doctor::factory()->for($laboratory)->create([
            'status' => Doctor::STATUS_INACTIVE,
        ]);

        $this->doctorRequest($user, $laboratory)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);

        $this->doctorRequest($user, $laboratory, ['status' => 'active'])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id);

        $this->doctorRequest($user, $laboratory, ['status' => 'inactive'])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inactive->id);
    }

    public function test_search_status_and_specialty_filters_are_combined(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $expected = Doctor::factory()->for($laboratory)->create([
            'first_names' => 'Juan',
            'last_names' => 'Correcto',
            'specialty' => 'Cardiología',
            'status' => Doctor::STATUS_ACTIVE,
        ]);
        Doctor::factory()->for($laboratory)->create([
            'first_names' => 'Juan',
            'specialty' => 'Cardiología',
            'status' => Doctor::STATUS_INACTIVE,
        ]);
        Doctor::factory()->for($laboratory)->create([
            'first_names' => 'Juan',
            'specialty' => 'Pediatría',
            'status' => Doctor::STATUS_ACTIVE,
        ]);
        Doctor::factory()->for($laboratory)->create([
            'first_names' => 'Ana',
            'specialty' => 'Cardiología',
            'status' => Doctor::STATUS_ACTIVE,
        ]);

        $this->doctorRequest($user, $laboratory, [
            'search' => 'Juan',
            'status' => 'active',
            'specialty' => 'cardiología',
        ])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $expected->id);
    }

    public function test_sorting_supports_whitelisted_columns_direction_and_stable_id_tiebreaker(): void
    {
        [$user, $laboratory] = $this->activeTenant();
        $first = Doctor::factory()->for($laboratory)->create([
            'first_names' => 'Ana',
            'last_names' => 'Same',
            'specialty' => 'Beta',
            'created_at' => '2026-09-20 12:00:00',
        ]);
        $second = Doctor::factory()->for($laboratory)->create([
            'first_names' => 'Zoe',
            'last_names' => 'Same',
            'specialty' => 'Alpha',
            'created_at' => '2026-09-23 11:00:00',
        ]);
        $third = Doctor::factory()->for($laboratory)->create([
            'first_names' => 'Carlos',
            'last_names' => 'Alpha',
            'specialty' => 'Gamma',
            'created_at' => '2026-09-21 12:00:00',
        ]);

        $this->doctorRequest($user, $laboratory)
            ->assertJsonPath('data.0.id', $third->id)
            ->assertJsonPath('data.1.id', $first->id)
            ->assertJsonPath('data.2.id', $second->id);

        $this->doctorRequest($user, $laboratory, ['sort' => 'last_names'])
            ->assertJsonPath('data.1.id', $first->id)
            ->assertJsonPath('data.2.id', $second->id);

        $this->doctorRequest($user, $laboratory, [
            'sort' => 'first_names',
            'direction' => 'desc',
        ])
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.1.id', $third->id)
            ->assertJsonPath('data.2.id', $first->id);

        $this->doctorRequest($user, $laboratory, ['sort' => 'specialty'])
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.1.id', $first->id)
            ->assertJsonPath('data.2.id', $third->id);

        $this->doctorRequest($user, $laboratory, [
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
            Doctor::factory()->for($laboratory)->create([
                'last_names' => sprintf('Doctor %02d', $number),
            ]);
        }

        $this->doctorRequest($user, $laboratory)
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 18);

        $this->doctorRequest($user, $laboratory, ['page' => 2])
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.current_page', 2);

        $this->doctorRequest($user, $laboratory, ['per_page' => 5, 'page' => 2])
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 5);

        $this->doctorRequest($user, $laboratory, ['per_page' => 100])
            ->assertJsonCount(18, 'data')
            ->assertJsonPath('meta.per_page', 100);
    }

    #[DataProvider('invalidQueryProvider')]
    public function test_invalid_prohibited_and_unknown_query_parameters_are_rejected(
        array $query,
        string $field,
    ): void {
        [$user, $laboratory] = $this->activeTenant();

        $this->doctorRequest($user, $laboratory, $query)
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
            'invalid sort' => [['sort' => 'email'], 'sort'],
            'sql injection sort' => [['sort' => 'last_names desc; drop table doctors'], 'sort'],
            'invalid direction' => [['direction' => 'ascending'], 'direction'],
            'uppercase direction' => [['direction' => 'DESC'], 'direction'],
            'per page above maximum' => [['per_page' => 101], 'per_page'],
            'per page zero' => [['per_page' => 0], 'per_page'],
            'per page negative' => [['per_page' => -1], 'per_page'],
            'per page non numeric' => [['per_page' => 'abc'], 'per_page'],
            'page zero' => [['page' => 0], 'page'],
            'page negative' => [['page' => -1], 'page'],
            'page non numeric' => [['page' => 'abc'], 'page'],
            'laboratory id query' => [['laboratory_id' => 999], 'laboratory_id'],
            'laboratory alias query' => [['lab' => 999], 'lab'],
            'unknown foo query' => [['foo' => 'bar'], 'foo'],
            'unknown email query' => [['email' => 'test@example.com'], 'email'],
            'unknown phone query' => [['phone' => '55555555'], 'phone'],
        ];
    }

    public function test_guest_is_rejected_by_the_saas_pipeline(): void
    {
        $this->getJson('/api/v1/doctors')->assertUnauthorized();
    }

    public function test_missing_laboratory_header_is_rejected_by_the_saas_pipeline(): void
    {
        $this->actingAs(User::factory()->create(), 'web')
            ->getJson('/api/v1/doctors')
            ->assertBadRequest()
            ->assertJsonPath('code', 'LABORATORY_CONTEXT_REQUIRED');
    }

    public function test_inactive_membership_is_rejected_by_the_saas_pipeline(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => false]);
        $this->createCurrentSubscription($laboratory);

        $this->doctorRequest($user, $laboratory)
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');
    }

    public function test_missing_subscription_is_rejected_by_the_saas_pipeline(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true]);

        $this->doctorRequest($user, $laboratory)
            ->assertForbidden()
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
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
    private function doctorRequest(
        User $user,
        Laboratory $laboratory,
        array $query = [],
    ): TestResponse {
        $uri = '/api/v1/doctors';

        if ($query !== []) {
            $uri .= '?'.http_build_query($query);
        }

        return $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson($uri);
    }
}

<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\Laboratory;
use App\Models\LaboratoryUser;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserLaboratoriesDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_response_adds_boolean_default_without_changing_existing_fields_or_leaking_pivot(): void
    {
        $user = User::factory()->create();
        $defaultLaboratory = Laboratory::factory()->create([
            'name' => 'Laboratorio A',
            'legal_name' => 'Laboratorio A, S.A.',
            'timezone' => 'America/Guatemala',
            'currency' => 'GTQ',
        ]);
        $otherLaboratory = Laboratory::factory()->create([
            'name' => 'Laboratorio B',
            'legal_name' => null,
            'timezone' => 'America/New_York',
            'currency' => 'USD',
        ]);
        $user->laboratories()->attach($defaultLaboratory, [
            'is_active' => true,
            'is_default' => true,
        ]);
        $user->laboratories()->attach($otherLaboratory, [
            'is_active' => true,
            'is_default' => false,
        ]);

        $response = $this->actingAs($user, 'web')
            ->getJson('/api/v1/auth/laboratories')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    [
                        'id' => $defaultLaboratory->id,
                        'name' => 'Laboratorio A',
                        'legal_name' => 'Laboratorio A, S.A.',
                        'timezone' => 'America/Guatemala',
                        'currency' => 'GTQ',
                        'is_default' => true,
                    ],
                    [
                        'id' => $otherLaboratory->id,
                        'name' => 'Laboratorio B',
                        'legal_name' => null,
                        'timezone' => 'America/New_York',
                        'currency' => 'USD',
                        'is_default' => false,
                    ],
                ],
            ]);

        $this->assertIsBool($response->json('data.0.is_default'));
        $this->assertIsBool($response->json('data.1.is_default'));
    }

    public function test_zero_defaults_returns_false_for_every_membership_without_writes(): void
    {
        $user = User::factory()->create();
        $laboratories = Laboratory::factory()->count(3)->sequence(
            ['name' => 'Laboratorio A'],
            ['name' => 'Laboratorio B'],
            ['name' => 'Laboratorio C'],
        )->create();
        $user->laboratories()->attach($laboratories->pluck('id'), ['is_active' => true]);
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (in_array(strtolower(strtok(ltrim($query->sql), ' ')), ['insert', 'update', 'delete'], true)) {
                $writes[] = $query->sql;
            }
        });

        $response = $this->actingAs($user, 'web')
            ->getJson('/api/v1/auth/laboratories')
            ->assertOk();

        $this->assertSame([false, false, false], $response->json('data.*.is_default'));
        $this->assertSame([], $writes);
    }

    public function test_same_laboratory_can_be_default_for_multiple_users(): void
    {
        $laboratory = Laboratory::factory()->create();
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        LaboratoryUser::factory()->asDefault()->create([
            'laboratory_id' => $laboratory->id,
            'user_id' => $firstUser->id,
        ]);
        LaboratoryUser::factory()->asDefault()->create([
            'laboratory_id' => $laboratory->id,
            'user_id' => $secondUser->id,
        ]);

        $this->actingAs($firstUser, 'web')
            ->getJson('/api/v1/auth/laboratories')
            ->assertOk()
            ->assertJsonPath('data.0.is_default', true);
        $this->app['auth']->forgetGuards();
        $this->actingAs($secondUser, 'web')
            ->getJson('/api/v1/auth/laboratories')
            ->assertOk()
            ->assertJsonPath('data.0.is_default', true);
    }

    public function test_users_receive_independent_defaults_for_shared_laboratories(): void
    {
        $laboratories = Laboratory::factory()->count(2)->sequence(
            ['name' => 'Laboratorio A'],
            ['name' => 'Laboratorio B'],
        )->create();
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $firstUser->laboratories()->attach($laboratories[0], ['is_active' => true, 'is_default' => true]);
        $firstUser->laboratories()->attach($laboratories[1], ['is_active' => true, 'is_default' => false]);
        $secondUser->laboratories()->attach($laboratories[0], ['is_active' => true, 'is_default' => false]);
        $secondUser->laboratories()->attach($laboratories[1], ['is_active' => true, 'is_default' => true]);

        $firstResponse = $this->actingAs($firstUser, 'web')
            ->getJson('/api/v1/auth/laboratories')
            ->assertOk();
        $this->app['auth']->forgetGuards();
        $secondResponse = $this->actingAs($secondUser, 'web')
            ->getJson('/api/v1/auth/laboratories')
            ->assertOk();

        $this->assertSame([true, false], $firstResponse->json('data.*.is_default'));
        $this->assertSame([false, true], $secondResponse->json('data.*.is_default'));
    }

    public function test_inactive_default_membership_remains_stored_but_is_not_returned(): void
    {
        $user = User::factory()->create();
        $visibleLaboratory = Laboratory::factory()->create(['name' => 'Laboratorio A']);
        $hiddenLaboratory = Laboratory::factory()->create(['name' => 'Laboratorio B']);
        $user->laboratories()->attach($visibleLaboratory, ['is_active' => true, 'is_default' => false]);
        $membership = LaboratoryUser::factory()->asDefault()->create([
            'laboratory_id' => $hiddenLaboratory->id,
            'user_id' => $user->id,
            'is_active' => false,
        ]);

        $this->actingAs($user, 'web')
            ->getJson('/api/v1/auth/laboratories')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visibleLaboratory->id)
            ->assertJsonPath('data.0.is_default', false);

        $this->assertTrue($membership->fresh()->is_default);
    }

    public function test_default_membership_for_inactive_laboratory_remains_stored_but_is_not_returned(): void
    {
        $user = User::factory()->create();
        $visibleLaboratory = Laboratory::factory()->create(['name' => 'Laboratorio A']);
        $hiddenLaboratory = Laboratory::factory()->create([
            'name' => 'Laboratorio B',
            'is_active' => false,
        ]);
        $user->laboratories()->attach($visibleLaboratory, ['is_active' => true, 'is_default' => false]);
        $membership = LaboratoryUser::factory()->asDefault()->create([
            'laboratory_id' => $hiddenLaboratory->id,
            'user_id' => $user->id,
        ]);

        $this->actingAs($user, 'web')
            ->getJson('/api/v1/auth/laboratories')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visibleLaboratory->id)
            ->assertJsonPath('data.0.is_default', false);

        $this->assertTrue($membership->fresh()->is_default);
    }

    public function test_query_count_is_constant_for_one_and_ten_laboratories(): void
    {
        $oneLaboratoryUser = User::factory()->create();
        $oneLaboratoryUser->laboratories()->attach(
            Laboratory::factory()->create(),
            ['is_active' => true],
        );
        $tenLaboratoryUser = User::factory()->create();
        $tenLaboratoryUser->laboratories()->attach(
            Laboratory::factory()->count(10)->create()->pluck('id'),
            ['is_active' => true],
        );
        $laboratoryQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$laboratoryQueries): void {
            if (str_contains($query->sql, 'from "laboratories"')) {
                $laboratoryQueries[] = $query->sql;
            }
        });

        $this->actingAs($oneLaboratoryUser, 'web')
            ->getJson('/api/v1/auth/laboratories')
            ->assertOk();
        $queriesAfterOneLaboratory = count($laboratoryQueries);
        $this->app['auth']->forgetGuards();
        $this->actingAs($tenLaboratoryUser, 'web')
            ->getJson('/api/v1/auth/laboratories')
            ->assertOk();

        $this->assertSame(1, $queriesAfterOneLaboratory);
        $this->assertSame(1, count($laboratoryQueries) - $queriesAfterOneLaboratory);
    }

    public function test_invalid_laboratory_header_does_not_affect_discovery_endpoint(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => true, 'is_default' => true]);

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', '999999999')
            ->getJson('/api/v1/auth/laboratories')
            ->assertOk()
            ->assertJsonPath('data.0.id', $laboratory->id)
            ->assertJsonPath('data.0.is_default', true);
    }

    public function test_openapi_matches_the_available_laboratory_runtime_contract(): void
    {
        $this->artisan('l5-swagger:generate')->assertExitCode(0);
        $document = json_decode(
            file_get_contents(storage_path('api-docs/api-docs.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $operation = $document['paths']['/api/v1/auth/laboratories']['get'];
        $schema = $document['components']['schemas']['AvailableLaboratory'];

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertSame(
            '#/components/schemas/AvailableLaboratoriesResponse',
            $operation['responses']['200']['content']['application/json']['schema']['$ref'],
        );
        $this->assertSame(
            ['id', 'name', 'legal_name', 'timezone', 'currency', 'is_default'],
            $schema['required'],
        );
        $this->assertSame('boolean', $schema['properties']['is_default']['type']);
        $this->assertArrayNotHasKey('nullable', $schema['properties']['is_default']);
    }
}

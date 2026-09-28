<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\Laboratory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvailableLaboratoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_all_laboratories_with_active_memberships(): void
    {
        $user = User::factory()->create();
        $laboratories = Laboratory::factory()->count(2)->sequence(
            ['name' => 'Laboratorio A'],
            ['name' => 'Laboratorio B'],
        )->create();

        $user->laboratories()->attach($laboratories->pluck('id'), ['is_active' => true]);

        $this->actingAs($user, 'web')
            ->getJson('/api/v1/auth/laboratories')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $laboratories[0]->id)
            ->assertJsonPath('data.1.id', $laboratories[1]->id)
            ->assertJsonMissingPath('data.0.nit')
            ->assertJsonMissingPath('data.0.is_active');
    }

    public function test_user_without_memberships_receives_an_empty_collection(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->getJson('/api/v1/auth/laboratories')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_inactive_membership_is_not_available(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();

        $user->laboratories()->attach($laboratory, ['is_active' => false]);

        $this->actingAs($user, 'web')
            ->getJson('/api/v1/auth/laboratories')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_inactive_laboratory_is_not_available_with_active_membership(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create(['is_active' => false]);

        $user->laboratories()->attach($laboratory, ['is_active' => true]);

        $this->actingAs($user, 'web')
            ->getJson('/api/v1/auth/laboratories')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_laboratory_exclusive_to_another_user_is_not_available(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $ownLaboratory = Laboratory::factory()->create(['name' => 'Own Laboratory']);
        $otherLaboratory = Laboratory::factory()->create(['name' => 'Other Laboratory']);

        $user->laboratories()->attach($ownLaboratory, ['is_active' => true]);
        $otherUser->laboratories()->attach($otherLaboratory, ['is_active' => true, 'is_default' => true]);

        $this->actingAs($user, 'web')
            ->getJson('/api/v1/auth/laboratories')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownLaboratory->id)
            ->assertJsonMissing(['id' => $otherLaboratory->id]);
    }

    public function test_guest_cannot_list_available_laboratories(): void
    {
        $this->getJson('/api/v1/auth/laboratories')
            ->assertUnauthorized();
    }
}

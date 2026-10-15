<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\Laboratory;
use App\Models\Patient;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class InactiveSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivation_blocks_existing_spa_session_before_reads_and_writes_but_allows_logout(): void
    {
        $laboratory = Laboratory::factory()->create();
        Subscription::factory()->for($laboratory)->create();
        $user = $this->createLaboratoryMember($laboratory);
        $this->assignDirectLaboratoryPermissions($user, $laboratory, ['patients.view', 'patients.change_status']);
        $patient = Patient::factory()->for($laboratory)->create(['status' => 'active']);
        $this->withHeader('Origin', 'http://localhost:5173');
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        Auth::forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertOk();

        DB::table('users')->where('id', $user->id)->update(['is_active' => false]);
        foreach (['/auth/me', '/auth/laboratories', '/auth/authorization', '/patients'] as $path) {
            Auth::forgetGuards();
            $this->withHeader('X-Laboratory-ID', (string) $laboratory->id)
                ->getJson('/api/v1'.$path)->assertForbidden()->assertJsonPath('code', 'USER_INACTIVE');
        }
        Auth::forgetGuards();
        $this->patchJson("/api/v1/patients/{$patient->id}/status", ['status' => 'inactive'])
            ->assertForbidden()->assertJsonPath('code', 'USER_INACTIVE');
        $this->assertSame('active', $patient->fresh()->status);
        Auth::forgetGuards();
        $this->postJson('/api/v1/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_deactivation_is_rechecked_even_when_the_guard_holds_a_stale_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->getJson('/api/v1/auth/me')->assertOk();
        DB::table('users')->where('id', $user->id)->update(['is_active' => false]);
        $this->getJson('/api/v1/auth/me')->assertForbidden()->assertJsonPath('code', 'USER_INACTIVE');
    }
}

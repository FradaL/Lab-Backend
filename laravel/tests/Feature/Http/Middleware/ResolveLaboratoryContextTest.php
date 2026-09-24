<?php

namespace Tests\Feature\Http\Middleware;

use App\Models\Laboratory;
use App\Models\User;
use App\Tenancy\CurrentLaboratory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ResolveLaboratoryContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['auth:sanctum', 'laboratory.context'])
            ->get('/api/v1/testing/laboratory-context', function (CurrentLaboratory $currentLaboratory) {
                return response()->json([
                    'laboratory_id' => $currentLaboratory->id(),
                    'laboratory_name' => $currentLaboratory->get()->name,
                ]);
            });
    }

    public function test_missing_header_returns_bad_request(): void
    {
        $this->actingAs(User::factory()->create(), 'web')
            ->getJson('/api/v1/testing/laboratory-context')
            ->assertBadRequest()
            ->assertExactJson([
                'message' => 'Debe especificar el laboratorio.',
                'code' => 'LABORATORY_CONTEXT_REQUIRED',
            ]);
    }

    public function test_invalid_header_returns_bad_request(): void
    {
        $this->actingAs(User::factory()->create(), 'web')
            ->withHeader('X-Laboratory-ID', '1.5')
            ->getJson('/api/v1/testing/laboratory-context')
            ->assertBadRequest()
            ->assertExactJson([
                'message' => 'El identificador del laboratorio no es válido.',
                'code' => 'INVALID_LABORATORY_CONTEXT',
            ]);
    }

    public function test_missing_laboratory_returns_not_found(): void
    {
        $this->actingAs(User::factory()->create(), 'web')
            ->withHeader('X-Laboratory-ID', '999999')
            ->getJson('/api/v1/testing/laboratory-context')
            ->assertNotFound()
            ->assertExactJson([
                'message' => 'El laboratorio solicitado no existe.',
                'code' => 'LABORATORY_NOT_FOUND',
            ]);
    }

    public function test_inactive_laboratory_returns_forbidden(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create(['is_active' => false]);
        $user->laboratories()->attach($laboratory, ['is_active' => true]);

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson('/api/v1/testing/laboratory-context')
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'El laboratorio no está activo.',
                'code' => 'LABORATORY_INACTIVE',
            ]);
    }

    public function test_user_without_membership_is_denied(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson('/api/v1/testing/laboratory-context')
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'No tiene acceso al laboratorio solicitado.',
                'code' => 'LABORATORY_ACCESS_DENIED',
            ]);
    }

    public function test_inactive_membership_is_denied_without_revealing_its_state(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create();
        $user->laboratories()->attach($laboratory, ['is_active' => false]);

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson('/api/v1/testing/laboratory-context')
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'No tiene acceso al laboratorio solicitado.',
                'code' => 'LABORATORY_ACCESS_DENIED',
            ]);
    }

    public function test_active_membership_allows_request_with_exact_requested_context(): void
    {
        $user = User::factory()->create();
        $laboratory = Laboratory::factory()->create(['name' => 'Requested Laboratory']);
        $user->laboratories()->attach($laboratory, ['is_active' => true]);

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratory->id)
            ->getJson('/api/v1/testing/laboratory-context')
            ->assertOk()
            ->assertExactJson([
                'laboratory_id' => $laboratory->id,
                'laboratory_name' => 'Requested Laboratory',
            ]);
    }

    public function test_user_cannot_access_another_users_laboratory(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $laboratoryA = Laboratory::factory()->create();
        $laboratoryB = Laboratory::factory()->create();

        $userA->laboratories()->attach($laboratoryA, ['is_active' => true]);
        $userB->laboratories()->attach($laboratoryB, ['is_active' => true]);

        $this->actingAs($userA, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratoryB->id)
            ->getJson('/api/v1/testing/laboratory-context')
            ->assertForbidden()
            ->assertJsonPath('code', 'LABORATORY_ACCESS_DENIED');
    }

    public function test_user_with_multiple_memberships_can_change_context_between_requests(): void
    {
        $user = User::factory()->create();
        $laboratoryA = Laboratory::factory()->create(['name' => 'Laboratory A']);
        $laboratoryB = Laboratory::factory()->create(['name' => 'Laboratory B']);
        $user->laboratories()->attach([$laboratoryA->id, $laboratoryB->id], ['is_active' => true]);

        $this->actingAs($user, 'web')
            ->withHeader('X-Laboratory-ID', (string) $laboratoryA->id)
            ->getJson('/api/v1/testing/laboratory-context')
            ->assertOk()
            ->assertJsonPath('laboratory_id', $laboratoryA->id);

        $this->withHeader('X-Laboratory-ID', (string) $laboratoryB->id)
            ->getJson('/api/v1/testing/laboratory-context')
            ->assertOk()
            ->assertJsonPath('laboratory_id', $laboratoryB->id);
    }

    public function test_context_is_not_retained_after_container_scope_is_flushed(): void
    {
        $context = $this->app->make(CurrentLaboratory::class);
        $context->set(Laboratory::factory()->create());

        $this->assertTrue($context->has());

        $this->app->forgetScopedInstances();

        $freshContext = $this->app->make(CurrentLaboratory::class);

        $this->assertNotSame($context, $freshContext);
        $this->assertFalse($freshContext->has());
    }

    public function test_non_tenant_routes_work_without_laboratory_header(): void
    {
        $user = User::factory()->create();

        $this->getJson('/api/v1/health')->assertOk();
        $this->getJson('/sanctum/csrf-cookie')->assertNoContent();

        $this->actingAs($user, 'web')
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        $this->getJson('/api/v1/auth/laboratories')
            ->assertOk();
    }
}

<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_user_can_log_in_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'name' => 'Administrador Demo',
            'email' => 'admin@donqerlab.test',
            'password' => 'password',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@donqerlab.test',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertExactJson([
                'message' => 'Sesión iniciada correctamente.',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => 'Administrador Demo',
                        'email' => 'admin@donqerlab.test',
                    ],
                ],
            ]);

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_login_rejects_an_incorrect_password_with_a_generic_message(): void
    {
        User::factory()->create([
            'email' => 'admin@donqerlab.test',
            'password' => 'password',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@donqerlab.test',
            'password' => 'incorrect-password',
        ])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Las credenciales proporcionadas son incorrectas.',
            ]);

        $this->assertGuest('web');
    }

    public function test_login_rejects_an_unknown_email_with_the_same_generic_message(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'unknown@donqerlab.test',
            'password' => 'password',
        ])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Las credenciales proporcionadas son incorrectas.',
            ]);

        $this->assertGuest('web');
    }

    public function test_inactive_user_cannot_log_in_even_with_the_correct_password(): void
    {
        User::factory()->create([
            'email' => 'inactive@donqerlab.test',
            'password' => 'password',
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'inactive@donqerlab.test',
            'password' => 'password',
        ])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Las credenciales proporcionadas son incorrectas.',
            ]);

        $this->assertGuest('web');
    }

    public function test_login_requires_an_email(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'password' => 'password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_requires_a_valid_email(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'not-an-email',
            'password' => 'password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_requires_a_password(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@donqerlab.test',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_authenticated_user_can_get_their_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Administrador Demo',
            'email' => 'admin@donqerlab.test',
        ]);

        $this->actingAs($user, 'web')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => 'Administrador Demo',
                        'email' => 'admin@donqerlab.test',
                    ],
                ],
            ]);
    }

    public function test_guest_cannot_get_their_profile(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_authenticated_user_can_log_out_and_the_session_stops_authenticating(): void
    {
        User::factory()->create([
            'email' => 'logout@donqerlab.test',
            'password' => 'password',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'logout@donqerlab.test',
            'password' => 'password',
        ])->assertOk();

        $this->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertExactJson([
                'message' => 'Sesión cerrada correctamente.',
            ]);

        $this->assertGuest('web');

        // Each real HTTP request gets fresh guards; the test application is reused.
        Auth::forgetGuards();

        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_spa_session_survives_multiple_authenticated_requests(): void
    {
        User::factory()->create([
            'email' => 'persistent-session@donqerlab.test',
            'password' => 'password',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'persistent-session@donqerlab.test',
            'password' => 'password',
        ])->assertOk();

        Auth::forgetGuards();

        $this->getJson('/api/v1/auth/me')->assertOk();

        Auth::forgetGuards();

        $this->getJson('/api/v1/auth/laboratories')
            ->assertOk()
            ->assertExactJson(['data' => []]);

        Auth::forgetGuards();

        $this->postJson('/api/v1/auth/logout')->assertOk();

        Auth::forgetGuards();

        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_guest_cannot_log_out(): void
    {
        $this->postJson('/api/v1/auth/logout')
            ->assertUnauthorized();
    }

    public function test_login_is_rate_limited_after_five_attempts_per_email_and_ip(): void
    {
        $request = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);
        $credentials = [
            'email' => 'rate-limited@donqerlab.test',
            'password' => 'incorrect-password',
        ];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $request->postJson('/api/v1/auth/login', $credentials)
                ->assertUnprocessable();
        }

        $request->postJson('/api/v1/auth/login', $credentials)
            ->assertTooManyRequests();
    }
}

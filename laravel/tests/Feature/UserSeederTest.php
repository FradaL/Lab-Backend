<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_users_are_seeded_idempotently_with_hashed_passwords(): void
    {
        $this->seed();
        $this->seed();

        $this->assertDatabaseCount('users', 2);

        $expectedUsers = [
            'admin@donqerlab.test' => 'Administrador Demo',
            'reception@donqerlab.test' => 'Recepción Demo',
        ];

        foreach ($expectedUsers as $email => $name) {
            $user = User::query()->where('email', $email)->firstOrFail();

            $this->assertSame($name, $user->name);
            $this->assertNotSame('password', $user->password);
            $this->assertTrue(Hash::check('password', $user->password));
            $this->assertTrue($user->is_active);
            $this->assertCount(0, $user->roles);
        }
    }
}

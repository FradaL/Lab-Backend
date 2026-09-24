<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Seed the development users.
     */
    public function run(): void
    {
        $users = [
            [
                'name' => 'Administrador Demo',
                'email' => 'admin@donqerlab.test',
            ],
            [
                'name' => 'Recepción Demo',
                'email' => 'reception@donqerlab.test',
            ],
        ];

        foreach ($users as $user) {
            User::query()->updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'password' => Hash::make('password'),
                    'is_active' => true,
                ],
            );
        }
    }
}

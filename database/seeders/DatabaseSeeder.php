<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        $defaultPassword = Hash::make('password');

        $users = [
            [
                'first_name' => 'Super',
                'last_name' => 'Admin',
                'email' => 'superadmin@radium.local',
                'role' => RolePermissionSeeder::ROLE_SUPERADMIN,
            ],
            [
                'first_name' => 'Admin',
                'last_name' => 'User',
                'email' => 'admin@radium.local',
                'role' => RolePermissionSeeder::ROLE_ADMIN,
            ],
            [
                'first_name' => 'Agent',
                'last_name' => 'User',
                'email' => 'agent@radium.local',
                'role' => RolePermissionSeeder::ROLE_AGENT,
            ],
        ];

        foreach ($users as $userData) {
            $user = User::query()->updateOrCreate(
                ['email' => $userData['email']],
                [
                    'first_name' => $userData['first_name'],
                    'last_name' => $userData['last_name'],
                    'name' => trim($userData['first_name'].' '.$userData['last_name']),
                    'password' => $defaultPassword,
                    'is_active' => true,
                ],
            );

            $user->syncRoles([$userData['role']]);
        }
    }
}

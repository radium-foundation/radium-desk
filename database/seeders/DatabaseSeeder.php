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
                'name' => 'Super Admin',
                'email' => 'superadmin@radium.local',
                'role' => RolePermissionSeeder::ROLE_SUPERADMIN,
            ],
            [
                'name' => 'Admin User',
                'email' => 'admin@radium.local',
                'role' => RolePermissionSeeder::ROLE_ADMIN,
            ],
            [
                'name' => 'Agent User',
                'email' => 'agent@radium.local',
                'role' => RolePermissionSeeder::ROLE_AGENT,
            ],
        ];

        foreach ($users as $userData) {
            $user = User::query()->updateOrCreate(
                ['email' => $userData['email']],
                [
                    'name' => $userData['name'],
                    'password' => $defaultPassword,
                    'is_active' => true,
                ],
            );

            $user->syncRoles([$userData['role']]);
        }
    }
}

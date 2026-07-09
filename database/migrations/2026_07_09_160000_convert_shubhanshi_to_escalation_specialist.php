<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        Role::findOrCreate(RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST, 'web');

        $user = User::query()
            ->where('email', 'shubhanshi@radiumbox.com')
            ->first();

        if ($user === null) {
            return;
        }

        $user->syncRoles([RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST]);
    }

    public function down(): void
    {
        $agentRole = Role::query()
            ->where('name', RolePermissionSeeder::ROLE_AGENT)
            ->where('guard_name', 'web')
            ->first();

        if ($agentRole === null) {
            return;
        }

        $user = User::query()
            ->where('email', 'shubhanshi@radiumbox.com')
            ->first();

        if ($user === null) {
            return;
        }

        $user->syncRoles([RolePermissionSeeder::ROLE_AGENT]);
    }
};

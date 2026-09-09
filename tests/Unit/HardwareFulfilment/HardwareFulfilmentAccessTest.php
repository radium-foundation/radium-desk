<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Models\User;
use App\Support\HardwareFulfilment\HardwareFulfilmentAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HardwareFulfilmentAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_and_hardware_team_can_operate_and_download(): void
    {
        $admin = $this->userWithRole(RolePermissionSeeder::ROLE_ADMIN);
        $operator = $this->userWithRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);

        $this->assertTrue(HardwareFulfilmentAccess::allows($admin));
        $this->assertTrue(HardwareFulfilmentAccess::allows($operator));
        $this->assertTrue(HardwareFulfilmentAccess::allowsDocumentDownload($admin));
        $this->assertTrue(HardwareFulfilmentAccess::allowsDocumentDownload($operator));
    }

    public function test_support_agent_can_download_without_operate(): void
    {
        $agent = $this->userWithRole(RolePermissionSeeder::ROLE_AGENT);

        $this->assertFalse(HardwareFulfilmentAccess::allows($agent));
        $this->assertTrue(HardwareFulfilmentAccess::allowsSupportAgentDocumentDownload($agent));
        $this->assertTrue(HardwareFulfilmentAccess::allowsDocumentDownload($agent));
        $this->assertTrue($agent->can('orders.view'));
        $this->assertFalse($agent->can(RolePermissionSeeder::PERMISSION_HARDWARE_FULFILMENT_OPERATE));
    }

    public function test_unprivileged_roles_cannot_download(): void
    {
        $specialist = $this->userWithRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);
        $employee = $this->userWithRole(RolePermissionSeeder::ROLE_EMPLOYEE);
        $stranger = User::factory()->create(['is_active' => true]);

        $this->assertFalse(HardwareFulfilmentAccess::allowsDocumentDownload($specialist));
        $this->assertFalse(HardwareFulfilmentAccess::allowsDocumentDownload($employee));
        $this->assertFalse(HardwareFulfilmentAccess::allowsDocumentDownload($stranger));
        $this->assertFalse(HardwareFulfilmentAccess::allowsDocumentDownload(null));
        $this->assertFalse(HardwareFulfilmentAccess::allows($specialist));
        $this->assertFalse(HardwareFulfilmentAccess::allows($employee));
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}

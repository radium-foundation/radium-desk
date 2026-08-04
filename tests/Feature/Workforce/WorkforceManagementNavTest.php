<?php

namespace Tests\Feature\Workforce;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkforceManagementNavTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_attendance_nav_links_leave_and_hides_placeholders(): void
    {
        config(['workforce_recognition.enabled' => false]);

        $admin = User::factory()->create(['name' => 'Nav Admin', 'is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->actingAs($admin)
            ->get(route('workforce-management.attendance.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('href="'.route('leave-requests.index').'"', $html);
        $this->assertMatchesRegularExpression('/href="[^"]*leave-requests"[^>]*>\s*Leave\s*</', $html);
        $this->assertStringNotContainsString('Coming soon', $html);
        $this->assertStringNotContainsString('wm-soon-pill', $html);
        $this->assertDoesNotMatchRegularExpression('/wm-workspace-nav[^>]*>[\s\S]*?>\s*Calendar\s*</', $html);
        $this->assertStringNotContainsString('>Performance</a>', $html);
        $this->assertStringNotContainsString('>Work Recognition</a>', $html);
    }

    public function test_work_recognition_tab_visible_only_when_enabled_and_permitted(): void
    {
        config(['workforce_recognition.enabled' => true]);

        $ops = User::factory()->create(['name' => 'Ops Nav', 'is_active' => true]);
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $this->assertTrue($ops->can('workforce.recognition.view'));

        $html = $this->actingAs($ops)
            ->get(route('workforce-management.attendance.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Work Recognition', $html);
        $this->assertStringContainsString(route('workforce-management.recognition.index'), $html);
    }
}

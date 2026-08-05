<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use App\Support\Platform\OverallSystemHealthPresentation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OverallSystemHealthTerminologyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_platform_banner_uses_overall_system_health_title(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->actingAs($user)
            ->get(route('admin.platform.index'))
            ->assertOk()
            ->assertSee(OverallSystemHealthPresentation::TITLE, false)
            ->assertSee(OverallSystemHealthPresentation::TOOLTIP, false)
            ->assertDontSee('Overall Platform Health', false);
    }

    public function test_presentation_constants_are_stable(): void
    {
        $this->assertSame('Overall System Health', OverallSystemHealthPresentation::TITLE);
        $this->assertStringContainsString('Platform Health', OverallSystemHealthPresentation::DESCRIPTION);
        $this->assertStringContainsString('Integration Health', OverallSystemHealthPresentation::DESCRIPTION);
        $this->assertStringContainsString('Operations Snapshot', OverallSystemHealthPresentation::DESCRIPTION);
    }
}

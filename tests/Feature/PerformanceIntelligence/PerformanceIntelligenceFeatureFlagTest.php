<?php

namespace Tests\Feature\PerformanceIntelligence;

use App\Models\User;
use App\Services\PerformanceIntelligence\PerformanceIntelligenceEngine;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PerformanceIntelligenceFeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-05 10:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_disabled_flag_blocks_access_even_for_superadmin(): void
    {
        config(['performance_intelligence.enabled' => false]);

        $super = User::factory()->create(['is_active' => true]);
        $super->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->actingAs($super)
            ->get(route('admin.performance-intelligence.index'))
            ->assertForbidden();

        $this->actingAs($super)
            ->get(route('admin.administration.index'))
            ->assertOk()
            ->assertDontSee(route('admin.performance-intelligence.index'), false);
    }

    public function test_disabled_engine_capture_is_noop(): void
    {
        config(['performance_intelligence.enabled' => false]);

        $result = app(PerformanceIntelligenceEngine::class)->captureDay(
            Carbon::parse('2026-08-04', 'Asia/Kolkata'),
        );

        $this->assertTrue($result['skipped']);
        $this->assertSame(0, $result['processed']);
        $this->assertDatabaseCount('performance_intelligence_snapshots', 0);
    }

    public function test_artisan_command_skips_when_disabled(): void
    {
        config(['performance_intelligence.enabled' => false]);

        $exit = Artisan::call('performance-intelligence:snapshot', [
            '--date' => '2026-08-04',
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('disabled', Artisan::output());
        $this->assertDatabaseCount('performance_intelligence_snapshots', 0);
    }
}

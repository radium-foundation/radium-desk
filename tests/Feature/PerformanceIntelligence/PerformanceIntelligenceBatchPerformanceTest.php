<?php

namespace Tests\Feature\PerformanceIntelligence;

use App\Enums\TeamAvailabilityStatus;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\PerformanceIntelligence\PerformanceIntelligenceEngine;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PerformanceIntelligenceBatchPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['performance_intelligence.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-08-05 10:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_batch_capture_avoids_per_user_query_explosion(): void
    {
        $ids = [];
        for ($i = 0; $i < 12; $i++) {
            $user = User::factory()->create([
                'is_active' => true,
                'availability_status' => TeamAvailabilityStatus::Available,
            ]);
            $user->assignRole(RolePermissionSeeder::ROLE_AGENT);
            TeamMemberWorkSchedule::query()->create([
                'user_id' => $user->id,
                'work_start_time' => '09:00:00',
                'work_end_time' => '18:00:00',
                'lunch_start_time' => '13:30:00',
                'lunch_end_time' => '14:00:00',
                'short_break_count' => 2,
                'short_break_minutes' => 10,
                'weekly_off_days' => [Carbon::SUNDAY],
            ]);
            $ids[] = $user->id;
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = app(PerformanceIntelligenceEngine::class)->captureDay(
            Carbon::parse('2026-08-04', 'Asia/Kolkata'),
            $ids,
        );

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertFalse($result['skipped']);
        $this->assertSame(12, $result['processed']);
        // Batch collector: well below N×(metrics) for 12 users; allow headroom for upserts.
        $this->assertLessThan(120, $queries, "Expected batched capture; saw {$queries} queries");
    }
}

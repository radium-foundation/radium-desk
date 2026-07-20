<?php

namespace Tests\Unit\Operations;

use App\Models\User;
use App\Services\Operations\TeamMemberActivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeamMemberActivityServiceTest extends TestCase
{
    use RefreshDatabase;

    private TeamMemberActivityService $activityService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activityService = app(TeamMemberActivityService::class);

        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));
        config(['team_member_activity.last_active_throttle_seconds' => 60]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_first_request_updates_last_active_at(): void
    {
        $user = User::factory()->create(['last_active_at' => null]);

        $this->activityService->recordSystemActivity($user);

        $this->assertSame('2026-07-06 10:00:00', $user->fresh()->last_active_at?->format('Y-m-d H:i:s'));
    }

    public function test_second_request_within_throttle_window_performs_no_update(): void
    {
        $user = User::factory()->create([
            'last_active_at' => now(),
        ]);

        Carbon::setTestNow(now()->addSeconds(30));

        $updateCount = $this->countLastActiveAtUpdates(function () use ($user): void {
            $this->activityService->recordSystemActivity($user);
        });

        $this->assertSame(0, $updateCount);
        $this->assertSame('2026-07-06 10:00:00', $user->fresh()->last_active_at?->format('Y-m-d H:i:s'));
    }

    public function test_update_resumes_after_throttle_expires(): void
    {
        $user = User::factory()->create([
            'last_active_at' => now(),
        ]);

        Carbon::setTestNow(now()->addSeconds(61));

        $this->activityService->recordSystemActivity($user);

        $this->assertSame('2026-07-06 10:01:01', $user->fresh()->last_active_at?->format('Y-m-d H:i:s'));
    }

    public function test_multiple_calls_inside_throttle_window_only_generate_one_database_update(): void
    {
        $user = User::factory()->create(['last_active_at' => null]);

        $firstPassUpdates = $this->countLastActiveAtUpdates(function () use ($user): void {
            $this->activityService->recordSystemActivity($user);
        });

        $secondPassUpdates = $this->countLastActiveAtUpdates(function () use ($user): void {
            $this->activityService->recordSystemActivity($user);
            $this->activityService->recordSystemActivity($user);
            $this->activityService->recordSystemActivity($user);
        });

        $this->assertSame(1, $firstPassUpdates);
        $this->assertSame(0, $secondPassUpdates);
    }

    public function test_case_action_still_updates_last_case_action_at_when_last_active_at_is_throttled(): void
    {
        $user = User::factory()->create([
            'last_active_at' => now(),
            'last_case_action_at' => null,
        ]);

        Carbon::setTestNow(now()->addSeconds(15));

        $updates = $this->countUserColumnUpdates(function () use ($user): void {
            $this->activityService->recordCaseAction($user);
        });

        $user->refresh();

        $this->assertSame('2026-07-06 10:00:15', $user->last_case_action_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-06 10:00:00', $user->last_active_at?->format('Y-m-d H:i:s'));
        $this->assertSame(1, $updates['users_table_updates']);
        $this->assertSame(1, $updates['last_case_action_at']);
        $this->assertSame(0, $updates['last_active_at']);
    }

    public function test_record_case_action_performs_one_users_table_update(): void
    {
        $user = User::factory()->create([
            'last_active_at' => null,
            'last_case_action_at' => null,
        ]);

        $updates = $this->countUserColumnUpdates(function () use ($user): void {
            $this->activityService->recordCaseAction($user);
        });

        $this->assertSame(1, $updates['users_table_updates']);
        $this->assertSame(1, $updates['last_case_action_at']);
        $this->assertSame(1, $updates['last_active_at']);
    }

    public function test_record_status_change_performs_one_users_table_update(): void
    {
        $user = User::factory()->create([
            'last_active_at' => null,
            'last_case_action_at' => null,
            'last_status_change_at' => null,
        ]);

        $updates = $this->countUserColumnUpdates(function () use ($user): void {
            $this->activityService->recordStatusChange($user);
        });

        $this->assertSame(1, $updates['users_table_updates']);
        $this->assertSame(1, $updates['last_status_change_at']);
        $this->assertSame(1, $updates['last_case_action_at']);
        $this->assertSame(1, $updates['last_active_at']);
    }

    public function test_batched_activity_columns_receive_identical_timestamps(): void
    {
        $user = User::factory()->create([
            'last_active_at' => null,
            'last_case_action_at' => null,
            'last_status_change_at' => null,
        ]);

        $this->activityService->recordStatusChange($user);

        $user->refresh();

        $this->assertSame('2026-07-06 10:00:00', $user->last_status_change_at?->format('Y-m-d H:i:s'));
        $this->assertSame(
            $user->last_status_change_at?->format('Y-m-d H:i:s'),
            $user->last_case_action_at?->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            $user->last_case_action_at?->format('Y-m-d H:i:s'),
            $user->last_active_at?->format('Y-m-d H:i:s'),
        );
    }

    public function test_record_customer_communication_performs_one_users_table_update(): void
    {
        $user = User::factory()->create([
            'last_active_at' => null,
            'last_customer_communication_at' => null,
        ]);

        $updates = $this->countUserColumnUpdates(function () use ($user): void {
            $this->activityService->recordCustomerCommunication($user);
        });

        $user->refresh();

        $this->assertSame(1, $updates['users_table_updates']);
        $this->assertSame('2026-07-06 10:00:00', $user->last_customer_communication_at?->format('Y-m-d H:i:s'));
        $this->assertSame(
            $user->last_customer_communication_at?->format('Y-m-d H:i:s'),
            $user->last_active_at?->format('Y-m-d H:i:s'),
        );
    }

    private function countLastActiveAtUpdates(callable $callback): int
    {
        return $this->countUserColumnUpdates($callback)['last_active_at'];
    }

    /**
     * @return array{
     *     users_table_updates: int,
     *     last_active_at: int,
     *     last_case_action_at: int,
     *     last_status_change_at: int,
     *     last_customer_communication_at: int
     * }
     */
    private function countUserColumnUpdates(callable $callback): array
    {
        $counts = [
            'users_table_updates' => 0,
            'last_active_at' => 0,
            'last_case_action_at' => 0,
            'last_status_change_at' => 0,
            'last_customer_communication_at' => 0,
        ];

        DB::listen(function ($query) use (&$counts): void {
            $sql = strtolower($query->sql);

            if (! str_starts_with($sql, 'update') || ! str_contains($sql, 'users')) {
                return;
            }

            $counts['users_table_updates']++;

            if (str_contains($sql, 'last_active_at')) {
                $counts['last_active_at']++;
            }

            if (str_contains($sql, 'last_case_action_at')) {
                $counts['last_case_action_at']++;
            }

            if (str_contains($sql, 'last_status_change_at')) {
                $counts['last_status_change_at']++;
            }

            if (str_contains($sql, 'last_customer_communication_at')) {
                $counts['last_customer_communication_at']++;
            }
        });

        $callback();

        return $counts;
    }
}

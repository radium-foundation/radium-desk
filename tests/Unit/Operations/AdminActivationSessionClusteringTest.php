<?php

namespace Tests\Unit\Operations;

use App\Models\AuditLog;
use App\Services\Operations\AdminActivationMetricsService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminActivationSessionClusteringTest extends TestCase
{
    public function test_batch_assignments_within_gap_count_as_one_session(): void
    {
        $service = app(AdminActivationMetricsService::class);
        $base = Carbon::parse('2026-07-06 10:00:00');

        $logs = collect(range(1, 35))->map(function (int $index) use ($base): AuditLog {
            $log = new AuditLog;
            $log->user_id = 7;
            $log->new_values = ['transaction_id' => 'SRV-BATCH-001'];
            $log->created_at = $base->copy()->addMilliseconds($index * 50);

            return $log;
        })->all();

        $this->assertSame(1, $service->countActivationSessions($logs));
    }

    public function test_separate_submissions_with_gap_count_as_multiple_sessions(): void
    {
        $service = app(AdminActivationMetricsService::class);
        $base = Carbon::parse('2026-07-06 10:00:00');

        $logs = [
            $this->activationLog(7, 'SRV-001', $base),
            $this->activationLog(7, 'SRV-001', $base->copy()->addSeconds(10)),
            $this->activationLog(7, 'SRV-002', $base->copy()->addMinutes(5)),
        ];

        $this->assertSame(3, $service->countActivationSessions($logs));
    }

    public function test_different_users_never_share_a_session(): void
    {
        $service = app(AdminActivationMetricsService::class);
        $base = Carbon::parse('2026-07-06 10:00:00');

        $logs = [
            $this->activationLog(7, 'SRV-001', $base),
            $this->activationLog(8, 'SRV-001', $base->copy()->addSecond()),
        ];

        $this->assertSame(2, $service->countActivationSessions($logs));
    }

    private function activationLog(int $userId, string $reference, Carbon $at): AuditLog
    {
        $log = new AuditLog;
        $log->user_id = $userId;
        $log->new_values = ['transaction_id' => $reference];
        $log->created_at = $at;

        return $log;
    }
}

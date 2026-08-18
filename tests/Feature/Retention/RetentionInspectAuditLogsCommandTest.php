<?php

namespace Tests\Feature\Retention;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RetentionInspectAuditLogsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-18 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_command_reports_audit_log_inspection_without_writes(): void
    {
        $user = User::factory()->create();

        $incoming = AuditLog::query()->create([
            'user_id' => $user->id,
            'event' => 'incoming_email.received',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'old_values' => ['message_id' => 1],
            'new_values' => null,
        ]);
        DB::table('audit_logs')->where('id', $incoming->id)->update([
            'created_at' => now()->subDays(120),
        ]);

        $business = AuditLog::query()->create([
            'user_id' => $user->id,
            'event' => 'service_case.status_changed',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'old_values' => ['status' => 'open'],
            'new_values' => ['status' => 'closed'],
        ]);
        DB::table('audit_logs')->where('id', $business->id)->update([
            'created_at' => now()->subDays(400),
        ]);

        $before = AuditLog::query()->count();

        $this->artisan('database:retention-inspect-audit-logs', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('read-only; zero database writes')
            ->expectsOutputToContain('MUST KEEP families')
            ->expectsOutputToContain('incoming_email.received / incoming_email.ignored older than 90 days')
            ->expectsOutputToContain('resolved')
            ->expectsOutputToContain('4.0.39')
            ->expectsOutputToContain('No rows were deleted or modified');

        $this->assertSame($before, AuditLog::query()->count());
        $this->assertSame($before, (int) DB::table('audit_logs')->count());
    }
}

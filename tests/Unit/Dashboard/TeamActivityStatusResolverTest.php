<?php

namespace Tests\Unit\Dashboard;

use App\Enums\PresenceStatus;
use App\Enums\TeamActivityStatus;
use App\Enums\WorkSessionEndReason;
use App\Models\AuditLog;
use App\Support\Dashboard\TeamActivityStatusResolver;
use Tests\TestCase;

class TeamActivityStatusResolverTest extends TestCase
{
    public function test_resolves_leave_from_block_reasons(): void
    {
        $resolver = new TeamActivityStatusResolver;

        $status = $resolver->resolve([
            'on_duty' => false,
            'authority' => ['block_reasons' => ['approved_leave']],
            'presence' => [],
            'session_summary' => [],
        ]);

        $this->assertSame(TeamActivityStatus::Leave, $status);
    }

    public function test_resolves_auto_logout_from_session_summary(): void
    {
        $resolver = new TeamActivityStatusResolver;

        $status = $resolver->resolve([
            'on_duty' => false,
            'authority' => ['block_reasons' => []],
            'presence' => ['session_open' => false],
            'session_summary' => [
                'last_ended_reason' => WorkSessionEndReason::AwayTimeout->value,
            ],
        ]);

        $this->assertSame(TeamActivityStatus::AutoLogout, $status);
    }

    public function test_idle_presence_resolves_to_active_working_status(): void
    {
        $resolver = new TeamActivityStatusResolver;

        $status = $resolver->resolve([
            'on_duty' => true,
            'authority' => ['block_reasons' => []],
            'presence' => [
                'session_open' => true,
                'status' => PresenceStatus::Idle->value,
            ],
            'session_summary' => [],
        ]);

        $this->assertSame(TeamActivityStatus::Working, $status);
        $this->assertSame('Active', $status->label());
    }

    public function test_assignment_audit_never_becomes_current_status(): void
    {
        $resolver = new TeamActivityStatusResolver;
        $audit = new AuditLog(['event' => 'service_case.assigned']);

        $status = $resolver->resolve([
            'on_duty' => true,
            'authority' => ['block_reasons' => []],
            'presence' => [
                'session_open' => true,
                'status' => PresenceStatus::Active->value,
            ],
            'session_summary' => [],
        ], $audit);

        $this->assertSame(TeamActivityStatus::Working, $status);
    }

    public function test_waiting_customer_audit_can_overlay_status(): void
    {
        $resolver = new TeamActivityStatusResolver;
        $audit = new AuditLog(['event' => 'service_case.customer_waiting_started']);

        $status = $resolver->resolve([
            'on_duty' => true,
            'authority' => ['block_reasons' => []],
            'presence' => [
                'session_open' => true,
                'status' => PresenceStatus::Active->value,
            ],
            'session_summary' => [],
        ], $audit);

        $this->assertSame(TeamActivityStatus::WaitingCustomer, $status);
    }

    public function test_working_label_combines_since_duration_and_overtime(): void
    {
        $resolver = new TeamActivityStatusResolver;

        $label = $resolver->workingLabel([
            'presence' => [
                'active_duration' => '5h 12m',
                'login_at' => '09:00',
                'overtime_duration' => '1h 8m',
            ],
            'session_summary' => [],
        ], TeamActivityStatus::Working);

        $this->assertSame('Since 9:00 AM • 5h 12m (+1h 8m OT)', $label);
    }
}

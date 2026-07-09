<?php

namespace Tests\Unit\Operations;

use App\Enums\ServiceCaseSlaStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\Operations\IraNotificationPolicyService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class IraNotificationPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_allows_notification_during_working_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'Asia/Kolkata'));

        $user = $this->createAgentWithSchedule();

        $this->assertTrue(app(IraNotificationPolicyService::class)->canNotifyNow($user));
    }

    public function test_blocks_notification_outside_working_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 20:00:00', 'Asia/Kolkata'));

        $user = $this->createAgentWithSchedule();

        $this->assertFalse(app(IraNotificationPolicyService::class)->canNotifyNow($user));
    }

    public function test_high_priority_incident_bypasses_working_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 20:00:00', 'Asia/Kolkata'));

        $user = $this->createAgentWithSchedule();
        $incident = $this->createIncident(highPriority: true);

        $this->assertTrue(app(IraNotificationPolicyService::class)->canNotifyNow($user, $incident));
    }

    public function test_sla_warning_incident_bypasses_working_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 20:00:00', 'Asia/Kolkata'));

        $user = $this->createAgentWithSchedule();
        $incident = $this->createIncident();
        $incident->created_at = now()->subHours(25);
        $incident->save();
        $incident->refresh()->load('order');

        $this->assertSame(ServiceCaseSlaStatus::Warning, $incident->slaStatus());
        $this->assertTrue(app(IraNotificationPolicyService::class)->canNotifyNow($user, $incident));
    }

    public function test_escalation_specialist_respects_working_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 20:00:00', 'Asia/Kolkata'));

        $specialist = User::factory()->create(['is_active' => true]);
        $specialist->assignRole(RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST);
        $this->createScheduleFor($specialist);

        $this->assertFalse(app(IraNotificationPolicyService::class)->canNotifyNow($specialist));
    }

    public function test_explicit_urgent_context_bypasses_working_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 20:00:00', 'Asia/Kolkata'));

        $user = $this->createAgentWithSchedule();
        $policy = app(IraNotificationPolicyService::class);

        $this->assertTrue($policy->canNotifyNowWithContext($user, null, ['urgent' => true]));
    }

    private function createAgentWithSchedule(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);
        $this->createScheduleFor($user);

        return $user;
    }

    private function createScheduleFor(User $user): TeamMemberWorkSchedule
    {
        return TeamMemberWorkSchedule::query()->create([
            'user_id' => $user->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);
    }

    private function createIncident(bool $highPriority = false): Incident
    {
        $creator = User::factory()->create(['is_active' => true]);
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-POLICY-'.uniqid(),
            'serial_number' => 'SN-POLICY',
            'product_name' => 'FM220',
            'device_model' => 'FM220',
            'transaction_id' => null,
            'status' => 'active',
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'RD-POLICY-'.uniqid(),
            'category' => 'General',
            'source' => \App\Enums\IncidentSource::Internal,
            'title' => 'Policy unit test',
            'description' => 'Policy unit test.',
            'status' => \App\Enums\IncidentStatus::Open,
            'high_priority' => $highPriority,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }
}

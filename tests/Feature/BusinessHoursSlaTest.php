<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\ServiceCaseSlaStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BusinessHoursSlaTest extends TestCase
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
        config(['sla.business_hours_enabled' => false]);

        parent::tearDown();
    }

    public function test_wall_clock_sla_remains_default_when_flag_disabled(): void
    {
        config(['sla.business_hours_enabled' => false]);

        $incident = $this->createPendingIncident();
        $incident->forceFill(['created_at' => now()->subHours(30)])->saveQuietly();
        $incident->load('order');

        $this->assertSame(ServiceCaseSlaStatus::Warning, $incident->slaStatus());
    }

    public function test_business_hours_sla_ignores_weekend_when_flag_enabled(): void
    {
        config(['sla.business_hours_enabled' => true]);

        Carbon::setTestNow(Carbon::parse('2026-07-13 10:00:00')); // Monday

        $incident = $this->createPendingIncident();
        $incident->forceFill(['created_at' => Carbon::parse('2026-07-10 17:00:00')])->saveQuietly(); // Friday evening
        $incident->load('order');

        // Wall clock: Fri 17:00 -> Mon 10:00 = 65 hours (Warning/Overdue)
        // Business hours: Fri 17:00-18:00 (1h) + Sat full day (8.5h) + Mon 09:00-10:00 (1h) = 10.5h
        $this->assertSame(ServiceCaseSlaStatus::WithinSla, $incident->slaStatus());
    }

    public function test_business_hours_sla_respects_warning_threshold_when_flag_enabled(): void
    {
        config(['sla.business_hours_enabled' => true]);

        Carbon::setTestNow(Carbon::parse('2026-07-08 12:00:00')); // Wednesday midday

        $incident = $this->createPendingIncident();
        $incident->forceFill(['created_at' => Carbon::parse('2026-07-06 09:00:00')])->saveQuietly(); // Monday start
        $incident->load('order');

        // ~20 business hours across Mon-Wed midday (within 24h warning threshold)
        $this->assertSame(ServiceCaseSlaStatus::WithinSla, $incident->slaStatus());
    }

    public function test_business_hours_sla_uses_assignee_weekly_off(): void
    {
        config(['sla.business_hours_enabled' => true]);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $agent->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 0,
            'short_break_minutes' => 0,
            'weekly_off_days' => [Carbon::SATURDAY, Carbon::SUNDAY],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-13 10:00:00')); // Monday

        $incident = $this->createPendingIncident($agent);
        $incident->forceFill(['created_at' => Carbon::parse('2026-07-10 17:00:00')])->saveQuietly();
        $incident->load(['order', 'assignee.workSchedule']);

        // Fri 1h + Mon 1h = 2 business hours (Sat/Sun off for assignee)
        $this->assertSame(ServiceCaseSlaStatus::WithinSla, $incident->slaStatus());
    }

    private function createPendingIncident(?User $assignee = null): Incident
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-BHSLA-'.uniqid(),
            'serial_number' => 'SN-BHSLA',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-BHSLA-'.uniqid(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Business hours SLA integration test',
            'description' => 'Business hours SLA integration test.',
            'status' => 'open',
            'high_priority' => false,
            'created_by' => $admin->id,
            'assigned_to_user_id' => $assignee?->id,
        ]);
    }
}

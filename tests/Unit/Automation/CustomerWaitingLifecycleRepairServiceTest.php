<?php

namespace Tests\Unit\Automation;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\WaitingReason;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\User;
use App\Services\Automation\CustomerWaitingLifecycleRepairService;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CustomerWaitingLifecycleRepairServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);

        $this->seed(RolePermissionSeeder::class);

        User::factory()->create([
            'email' => 'superadmin@radium.local',
        ])->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);
    }

    public function test_dry_run_finds_stale_and_policy_mismatches_without_writing(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $closed = $this->makeIncident($agent, IncidentStatus::Closed);
        IncidentWaitingState::query()->create([
            'incident_id' => $closed->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => Carbon::parse('2026-07-05 10:00:00'),
            'sla_paused' => true,
            'reminder_policy_key' => 'customer_waiting_default',
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $open = $this->makeIncident($agent, IncidentStatus::Open);
        IncidentWaitingState::query()->create([
            'incident_id' => $open->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => Carbon::parse('2026-07-05 10:00:00'),
            'sla_paused' => true,
            'reminder_policy_key' => 'serial_number_default',
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $summary = app(CustomerWaitingLifecycleRepairService::class)->repair(dryRun: true);

        $this->assertSame(1, $summary->counts['stale_on_closed_found']);
        $this->assertSame(0, $summary->counts['stale_on_closed_cleared']);
        $this->assertSame(1, $summary->counts['policy_mismatch_found']);
        $this->assertSame(0, $summary->counts['policy_mismatch_repaired']);
        $this->assertNull($closed->fresh()->activeWaitingState?->cleared_at);
        $this->assertSame(
            'serial_number_default',
            $open->fresh()->activeWaitingState?->reminder_policy_key,
        );
    }

    public function test_apply_clears_stale_and_repairs_policy_without_customer_notifications(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $closed = $this->makeIncident($agent, IncidentStatus::Closed);
        IncidentWaitingState::query()->create([
            'incident_id' => $closed->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => Carbon::parse('2026-07-05 10:00:00'),
            'sla_paused' => true,
            'reminder_policy_key' => 'customer_waiting_default',
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $open = $this->makeIncident($agent, IncidentStatus::Open);
        IncidentWaitingState::query()->create([
            'incident_id' => $open->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => Carbon::parse('2026-07-05 10:00:00'),
            'sla_paused' => true,
            'reminder_policy_key' => 'serial_number_default',
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $summary = app(CustomerWaitingLifecycleRepairService::class)->repair(dryRun: false);

        $this->assertSame(1, $summary->counts['stale_on_closed_cleared']);
        $this->assertSame(1, $summary->counts['policy_mismatch_repaired']);
        $this->assertNotNull(
            IncidentWaitingState::query()->where('incident_id', $closed->id)->value('cleared_at'),
        );
        $this->assertSame(
            'customer_waiting_default',
            $open->fresh()->activeWaitingState?->reminder_policy_key,
        );
    }

    private function makeIncident(User $actor, IncidentStatus $status): Incident
    {
        $order = Order::query()->create([
            'order_id' => 'RD-REPAIR-'.uniqid(),
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Repair lifecycle',
            'description' => 'Repair lifecycle.',
            'status' => $status,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'assigned_to_user_id' => $actor->id,
        ]);
    }
}

<?php

namespace Tests\Feature\Automation;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\WaitingReason;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerWaitingLifecycleRepairCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_completes_with_empty_users_table(): void
    {
        config([
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);

        $this->assertSame(0, User::query()->count());

        $this->artisan('customer-waiting:repair-lifecycle', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();
    }

    public function test_apply_mode_reports_missing_automation_user_without_exception(): void
    {
        $this->seed(RolePermissionSeeder::class);

        config([
            'cashfree.system_user_email' => 'missing@radium.local',
        ]);

        User::query()->where('email', 'superadmin@radium.local')->delete();

        $agent = User::factory()->create(['email' => 'agent@example.com']);
        $agent->assignRole(\Database\Seeders\RolePermissionSeeder::ROLE_AGENT);

        $closed = \App\Models\Incident::query()->create([
            'order_id' => \App\Models\Order::query()->create([
                'order_id' => 'RD-APPLY-ERR',
                'serial_number' => null,
                'product_name' => 'MFS 110',
                'device_model' => 'MFS 110',
                'customer_phone' => '9876543210',
                'status' => 'active',
                'created_by' => $agent->id,
            ])->id,
            'reference_no' => app(\App\Services\IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => \App\Enums\IncidentSource::Call,
            'title' => 'Closed',
            'description' => 'Closed.',
            'status' => \App\Enums\IncidentStatus::Closed,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        \App\Models\IncidentWaitingState::query()->create([
            'incident_id' => $closed->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => now(),
            'sla_paused' => true,
            'reminder_policy_key' => 'customer_waiting_default',
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        User::query()->delete();

        $this->artisan('customer-waiting:repair-lifecycle')
            ->expectsOutputToContain('Automation system user not found')
            ->assertFailed();
    }
}

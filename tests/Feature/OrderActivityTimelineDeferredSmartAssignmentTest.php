<?php

namespace Tests\Feature;

use App\Enums\AssignmentOrigin;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderActivityTimelineService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OrderActivityTimelineDeferredSmartAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-11 12:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_deferred_smart_assignment_resolves_historical_target_not_current_incident_assignee(): void
    {
        $system = User::factory()->create(['name' => 'Ira']);
        $system->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $avinash = User::factory()->create(['name' => 'Avinash Jha']);
        $avinash->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $vanshika = User::factory()->create(['name' => 'Vanshika Baniwal']);
        $vanshika->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-DEFERRED-ASSIGNEE',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $system->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-DEFERRED',
            'category' => 'General',
            'source' => IncidentSource::Cashfree->value,
            'title' => 'Deferred assignee fixture',
            'description' => 'Deferred assignee fixture.',
            'status' => IncidentStatus::Open->value,
            'assignment_origin' => AssignmentOrigin::AppointmentSmartAssignment->value,
            'assigned_to_user_id' => $avinash->id,
            'created_by' => $system->id,
            'created_at' => Carbon::parse('2026-08-11 10:00:00', 'Asia/Kolkata'),
        ]);

        AuditLog::query()->create([
            'user_id' => $system->id,
            'event' => 'service_case.deferred_smart_assignment',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'created_at' => Carbon::parse('2026-08-11 10:15:44', 'Asia/Kolkata'),
            'old_values' => ['assigned_to_user_id' => $avinash->id],
            'new_values' => [
                'assigned_to_user_id' => $vanshika->id,
                'assignment_origin' => AssignmentOrigin::AppointmentSmartAssignment->value,
                'assignment_method' => 'smart',
            ],
        ]);

        $titles = app(OrderActivityTimelineService::class)
            ->forOrder($order->fresh())
            ->pluck('title')
            ->all();

        $this->assertContains('Reassigned to Vanshika', $titles);
        $this->assertNotContains('Reassigned to Avinash', $titles);
    }
}

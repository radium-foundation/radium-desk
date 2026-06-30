<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\ServiceCaseAutomationStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\ServiceCaseAutomationHealthService;
use App\Services\ServiceCaseAutomationStatusService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerialPlaceholderAutomationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    private function createAgentUser(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $user;
    }

    public function test_placeholder_serial_shows_waiting_for_customer_serial_status_for_agent(): void
    {
        $agent = $this->createAgentUser();
        $actor = User::factory()->create();

        $order = Order::query()->create([
            'order_id' => 'RD-PLACEHOLDER-STATUS',
            'serial_number' => 'FPSPL1141XX',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Placeholder serial',
            'description' => 'Waiting for real serial.',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $agent->id,
            'created_by' => $actor->id,
        ]);

        $status = app(ServiceCaseAutomationStatusService::class)
            ->statusFor($incident->fresh(['order', 'assignee']));

        $this->assertSame(ServiceCaseAutomationStatus::WaitingForCustomerSerial, $status);
        $this->assertSame('Waiting for Customer Serial', $status->label());
    }

    public function test_placeholder_serial_is_counted_separately_from_validation_failed(): void
    {
        $agent = $this->createAgentUser();
        $actor = User::factory()->create();

        Order::query()->create([
            'order_id' => 'RD-PLACEHOLDER-COUNT',
            'serial_number' => 'UNKNOWN',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ])->incidents()->create([
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Placeholder',
            'description' => 'Placeholder',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $agent->id,
            'created_by' => $actor->id,
        ]);

        Order::query()->create([
            'order_id' => 'RD-INVALID-COUNT',
            'serial_number' => 'ABC123',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ])->incidents()->create([
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Invalid',
            'description' => 'Invalid',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $agent->id,
            'created_by' => $actor->id,
        ]);

        $counts = app(ServiceCaseAutomationHealthService::class)->counts();

        $this->assertSame(1, $counts['waiting_for_customer_serial']);
        $this->assertSame(1, $counts['validation_failed']);
    }
}

<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncCompletedOrdersServiceCasesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_command_is_registered(): void
    {
        $this->artisan('service-cases:sync-closed-status --help')
            ->assertSuccessful();
    }

    public function test_dry_run_shows_table_and_does_not_close_service_cases(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = $this->createCompletedOrder($admin, 'RD-SYNC-DRY');
        $incident = $this->createUnfinishedServiceCase($order, $admin, 'SC-SYNC-DRY', IncidentStatus::InProgress);

        $this->artisan('service-cases:sync-closed-status --dry-run')
            ->expectsTable(
                ['Order ID', 'Service Case Reference', 'Current Status', 'Action'],
                [[
                    $order->order_id,
                    $incident->display_reference,
                    'In Progress',
                    'Would Close',
                ]],
            )
            ->expectsOutputToContain('Orders scanned: 1')
            ->expectsOutputToContain('Service Cases updated: 0')
            ->expectsOutputToContain('Skipped: 0')
            ->expectsOutputToContain('Failures: 0')
            ->assertSuccessful();

        $this->assertSame(IncidentStatus::InProgress, $incident->fresh()->status);
        $this->assertSame(0, AuditLog::query()->where('event', 'service_case.status_changed')->count());
    }

    public function test_command_closes_unfinished_service_cases_for_completed_orders(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = $this->createCompletedOrder($admin, 'RD-SYNC-RUN');
        $openCase = $this->createUnfinishedServiceCase($order, $admin, 'SC-SYNC-OPEN', IncidentStatus::Open);
        $resolvedCase = $this->createUnfinishedServiceCase($order, $admin, 'SC-SYNC-RESOLVED', IncidentStatus::Resolved);
        $closedCase = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-SYNC-CLOSED',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Already closed',
            'description' => 'Already closed.',
            'status' => IncidentStatus::Closed->value,
            'created_by' => $admin->id,
        ]);

        $this->artisan('service-cases:sync-closed-status')
            ->expectsOutputToContain('Orders scanned: 1')
            ->expectsOutputToContain('Service Cases updated: 2')
            ->expectsOutputToContain('Skipped: 0')
            ->expectsOutputToContain('Failures: 0')
            ->assertSuccessful();

        $this->assertSame(IncidentStatus::Closed, $openCase->fresh()->status);
        $this->assertSame(IncidentStatus::Closed, $resolvedCase->fresh()->status);
        $this->assertSame(IncidentStatus::Closed, $closedCase->fresh()->status);
    }

    public function test_command_skips_orders_without_unfinished_service_cases(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $orderWithOpenCase = $this->createCompletedOrder($admin, 'RD-SYNC-HAS-OPEN');
        $this->createUnfinishedServiceCase($orderWithOpenCase, $admin, 'SC-SYNC-HAS-OPEN', IncidentStatus::Open);

        $orderAlreadyClosed = $this->createCompletedOrder($admin, 'RD-SYNC-ALREADY-CLOSED');
        Incident::query()->create([
            'order_id' => $orderAlreadyClosed->id,
            'reference_no' => 'SC-SYNC-ALREADY-CLOSED',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Closed case',
            'description' => 'Closed case.',
            'status' => IncidentStatus::Closed->value,
            'created_by' => $admin->id,
        ]);

        $this->artisan('service-cases:sync-closed-status')
            ->expectsOutputToContain('Orders scanned: 2')
            ->expectsOutputToContain('Service Cases updated: 1')
            ->expectsOutputToContain('Skipped: 1')
            ->expectsOutputToContain('Failures: 0')
            ->assertSuccessful();
    }

    public function test_command_ignores_orders_without_transaction_id(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $pendingOrder = Order::query()->create([
            'order_id' => 'RD-SYNC-NO-TXN',
            'serial_number' => 'SN-SYNC-NO-TXN',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $incident = $this->createUnfinishedServiceCase($pendingOrder, $admin, 'SC-SYNC-NO-TXN', IncidentStatus::Open);

        $this->artisan('service-cases:sync-closed-status')
            ->expectsOutputToContain('Orders scanned: 0')
            ->expectsOutputToContain('Service Cases updated: 0')
            ->expectsOutputToContain('Skipped: 0')
            ->expectsOutputToContain('Failures: 0')
            ->assertSuccessful();

        $this->assertSame(IncidentStatus::Open, $incident->fresh()->status);
    }

    public function test_command_creates_audit_logs_when_closing_service_cases(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = $this->createCompletedOrder($admin, 'RD-SYNC-AUDIT');
        $incident = $this->createUnfinishedServiceCase($order, $admin, 'SC-SYNC-AUDIT', IncidentStatus::Open);

        $this->artisan('service-cases:sync-closed-status')
            ->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'service_case.status_changed',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_command_reports_failure_when_no_actor_user_available(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-SYNC-NO-ACTOR',
            'serial_number' => 'SN-SYNC-NO-ACTOR',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => 'TX-NO-ACTOR',
            'completed_at' => now(),
            'status' => 'active',
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-SYNC-NO-ACTOR',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'No actor available',
            'description' => 'No actor available.',
            'status' => IncidentStatus::Open->value,
            'created_by' => $admin->id,
        ]);

        $this->artisan('service-cases:sync-closed-status')
            ->expectsOutputToContain('Failed to close '.$incident->display_reference.' for order '.$order->order_id.': no actor user available.')
            ->expectsOutputToContain('Orders scanned: 1')
            ->expectsOutputToContain('Service Cases updated: 0')
            ->expectsOutputToContain('Skipped: 0')
            ->expectsOutputToContain('Failures: 1')
            ->assertSuccessful();

        $this->assertSame(IncidentStatus::Open, $incident->fresh()->status);
    }

    public function test_command_continues_processing_after_individual_failure(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $failingOrder = Order::query()->create([
            'order_id' => 'RD-SYNC-FAIL',
            'serial_number' => 'SN-SYNC-FAIL',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => 'TX-FAIL',
            'completed_at' => now(),
            'status' => 'active',
        ]);

        $failingIncident = Incident::query()->create([
            'order_id' => $failingOrder->id,
            'reference_no' => 'SC-SYNC-FAIL',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Will fail',
            'description' => 'Will fail.',
            'status' => IncidentStatus::Open->value,
            'created_by' => $admin->id,
        ]);

        $successOrder = $this->createCompletedOrder($admin, 'RD-SYNC-SUCCESS');
        $successIncident = $this->createUnfinishedServiceCase($successOrder, $admin, 'SC-SYNC-SUCCESS', IncidentStatus::Open);

        $this->artisan('service-cases:sync-closed-status')
            ->expectsOutputToContain('Orders scanned: 2')
            ->expectsOutputToContain('Service Cases updated: 1')
            ->expectsOutputToContain('Skipped: 0')
            ->expectsOutputToContain('Failures: 1')
            ->assertSuccessful();

        $this->assertSame(IncidentStatus::Open, $failingIncident->fresh()->status);
        $this->assertSame(IncidentStatus::Closed, $successIncident->fresh()->status);
    }

    private function createCompletedOrder(User $admin, string $orderId): Order
    {
        return Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => "SN-{$orderId}",
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => "TX-{$orderId}",
            'completed_at' => now(),
            'transaction_assigned_by' => $admin->id,
            'status' => 'active',
            'created_by' => $admin->id,
        ]);
    }

    private function createUnfinishedServiceCase(
        Order $order,
        User $admin,
        string $referenceNo,
        IncidentStatus $status,
    ): Incident {
        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => $referenceNo,
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Sync test case',
            'description' => 'Sync test case.',
            'status' => $status->value,
            'created_by' => $admin->id,
        ]);
    }
}

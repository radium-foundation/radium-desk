<?php

namespace Tests\Feature;

use App\Enums\ApprovedRefundMethod;
use App\Enums\BusinessHoldType;
use App\Enums\IncidentStatus;
use App\Enums\RefundStatus;
use App\Models\BusinessHold;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\BusinessHoldService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\RefundNotificationService;
use App\Services\Refunds\RefundCompletedOpenCaseRepairService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RefundCompletionNotificationDecoupleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'cashfree.system_user_email' => 'ops@example.com',
            'interakt.templates.refund_confirmation.enabled' => false,
            'interakt.templates.refund_confirmation.name' => null,
            'mail.enabled' => false,
            'notifications.email.enabled' => false,
        ]);
    }

    public function test_whatsapp_template_disabled_still_closes_case_hold_and_refund(): void
    {
        [$ops, $refund, $incident] = $this->pendingExecutionRefund(
            channels: ['whatsapp'],
            withHold: true,
        );

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [
                'execution_reference_no' => 'UTR-WA-DISABLED',
                'execution_transaction_id' => 'TXN-WA-DISABLED',
            ])
            ->assertRedirect(route('refunds.show', $refund))
            ->assertSessionHas('status', 'refund-completed');

        $this->assertClosedWorkflow($refund, $incident);
        $this->assertCustomerNotificationOutcome($refund, expectedOutcomes: ['skipped', 'failed']);
    }

    public function test_email_unavailable_still_closes_case(): void
    {
        [$ops, $refund, $incident] = $this->pendingExecutionRefund(
            channels: ['email'],
            withHold: true,
        );

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [
                'execution_reference_no' => 'UTR-EMAIL-OFF',
                'execution_transaction_id' => 'TXN-EMAIL-OFF',
            ])
            ->assertSessionHas('status', 'refund-completed');

        $this->assertClosedWorkflow($refund, $incident);
        $this->assertCustomerNotificationOutcome($refund, expectedOutcomes: ['failed']);
    }

    public function test_whatsapp_and_email_unavailable_still_closes_case(): void
    {
        [$ops, $refund, $incident] = $this->pendingExecutionRefund(
            channels: ['email', 'whatsapp'],
            withHold: true,
        );

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [
                'execution_reference_no' => 'UTR-BOTH-OFF',
                'execution_transaction_id' => 'TXN-BOTH-OFF',
            ])
            ->assertSessionHas('status', 'refund-completed');

        $this->assertClosedWorkflow($refund, $incident);
    }

    public function test_customer_notification_exception_still_closes_case(): void
    {
        [$ops, $refund, $incident] = $this->pendingExecutionRefund(
            channels: ['whatsapp'],
            withHold: true,
        );

        $notification = Mockery::mock(RefundNotificationService::class);
        $notification->shouldReceive('notifyCustomer')
            ->once()
            ->andThrow(new RuntimeException('Interakt timeout'));
        $notification->shouldReceive('notifyRequesterOfDecision')->once();
        $this->app->instance(RefundNotificationService::class, $notification);

        Log::spy();

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [
                'execution_reference_no' => 'UTR-THROW',
                'execution_transaction_id' => 'TXN-THROW',
            ])
            ->assertSessionHas('status', 'refund-completed');

        $this->assertClosedWorkflow($refund, $incident);
    }

    public function test_requester_notification_exception_still_closes_case(): void
    {
        [$ops, $refund, $incident] = $this->pendingExecutionRefund(
            channels: [],
            withHold: true,
        );

        $notification = Mockery::mock(RefundNotificationService::class);
        $notification->shouldReceive('notifyCustomer')->once()->andReturn(null);
        $notification->shouldReceive('notifyRequesterOfDecision')
            ->once()
            ->andThrow(new RuntimeException('Telegram unavailable'));
        $this->app->instance(RefundNotificationService::class, $notification);

        Log::spy();

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [
                'execution_reference_no' => 'UTR-REQ-FAIL',
                'execution_transaction_id' => 'TXN-REQ-FAIL',
            ])
            ->assertSessionHas('status', 'refund-completed');

        $this->assertClosedWorkflow($refund, $incident);
    }

    public function test_dispatcher_false_result_still_closes_case(): void
    {
        [$ops, $refund, $incident] = $this->pendingExecutionRefund(
            channels: ['whatsapp'],
            withHold: true,
        );

        $dispatcher = Mockery::mock(NotificationDispatcher::class);
        $dispatcher->shouldReceive('send')->once()->andReturn(
            new \App\Data\NotificationDispatchResult(
                success: false,
                results: [],
                message: 'WhatsApp provider unavailable',
            ),
        );
        $this->app->instance(NotificationDispatcher::class, $dispatcher);

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [
                'execution_reference_no' => 'UTR-FALSE',
                'execution_transaction_id' => 'TXN-FALSE',
            ])
            ->assertSessionHas('status', 'refund-completed');

        $this->assertClosedWorkflow($refund, $incident);
        $this->assertCustomerNotificationOutcome($refund, expectedOutcomes: ['failed']);
    }

    public function test_normal_completion_with_empty_channels_still_closes(): void
    {
        [$ops, $refund, $incident] = $this->pendingExecutionRefund(
            channels: [],
            withHold: true,
        );

        $this->actingAs($ops)
            ->post(route('refunds.complete', $refund), [
                'execution_reference_no' => 'UTR-OK',
                'execution_transaction_id' => 'TXN-OK',
            ])
            ->assertSessionHas('status', 'refund-completed');

        $this->assertClosedWorkflow($refund, $incident);
    }

    public function test_repair_command_dry_run_and_execute_for_stuck_completed_refund(): void
    {
        $ops = User::factory()->create(['email' => 'ops@example.com']);
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $order = $this->createOrder($ops, 'RD-REPAIR-1');
        $incident = $this->createIncident($ops, $order, 'SC-REPAIR-1', IncidentStatus::AwaitingProductDetails);
        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'reference_no' => 'REF-2026-009901',
            'amount' => 499,
            'refund_amount' => 499,
            'reason' => 'Stuck completed refund needing repair.',
            'status' => RefundStatus::Completed,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'requested_by' => $ops->id,
            'reviewed_by' => $ops->id,
            'reviewed_at' => now(),
            'executed_by' => $ops->id,
            'executed_at' => now(),
            'execution_reference_no' => 'REF-2026-009901',
            'communication_channels' => ['whatsapp'],
        ]);

        app(BusinessHoldService::class)->activateRefundHold($incident, $refund, $ops);

        $this->artisan('refunds:repair-completed-open-cases', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('would_repair: 1');

        $refund->refresh();
        $incident->refresh();
        $this->assertSame(RefundStatus::Completed, $refund->status);
        $this->assertSame(IncidentStatus::AwaitingProductDetails, $incident->status);
        $this->assertTrue(app(BusinessHoldService::class)->hasActiveHold($incident, BusinessHoldType::Refund));

        $this->artisan('refunds:repair-completed-open-cases')
            ->assertSuccessful()
            ->expectsOutputToContain('repaired: 1');

        $this->assertClosedWorkflow($refund, $incident);
    }

    public function test_repair_service_skips_refunds_without_active_hold(): void
    {
        $ops = User::factory()->create(['email' => 'ops@example.com']);
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $order = $this->createOrder($ops, 'RD-REPAIR-2');
        $incident = $this->createIncident($ops, $order, 'SC-REPAIR-2');
        RefundRequest::query()->create([
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'reference_no' => 'REF-2026-009902',
            'amount' => 100,
            'refund_amount' => 100,
            'reason' => 'Completed without hold should be ignored by repair.',
            'status' => RefundStatus::Completed,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'requested_by' => $ops->id,
            'executed_by' => $ops->id,
            'executed_at' => now(),
        ]);

        $summary = app(RefundCompletedOpenCaseRepairService::class)->repair(dryRun: true);

        $this->assertSame(0, $summary['scanned']);
        $this->assertSame(0, $summary['repaired']);
    }

    /**
     * @param  list<string>  $channels
     * @return array{0: User, 1: RefundRequest, 2: Incident}
     */
    private function pendingExecutionRefund(array $channels, bool $withHold): array
    {
        $ops = User::factory()->create(['email' => 'ops@example.com']);
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $order = $this->createOrder($ops, 'RD-DEC-'.uniqid());
        $order->update([
            'customer_phone' => '9999999999',
            'customer_email' => 'customer@example.com',
        ]);
        $incident = $this->createIncident($ops, $order, 'SC-DEC-'.uniqid());

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'reference_no' => 'REF-2026-'.random_int(100000, 999999),
            'amount' => 1000,
            'refund_amount' => 1000,
            'reason' => 'Decouple notification from refund completion hotfix coverage.',
            'status' => RefundStatus::PendingExecution,
            'approved_refund_method' => ApprovedRefundMethod::BankTransfer,
            'requested_by' => $ops->id,
            'reviewed_by' => $ops->id,
            'reviewed_at' => now(),
            'communication_channels' => $channels,
        ]);

        if ($withHold) {
            app(BusinessHoldService::class)->activateRefundHold($incident, $refund, $ops);
            $this->assertTrue(app(BusinessHoldService::class)->hasActiveHold($incident->fresh(), BusinessHoldType::Refund));
        }

        return [$ops, $refund, $incident];
    }

    private function assertClosedWorkflow(RefundRequest $refund, Incident $incident): void
    {
        $refund->refresh();
        $incident->refresh();

        $this->assertSame(RefundStatus::Closed, $refund->status);
        $this->assertNotNull($refund->closed_at);
        $this->assertSame(IncidentStatus::Closed, $incident->status);
        $this->assertFalse(app(BusinessHoldService::class)->hasActiveHold($incident));
        $this->assertNotNull(
            BusinessHold::query()->where('incident_id', $incident->id)->value('cleared_at'),
        );
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'refund.closed',
            'auditable_type' => $refund->getMorphClass(),
            'auditable_id' => $refund->id,
        ]);
    }

    /**
     * @param  list<string>  $expectedOutcomes
     */
    private function assertCustomerNotificationOutcome(RefundRequest $refund, array $expectedOutcomes): void
    {
        $audit = \App\Models\AuditLog::query()
            ->where('event', 'refund.customer_notified')
            ->where('auditable_type', $refund->getMorphClass())
            ->where('auditable_id', $refund->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $outcome = $audit->new_values['outcome'] ?? null;
        $this->assertContains($outcome, $expectedOutcomes);
    }

    private function createOrder(User $user, string $orderId): Order
    {
        return Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'status' => 'active',
            'payment_amount' => 1000,
            'created_by' => $user->id,
        ]);
    }

    private function createIncident(
        User $user,
        Order $order,
        string $referenceNo,
        IncidentStatus $status = IncidentStatus::Open,
    ): Incident {
        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => $referenceNo,
            'category' => 'Hardware',
            'source' => 'internal',
            'title' => 'Refund notification decouple incident',
            'description' => 'Incident for refund completion notification decoupling tests.',
            'status' => $status,
            'created_by' => $user->id,
        ]);
    }
}

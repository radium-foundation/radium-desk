<?php

namespace Tests\Feature;

use App\Enums\ApprovedRefundMethod;
use App\Enums\CustomerPreferredRefundMethod;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OrderStatus;
use App\Enums\RefundDeductionProfile;
use App\Enums\WaitingReason;
use App\Events\Dashboard\DashboardKpisUpdated;
use App\Events\Dashboard\ServiceCaseCreated;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\BusinessHoldService;
use App\Services\DashboardBroadcastService;
use App\Services\IncidentWaitingStateService;
use App\Services\RefundRequestService;
use App\Services\ServiceCaseAssignmentService;
use App\Services\ServiceCaseStatusService;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DashboardReverbCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        $dayAdmin = User::factory()->create(['email' => 'day-admin-reverb@test.com']);
        $dayAdmin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        app(SettingService::class)->setMany([
            'assignment.timezone' => config('app.timezone'),
            'assignment.day_shift_start' => '09:00',
            'assignment.day_shift_end' => '18:30',
            'assignment.day_shift_admin_user_id' => (string) $dayAdmin->id,
            'assignment.night_shift_admin_user_id' => (string) $dayAdmin->id,
            'assignment.fallback_admin_1_user_id' => '',
            'assignment.fallback_admin_2_user_id' => '',
        ]);
    }

    public function test_kpis_updated_broadcast_includes_filter_counts(): void
    {
        Event::fake([DashboardKpisUpdated::class]);

        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $actor = User::factory()->create(['is_active' => true]);
        $actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        app(DashboardBroadcastService::class)->kpisUpdated($actor);

        Event::assertDispatched(DashboardKpisUpdated::class, function (DashboardKpisUpdated $event) use ($viewer): bool {
            return $event->recipient->id === $viewer->id
                && is_array($event->serviceCaseFilterCountVariants)
                && array_key_exists(
                    \App\Services\DashboardPersonalizationService::SCOPE_OPERATIONS,
                    $event->serviceCaseFilterCountVariants,
                )
                && array_key_exists(
                    'action_required',
                    $event->serviceCaseFilterCountVariants[\App\Services\DashboardPersonalizationService::SCOPE_OPERATIONS],
                );
        });
    }

    public function test_waiting_state_start_broadcasts_queue_membership_change(): void
    {
        $broadcastSpy = $this->spy(DashboardBroadcastService::class);
        $this->app->instance(DashboardBroadcastService::class, $broadcastSpy);

        $actor = User::factory()->create();
        $actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'ORD-WAITING-BROADCAST',
            'serial_number' => 'SN-WAITING-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => OrderStatus::Active,
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-WAITING-1',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Waiting broadcast',
            'description' => 'Waiting broadcast',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
        ]);

        app(IncidentWaitingStateService::class)->start(
            incident: $incident,
            reason: WaitingReason::CustomerNotResponding,
            actor: $actor,
        );

        $broadcastSpy->shouldHaveReceived('serviceCaseQueueMembershipChanged')->once();
    }

    public function test_business_hold_activation_broadcasts_queue_membership_change(): void
    {
        Event::fake([ServiceCaseCreated::class, DashboardKpisUpdated::class]);

        $actor = User::factory()->create();
        $actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'ORD-HOLD-BROADCAST',
            'serial_number' => 'SN-HOLD-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => OrderStatus::Active,
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-HOLD-1',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Hold broadcast',
            'description' => 'Hold broadcast',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
        ]);

        $refund = \App\Models\RefundRequest::query()->create([
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'reference_no' => 'RF-HOLD-1',
            'amount' => 100,
            'refund_amount' => 100,
            'reason' => 'Test',
            'requester_remarks' => 'Test',
            'customer_preferred_method' => CustomerPreferredRefundMethod::Opm,
            'status' => \App\Enums\RefundStatus::Pending,
            'total_paid_amount' => 100,
            'already_refunded_amount' => 0,
            'maximum_refundable' => 100,
            'cancellation_charges' => 0,
            'gst_on_cancellation' => 0,
            'other_deduction' => 0,
            'total_deduction' => 0,
            'deduction_profile_key' => RefundDeductionProfile::FullRefund,
            'communication_channels' => ['email'],
            'requested_by' => $actor->id,
        ]);

        app(BusinessHoldService::class)->activateRefundHold($incident, $refund, $actor);

        Event::assertDispatched(ServiceCaseCreated::class);
        Event::assertDispatched(DashboardKpisUpdated::class);
    }

    public function test_refund_approval_broadcasts_kpis(): void
    {
        $broadcastSpy = $this->spy(DashboardBroadcastService::class);
        $this->app->instance(DashboardBroadcastService::class, $broadcastSpy);

        $actor = User::factory()->create();
        $actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'ORD-REFUND-APPROVE',
            'serial_number' => 'SN-REFUND-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'cashfree_payment_id' => 'cf_refund_approve',
            'payment_amount' => 1000,
            'status' => OrderStatus::Active,
            'created_by' => $actor->id,
        ]);

        $refund = \App\Models\RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'RF-APPROVE-1',
            'amount' => 750,
            'refund_amount' => 750,
            'reason' => 'Test',
            'requester_remarks' => 'Test',
            'customer_preferred_method' => CustomerPreferredRefundMethod::Opm,
            'status' => \App\Enums\RefundStatus::Pending,
            'total_paid_amount' => 1000,
            'already_refunded_amount' => 0,
            'maximum_refundable' => 1000,
            'cancellation_charges' => 0,
            'gst_on_cancellation' => 0,
            'other_deduction' => 0,
            'total_deduction' => 0,
            'deduction_profile_key' => RefundDeductionProfile::FullRefund,
            'communication_channels' => ['email'],
            'requested_by' => $actor->id,
        ]);

        app(RefundRequestService::class)->approve(
            refund: $refund,
            user: $actor,
            data: [
                'approved_refund_method' => ApprovedRefundMethod::Cashfree->value,
                'deduction_profile_key' => 'custom',
                'cancellation_charges' => 0,
                'gst_on_cancellation' => 0,
                'other_deduction' => 0,
                'refund_amount' => 750,
                'partial_difference_reason' => 'partial_refund',
            ],
            request: request(),
        );

        $broadcastSpy->shouldHaveReceived('kpisUpdated')->once();
    }

    public function test_initial_assignment_broadcasts_to_other_operators(): void
    {
        $broadcastSpy = $this->spy(DashboardBroadcastService::class);
        $this->app->instance(DashboardBroadcastService::class, $broadcastSpy);

        $actor = User::factory()->create();
        $actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $assignee = User::factory()->create();
        $assignee->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'ORD-ASSIGN-BROADCAST',
            'serial_number' => 'SN-ASSIGN-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => OrderStatus::Active,
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-ASSIGN-1',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Assign broadcast',
            'description' => 'Assign broadcast',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
        ]);

        app(ServiceCaseAssignmentService::class)->applySupportAssignment(
            incident: $incident,
            assignee: $assignee,
            actor: $actor,
            event: 'service_case.assigned',
        );

        $broadcastSpy->shouldHaveReceived('serviceCaseAssigned')->once();
    }

    public function test_resolved_status_broadcasts_service_case_resolved(): void
    {
        $broadcastSpy = $this->spy(DashboardBroadcastService::class);
        $this->app->instance(DashboardBroadcastService::class, $broadcastSpy);

        $actor = User::factory()->create();
        $actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'ORD-RESOLVED-BROADCAST',
            'serial_number' => 'SN-RESOLVED-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => OrderStatus::Active,
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-RESOLVED-1',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Resolved broadcast',
            'description' => 'Resolved broadcast',
            'status' => IncidentStatus::InProgress,
            'created_by' => $actor->id,
        ]);

        app(ServiceCaseStatusService::class)->updateStatus(
            incident: $incident,
            status: IncidentStatus::Resolved,
            actor: $actor,
        );

        $broadcastSpy->shouldHaveReceived('serviceCaseResolved')->once();
    }

    public function test_reopened_status_broadcasts_queue_membership_change(): void
    {
        $broadcastSpy = $this->spy(DashboardBroadcastService::class);
        $this->app->instance(DashboardBroadcastService::class, $broadcastSpy);

        $actor = User::factory()->create();
        $actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'ORD-REOPEN-BROADCAST',
            'serial_number' => 'SN-REOPEN-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => OrderStatus::Active,
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-REOPEN-1',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Reopen broadcast',
            'description' => 'Reopen broadcast',
            'status' => IncidentStatus::Closed,
            'created_by' => $actor->id,
        ]);

        app(ServiceCaseStatusService::class)->reopen($incident, $actor);

        $broadcastSpy->shouldHaveReceived('serviceCaseQueueMembershipChanged')->once();
    }
}

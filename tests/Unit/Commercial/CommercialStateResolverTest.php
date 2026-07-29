<?php

namespace Tests\Unit\Commercial;

use App\Enums\CommercialAction;
use App\Enums\CommercialState;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RefundStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\Commercial\CommercialStateResolver;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommercialStateResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['commercial_state.enabled' => true]);
    }

    public function test_open_allows_all_commercial_actions(): void
    {
        [$incident] = $this->createIncident();

        $snapshot = app(CommercialStateResolver::class)->forIncident($incident);

        $this->assertSame(CommercialState::Open, $snapshot->state);
        $this->assertFalse($snapshot->showBanner);
        $this->assertSame([], $snapshot->blockedActions);

        foreach (CommercialAction::cases() as $action) {
            $this->assertFalse($snapshot->blocks($action), $action->value);
        }
    }

    public function test_case_closed_shows_banner_and_allows_reopen_without_blocking_commercial_actions(): void
    {
        [$incident] = $this->createIncident(status: IncidentStatus::Closed);

        $snapshot = app(CommercialStateResolver::class)->forIncident($incident);

        $this->assertSame(CommercialState::CaseClosed, $snapshot->state);
        $this->assertTrue($snapshot->showBanner);
        $this->assertTrue($snapshot->allowsReopen);
        $this->assertTrue($snapshot->timelineIsHistorical);
        $this->assertSame([], $snapshot->blockedActions);
    }

    public function test_case_closed_closed_by_uses_status_changed_audit_before_assignee_fallback(): void
    {
        [$incident, $order, $assignee, $closer] = $this->createIncidentWithUsers();

        $incident->update([
            'status' => IncidentStatus::Closed->value,
            'assigned_to_user_id' => $assignee->id,
        ]);

        AuditLog::query()->create([
            'user_id' => $closer->id,
            'event' => 'service_case.status_changed',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'old_values' => ['status' => IncidentStatus::Open->value],
            'new_values' => ['status' => IncidentStatus::Closed->value],
        ]);

        $snapshot = app(CommercialStateResolver::class)->forIncident($incident->fresh(['assignee', 'closeOutcomes.closer']));

        $closedBy = collect($snapshot->details)->firstWhere('label', 'Closed By');

        $this->assertNotNull($closedBy);
        $this->assertSame($closer->name, $closedBy['value']);
        $this->assertNotSame($assignee->name, $closedBy['value']);
    }

    public function test_case_closed_closed_by_falls_back_to_assignee_when_no_outcome_or_audit(): void
    {
        [$incident, , $assignee] = $this->createIncidentWithUsers();

        $incident->update([
            'status' => IncidentStatus::Closed->value,
            'assigned_to_user_id' => $assignee->id,
        ]);

        $snapshot = app(CommercialStateResolver::class)->forIncident($incident->fresh(['assignee', 'closeOutcomes.closer']));

        $closedBy = collect($snapshot->details)->firstWhere('label', 'Closed By');

        $this->assertNotNull($closedBy);
        $this->assertSame($assignee->name, $closedBy['value']);
    }

    public function test_refund_initiated_blocks_assign_paid_service_and_paid_appointment_only(): void
    {
        [$incident, $order, $agent] = $this->createIncident();
        $this->createRefund($order, $incident, $agent, RefundStatus::Pending);

        $snapshot = app(CommercialStateResolver::class)->forIncident($incident->fresh());

        $this->assertSame(CommercialState::RefundInitiated, $snapshot->state);
        $this->assertTrue($snapshot->blocks(CommercialAction::AssignServiceReference));
        $this->assertTrue($snapshot->blocks(CommercialAction::PaidService));
        $this->assertTrue($snapshot->blocks(CommercialAction::PaidAppointment));
        $this->assertFalse($snapshot->blocks(CommercialAction::ChargeCustomer));
        $this->assertSame('Refund pending', $snapshot->dashboardBadgeLabel);
    }

    public function test_refund_completed_blocks_all_commercial_actions_including_charge_customer(): void
    {
        [$incident, $order, $agent] = $this->createIncident();
        $this->createRefund($order, $incident, $agent, RefundStatus::Completed);

        $snapshot = app(CommercialStateResolver::class)->forIncident($incident->fresh());

        $this->assertSame(CommercialState::RefundCompleted, $snapshot->state);
        $this->assertTrue($snapshot->blocks(CommercialAction::AssignServiceReference));
        $this->assertTrue($snapshot->blocks(CommercialAction::PaidService));
        $this->assertTrue($snapshot->blocks(CommercialAction::PaidAppointment));
        $this->assertTrue($snapshot->blocks(CommercialAction::ChargeCustomer));
        $this->assertSame('Refunded', $snapshot->dashboardBadgeLabel);
    }

    public function test_refund_completed_outranks_case_closed(): void
    {
        [$incident, $order, $agent] = $this->createIncident(status: IncidentStatus::Closed);
        $this->createRefund($order, $incident, $agent, RefundStatus::Completed);

        $snapshot = app(CommercialStateResolver::class)->forIncident($incident->fresh());

        $this->assertSame(CommercialState::RefundCompleted, $snapshot->state);
    }

    public function test_refund_initiated_outranks_case_closed(): void
    {
        [$incident, $order, $agent] = $this->createIncident(status: IncidentStatus::Closed);
        $this->createRefund($order, $incident, $agent, RefundStatus::PendingExecution);

        $snapshot = app(CommercialStateResolver::class)->forIncident($incident->fresh());

        $this->assertSame(CommercialState::RefundInitiated, $snapshot->state);
    }

    /**
     * @return array{0: Incident, 1: Order, 2: User}
     */
    private function createIncident(IncidentStatus $status = IncidentStatus::Open): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-CS-'.uniqid(),
            'serial_number' => 'SN-CS-'.uniqid(),
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Internal->value,
            'title' => 'Commercial state fixture',
            'description' => 'Commercial state fixture description.',
            'status' => $status->value,
            'created_by' => $agent->id,
        ]);

        return [$incident, $order, $agent];
    }

    /**
     * @return array{0: Incident, 1: Order, 2: User, 3: User}
     */
    private function createIncidentWithUsers(): array
    {
        $assignee = User::factory()->create(['name' => 'Assignee Agent']);
        $assignee->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $closer = User::factory()->create(['name' => 'Closer Agent']);
        $closer->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-CS-'.uniqid(),
            'serial_number' => 'SN-CS-'.uniqid(),
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Internal->value,
            'title' => 'Commercial state fixture',
            'description' => 'Commercial state fixture description.',
            'status' => IncidentStatus::Open->value,
            'created_by' => $creator->id,
        ]);

        return [$incident, $order, $assignee, $closer];
    }

    private function createRefund(
        Order $order,
        Incident $incident,
        User $agent,
        RefundStatus $status,
    ): RefundRequest {
        return RefundRequest::query()->create([
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'reference_no' => 'REF-CS-'.uniqid(),
            'amount' => 1500,
            'reason' => 'Customer requested refund for commercial state test.',
            'status' => $status,
            'requested_by' => $agent->id,
        ]);
    }
}

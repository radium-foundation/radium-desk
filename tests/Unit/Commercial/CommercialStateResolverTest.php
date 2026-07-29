<?php

namespace Tests\Unit\Commercial;

use App\Enums\CommercialAction;
use App\Enums\CommercialState;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RefundStatus;
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

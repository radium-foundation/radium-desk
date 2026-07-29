<?php

namespace Tests\Feature\Commercial;

use App\Enums\CommercialAction;
use App\Enums\CommercialState;
use App\Enums\CommunicationActionKey;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RefundStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\Commercial\CommercialStateResolver;
use App\Services\CommunicationActions\CommunicationActionEligibilityService;
use App\Services\CommunicationActions\CommunicationActionRegistry;
use App\Services\Customer360Service;
use App\Services\IncidentReferenceService;
use App\Services\OrderTransactionService;
use App\Services\ServiceCaseReopenService;
use App\Services\WorkspaceActionDialogService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * BR-04 golden checks: commercial posture resolution + enforcement.
 */
class CommercialStateGoldenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['commercial_state.enabled' => true]);
    }

    public function test_open_allows_commercial_actions_and_hides_banner(): void
    {
        [$agent, $incident] = $this->createFixture();

        $this->actingAs($agent);

        $drawer = app(Customer360Service::class)->drawerData($incident);
        $commercial = $drawer['commercialState'];

        $this->assertIsArray($commercial);
        $this->assertSame(CommercialState::Open->value, $commercial['state']);
        $this->assertFalse($commercial['show_banner']);
        $this->assertTrue($commercial['allows_commercial_work']);
        $this->assertSame([], $commercial['blocked_actions']);

        $resolver = app(CommercialStateResolver::class);
        foreach (CommercialAction::cases() as $action) {
            $this->assertFalse($resolver->blocks($incident, $action), $action->value);
            $this->assertNull($resolver->ineligibilityReason($incident, $action));
        }

        $response = $this->get(route('dashboard.service-cases.customer-360', $incident));
        $response->assertOk();
        $this->assertStringNotContainsString('data-customer-360-section="commercial-state"', $response->getContent());
    }

    public function test_case_closed_shows_green_banner_and_still_allows_reopen(): void
    {
        [$agent, $incident] = $this->createFixture(status: IncidentStatus::Closed);

        $this->actingAs($agent);

        $drawer = app(Customer360Service::class)->drawerData($incident);
        $commercial = $drawer['commercialState'];

        $this->assertSame(CommercialState::CaseClosed->value, $commercial['state']);
        $this->assertTrue($commercial['show_banner']);
        $this->assertTrue($commercial['allows_reopen']);
        $this->assertSame('success', $commercial['banner_variant']);

        $capabilities = app(WorkspaceActionDialogService::class)->capabilities($incident, $agent);
        $this->assertTrue($capabilities['reopen']);

        $html = $this->get(route('dashboard.service-cases.customer-360', $incident))->assertOk()->getContent();
        $this->assertStringContainsString('data-customer-360-section="commercial-state"', $html);
        $this->assertStringContainsString('data-commercial-state="case_closed"', $html);
        $this->assertStringContainsString('Case Closed', $html);

        $reopened = app(ServiceCaseReopenService::class)->reopen(
            $incident,
            $agent,
            'Reopening after commercial closed banner check.',
        );

        $this->assertSame(IncidentStatus::Open, $reopened->status);
        $this->assertSame(
            CommercialState::Open,
            app(CommercialStateResolver::class)->forIncident($reopened->fresh())->state,
        );
    }

    public function test_refund_initiated_blocks_commercial_actions_and_shows_amber_banner(): void
    {
        [$agent, $incident, $order] = $this->createFixture();
        $this->createRefund($order, $incident, $agent, RefundStatus::Pending);

        $this->actingAs($agent);
        $incident = $incident->fresh();

        $resolver = app(CommercialStateResolver::class);
        $this->assertTrue($resolver->blocks($incident, CommercialAction::AssignServiceReference));
        $this->assertTrue($resolver->blocks($incident, CommercialAction::PaidService));
        $this->assertTrue($resolver->blocks($incident, CommercialAction::PaidAppointment));
        $this->assertFalse($resolver->blocks($incident, CommercialAction::ChargeCustomer));

        $eligibility = app(CommunicationActionEligibilityService::class);
        $registry = app(CommunicationActionRegistry::class);

        $paidServiceReason = $eligibility->ineligibilityReason(
            $registry->get(CommunicationActionKey::BuyRdService),
            $incident,
            $agent,
        );
        $this->assertNotNull($paidServiceReason);
        $this->assertStringContainsString('Commercial', $paidServiceReason);

        $chargeReason = $eligibility->ineligibilityReason(
            $registry->get(CommunicationActionKey::BuyProduct),
            $incident,
            $agent,
        );
        $this->assertNull($resolver->ineligibilityReason($incident, CommercialAction::ChargeCustomer));
        $this->assertTrue(
            $chargeReason === null || ! str_contains($chargeReason, 'Commercial'),
            'Charge Customer must not be commercially blocked while refund is only initiated.',
        );

        try {
            app(OrderTransactionService::class)->assignTransactionId($order, 'TXN-BLOCKED-INIT', $agent, broadcast: false);
            $this->fail('Expected ValidationException for refund-initiated assign.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('transaction_id', $exception->errors());
        }

        $html = $this->get(route('dashboard.service-cases.customer-360', $incident))->assertOk()->getContent();
        $this->assertStringContainsString('data-commercial-state="refund_initiated"', $html);
        $this->assertStringContainsString('Refund Requested', $html);
        $this->assertStringContainsString('c360-commercial-state--warning', $html);
    }

    public function test_refund_completed_blocks_all_commercial_actions_and_shows_red_banner(): void
    {
        [$agent, $incident, $order] = $this->createFixture();
        $this->createRefund($order, $incident, $agent, RefundStatus::Completed);

        $this->actingAs($agent);
        $incident = $incident->fresh();

        $resolver = app(CommercialStateResolver::class);
        foreach ([
            CommercialAction::AssignServiceReference,
            CommercialAction::PaidService,
            CommercialAction::PaidAppointment,
            CommercialAction::ChargeCustomer,
        ] as $action) {
            $this->assertTrue($resolver->blocks($incident, $action), $action->value);
        }

        $eligibility = app(CommunicationActionEligibilityService::class);
        $registry = app(CommunicationActionRegistry::class);

        foreach ([CommunicationActionKey::BuyRdService, CommunicationActionKey::BuyProduct] as $key) {
            $reason = $eligibility->ineligibilityReason($registry->get($key), $incident, $agent);
            $this->assertNotNull($reason);
            $this->assertStringContainsString('Commercial', $reason);
        }

        try {
            app(OrderTransactionService::class)->assignTransactionId($order, 'TXN-BLOCKED-DONE', $agent, broadcast: false);
            $this->fail('Expected ValidationException for refund-completed assign.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('transaction_id', $exception->errors());
        }

        $html = $this->get(route('dashboard.service-cases.customer-360', $incident))->assertOk()->getContent();
        $this->assertStringContainsString('data-commercial-state="refund_completed"', $html);
        $this->assertStringContainsString('Refund Completed', $html);
        $this->assertStringContainsString('Commercially Closed', $html);
        $this->assertStringContainsString('c360-commercial-state--danger', $html);
    }

    public function test_dashboard_resolved_label_and_refund_badge(): void
    {
        [$agent, $incident, $order] = $this->createFixture();
        $incident->forceFill(['created_at' => now()->subHour()])->saveQuietly();
        $order->update([
            'transaction_id' => 'TXN-RESOLVED',
            'completed_at' => now()->subMinutes(23),
            'transaction_assigned_by' => $agent->id,
        ]);
        $this->createRefund($order, $incident, $agent, RefundStatus::PendingExecution);

        $this->actingAs($agent);

        $rowHtml = view('dashboard.partials.service-case-row', app(\App\Services\DashboardService::class)->serviceCaseRowViewData(
            $incident->fresh(['order.transactionAssigner', 'order.refundRequests', 'refundRequests', 'creator', 'assignee']),
            $agent,
        ))->render();

        $this->assertStringContainsString('Resolved in', $rowHtml);
        $this->assertStringContainsString('dashboard-commercial-badge--refund_initiated', $rowHtml);
        $this->assertStringContainsString('Refund pending', $rowHtml);
        $this->assertStringNotContainsString('dashboard-status-sla-compact__icon', $rowHtml);
    }

    /**
     * @return array{0: User, 1: Incident, 2: Order}
     */
    private function createFixture(IncidentStatus $status = IncidentStatus::Open): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-CSG-'.uniqid(),
            'serial_number' => 'SN-CSG-'.uniqid(),
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'customer_name' => 'Commercial Customer',
            'customer_email' => 'commercial@example.com',
            'customer_phone' => '9000000001',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Internal->value,
            'title' => 'Commercial golden fixture',
            'description' => 'Commercial golden fixture description.',
            'status' => $status->value,
            'created_by' => $agent->id,
            'assigned_to' => $agent->id,
        ]);

        return [$agent, $incident, $order];
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
            'reference_no' => 'REF-CSG-'.uniqid(),
            'amount' => 2500,
            'reason' => 'Golden commercial state refund fixture.',
            'status' => $status,
            'requested_by' => $agent->id,
        ]);
    }
}

<?php

namespace Tests\Feature\PreShipmentCancellation;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareAwaitingFulfilmentReason;
use App\Enums\HardwareFulfilmentState;
use App\Enums\IncidentStatus;
use App\Enums\PreShipmentCancellationStatus;
use App\Enums\PreShipmentEligibilityFailureCode;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\Incident;
use App\Models\InventoryBranch;
use App\Models\Order;
use App\Models\PreShipmentCancellationRequest;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\HardwareFulfilment\Data\HardwareAwaitingFulfilmentClassifier;
use App\Services\HardwareFulfilment\HardwareFulfilmentCancellationGuard;
use App\Services\PreShipmentCancellation\PreShipmentCancellationEligibilityService;
use App\Services\PreShipmentCancellation\PreShipmentCancellationFeature;
use App\Services\PreShipmentCancellation\PreShipmentCancellationFinanceService;
use App\Services\PreShipmentCancellation\PreShipmentCancellationIncidentService;
use App\Services\PreShipmentCancellation\PreShipmentCancellationRefundLinkService;
use App\Services\PreShipmentCancellation\PreShipmentCancellationSubmitService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PreShipmentCancellationPhase2AbTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
        config(['pre_shipment_cancellation.enabled' => true]);
    }

    /**
     * @return array{0: User, 1: Order, 2: CommerceOrder, 3: ?HardwareFulfilment}
     */
    private function paidHardwareFixtures(
        string $orderId = 'RBP9001001',
        ?HardwareFulfilmentState $hfState = null,
        float $orderValue = 2500,
        float $walletTender = 0,
    ): array {
        $actor = User::factory()->create();
        $actor->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => null,
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'status' => 'active',
            'payment_amount' => $orderValue - $walletTender,
            'cashfree_payment_id' => 'cf_test_'.$orderId,
            'created_by' => $actor->id,
        ]);

        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$orderId,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $orderId,
            'idempotency_key' => 'psc-'.$order->id,
            'payload_hash' => hash('sha256', $orderId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'order_value' => $orderValue,
            'wallet_tender_amount' => $walletTender > 0 ? $walletTender : null,
            'received_at' => now(),
            'support_order_id' => $order->id,
        ]);

        $fulfilment = null;
        if ($hfState !== null) {
            $branch = InventoryBranch::query()->create([
                'code' => 'BR-PSC-'.random_int(100, 999),
                'name' => 'PSC Branch',
                'is_active' => true,
            ]);
            $fulfilment = HardwareFulfilment::query()->create([
                'commerce_order_id' => $commerce->id,
                'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
                'source_type' => 'commerce_order',
                'source_id' => $orderId,
                'idempotency_key' => $commerce->idempotency_key,
                'state' => $hfState,
                'support_order_id' => $order->id,
                'fulfilment_branch_id' => $branch->id,
                'ingested_at' => now(),
            ]);
        }

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'INC-PSC-'.random_int(1000, 9999),
            'category' => 'Hardware',
            'source' => 'internal',
            'title' => 'Hardware fulfilment',
            'description' => 'Awaiting serial',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
        ]);

        return [$actor, $order, $commerce, $fulfilment];
    }

    public function test_feature_flag_defaults_off(): void
    {
        config(['pre_shipment_cancellation.enabled' => false]);
        $this->assertFalse(app(PreShipmentCancellationFeature::class)->enabled());
    }

    public function test_state_a_submit_blocks_ensure_ingested(): void
    {
        [$actor, $order, $commerce] = array_slice($this->paidHardwareFixtures(), 0, 3);
        $service = app(PreShipmentCancellationSubmitService::class);

        $case = $service->submit($actor, $order, [
            'cancellation_reason' => 'Customer requested cancel before shipment.',
            'idempotency_key' => 'psc-submit-'.$order->id,
        ]);

        $this->assertNotNull($case->committed_at);
        $this->assertNotNull($commerce->fresh()->hardware_fulfilment_blocked_at);

        $this->expectException(ValidationException::class);
        HardwareFulfilmentCancellationGuard::assertFulfilmentNotBlocked($commerce->fresh());

        $this->assertSame(0, HardwareFulfilment::query()->count());
    }

    public function test_state_b_transitions_hf_to_cancelled_pre_shipment(): void
    {
        [$actor, $order, $commerce, $fulfilment] = $this->paidHardwareFixtures(
            hfState: HardwareFulfilmentState::ReadyForFulfilment,
        );

        $case = app(PreShipmentCancellationSubmitService::class)->submit($actor, $order, [
            'cancellation_reason' => 'Cancel before allocation.',
            'idempotency_key' => 'psc-b-'.$order->id,
        ]);

        $this->assertSame(HardwareFulfilmentState::CancelledPreShipment, $fulfilment->fresh()->state);
        $this->assertSame($fulfilment->id, $case->hardware_fulfilment_id);
    }

    public function test_state_c_rejected_when_serials_allocated(): void
    {
        [$actor, $order] = $this->paidHardwareFixtures(hfState: HardwareFulfilmentState::SerialsAllocated);
        $assessment = app(PreShipmentCancellationEligibilityService::class)->assess($order);

        $this->assertFalse($assessment['eligible']);
        $this->assertSame(PreShipmentEligibilityFailureCode::FulfilmentBeyondScope, $assessment['code']);
    }

    public function test_state_d_rejected_when_awb_present(): void
    {
        [$actor, $order, $commerce, $fulfilment] = $this->paidHardwareFixtures(
            hfState: HardwareFulfilmentState::Ingested,
        );
        $fulfilment->forceFill(['awb' => 'AWB12345'])->save();

        $assessment = app(PreShipmentCancellationEligibilityService::class)->assess($order);
        $this->assertFalse($assessment['eligible']);
        $this->assertSame(PreShipmentEligibilityFailureCode::ShipmentOrAwb, $assessment['code']);
    }

    public function test_classifier_marks_blocked_commerce(): void
    {
        [$actor, $order, $commerce] = $this->paidHardwareFixtures();
        $commerce->forceFill(['hardware_fulfilment_blocked_at' => now()])->save();

        $reason = HardwareAwaitingFulfilmentClassifier::reason($order, $commerce->fresh());
        $this->assertSame(HardwareAwaitingFulfilmentReason::PreShipmentCancelled, $reason);
    }

    public function test_incident_closes_via_canonical_status_service(): void
    {
        [$actor, $order] = $this->paidHardwareFixtures();
        $case = app(PreShipmentCancellationSubmitService::class)->submit($actor, $order, [
            'cancellation_reason' => 'Close incident on cancel.',
            'idempotency_key' => 'psc-inc-'.$order->id,
        ]);

        $incident = Incident::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
        $this->assertNotNull($case->service_case_incident_id);
    }

    public function test_split_tender_routes_to_finance_review(): void
    {
        [$actor, $order] = $this->paidHardwareFixtures(
            orderId: 'RDE9002002',
            walletTender: 500,
            orderValue: 2500,
        );

        $case = app(PreShipmentCancellationSubmitService::class)->submit($actor, $order, [
            'cancellation_reason' => 'Split tender manual finance.',
            'idempotency_key' => 'psc-split-'.$order->id,
        ]);

        $this->assertTrue($case->split_tender);
        $this->assertSame(PreShipmentCancellationStatus::FinanceReview, $case->status);
    }

    public function test_refund_link_uses_narrow_gate_bypass(): void
    {
        [$actor, $order] = $this->paidHardwareFixtures(orderId: 'RBP9003003');
        $case = app(PreShipmentCancellationSubmitService::class)->submit($actor, $order, [
            'cancellation_reason' => 'Refund link path.',
            'idempotency_key' => 'psc-rr-'.$order->id,
        ]);

        $refund = app(PreShipmentCancellationRefundLinkService::class)
            ->approveAndLink($actor, $case, Request::create('/'));

        $this->assertSame(1, RefundRequest::query()->count());
        $this->assertSame($case->fresh()->refund_request_id, $refund->id);
        $this->assertSame(PreShipmentCancellationStatus::Completed, $case->fresh()->status);
    }

    public function test_duplicate_idempotency_key_is_idempotent(): void
    {
        [$actor, $order] = $this->paidHardwareFixtures(orderId: 'RDP9004004');
        $service = app(PreShipmentCancellationSubmitService::class);
        $key = 'psc-dup-'.$order->id;

        $first = $service->submit($actor, $order, [
            'cancellation_reason' => 'Dup test',
            'idempotency_key' => $key,
        ]);
        $second = $service->submit($actor, $order, [
            'cancellation_reason' => 'Dup test',
            'idempotency_key' => $key,
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PreShipmentCancellationRequest::query()->count());
    }

    public function test_flag_off_blocks_submit(): void
    {
        config(['pre_shipment_cancellation.enabled' => false]);
        [$actor, $order] = $this->paidHardwareFixtures(orderId: 'RBP9005005');

        $this->expectException(ValidationException::class);
        app(PreShipmentCancellationSubmitService::class)->submit($actor, $order, [
            'cancellation_reason' => 'Should fail',
            'idempotency_key' => 'psc-off-'.$order->id,
        ]);
    }

    public function test_incident_close_retry_is_idempotent(): void
    {
        [$actor, $order] = $this->paidHardwareFixtures(orderId: 'RBP9007007');
        $case = app(PreShipmentCancellationSubmitService::class)->submit($actor, $order, [
            'cancellation_reason' => 'Retry incident sync.',
            'idempotency_key' => 'psc-retry-inc-'.$order->id,
        ]);

        $retried = app(PreShipmentCancellationIncidentService::class)->retryClose($case, $actor);
        $this->assertSame($case->service_case_incident_id, $retried->service_case_incident_id);
        $this->assertSame(1, PreShipmentCancellationRequest::query()->count());
    }

    public function test_finance_approve_moves_split_case_from_review(): void
    {
        [$actor, $order] = $this->paidHardwareFixtures(
            orderId: 'RBP9006006',
            walletTender: 400,
        );

        $case = app(PreShipmentCancellationSubmitService::class)->submit($actor, $order, [
            'cancellation_reason' => 'Finance approve split.',
            'idempotency_key' => 'psc-fin-'.$order->id,
        ]);

        $approved = app(PreShipmentCancellationFinanceService::class)->approve($actor, $case);
        $this->assertSame(PreShipmentCancellationStatus::FinanceApproved, $approved->status);
    }
}

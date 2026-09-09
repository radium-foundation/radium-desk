<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareDashboardQueue;
use App\Enums\HardwareFulfilmentOperationalStage;
use App\Enums\HardwareFulfilmentState;
use App\Enums\HardwareOperationsSection;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\HardwareFulfilment;
use App\Models\Order;
use App\Models\User;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentOperationalClassifier;
use App\Services\HardwareFulfilment\Data\HardwareShipmentReadiness;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\HardwareFulfilment\HardwareShipmentEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HardwareFulfilmentOperationalClassifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_awaiting_review_candidate_and_hold_orders(): void
    {
        $classifier = app(HardwareFulfilmentOperationalClassifier::class);
        $review = $this->order('RDE971001', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-07 10:00:00',
        ]);
        $hold = $this->order('RDE255714', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-07 10:00:00',
        ]);

        $reviewRow = $classifier->fromAwaiting($review);
        $holdRow = $classifier->fromAwaiting($hold);

        $this->assertSame(HardwareFulfilmentOperationalStage::AwaitingFulfilment, $reviewRow->stage);
        $this->assertSame('Review', $reviewRow->nextAction);
        $this->assertSame('MFS 110', $reviewRow->productDisplay());
        $this->assertFalse($reviewRow->productMissing);
        $this->assertNotSame('—', $reviewRow->productDisplay());
        $this->assertSame(HardwareOperationsSection::NeedsFulfilment, $reviewRow->section);
        $this->assertFalse($reviewRow->hasFulfilment);
        $this->assertSame(route('dashboard.orders.customer-360', $review), $reviewRow->nextUrl);
        $this->assertSame(HardwareFulfilmentOperationalStage::BlockedReview, $holdRow->stage);
        $this->assertSame('View', $holdRow->nextAction);
        $this->assertSame('Blocked', $holdRow->operatorStatus());
        $this->assertSame(HardwareOperationsSection::Exceptions, $holdRow->section);
    }

    public function test_windowed_rin_is_an_exception_without_mutating_action(): void
    {
        $rin = $this->order('RIN971099', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-07 10:00:00',
        ]);
        $row = app(HardwareFulfilmentOperationalClassifier::class)->fromRin($rin);

        $this->assertSame('RIN', $row->source);
        $this->assertSame(HardwareOperationsSection::Exceptions, $row->section);
        $this->assertSame('View', $row->nextAction);
        $this->assertSame('Blocked', $row->operatorStatus());
        $this->assertFalse($row->mutatingAction);
        $this->assertFalse($row->hasFulfilment);
        $this->assertSame(route('dashboard.orders.customer-360', $rin), $row->nextUrl);
    }

    public function test_existing_fulfilment_without_serial_is_awaiting_serial(): void
    {
        $fulfilment = $this->fulfilment('RDE971002', HardwareFulfilmentState::ReadyForFulfilment);
        $ready = app(HardwareShipmentEligibility::class)->inspect($fulfilment);
        $row = app(HardwareFulfilmentOperationalClassifier::class)->fromFulfilment($fulfilment, $ready);

        $this->assertSame(HardwareFulfilmentOperationalStage::AwaitingSerial, $row->stage);
        $this->assertSame('Allocate Serial', $row->nextAction);
        $this->assertTrue($row->hasFulfilment);
        $this->assertNotNull($row->fulfilmentUrl());
    }

    public function test_ingested_eligible_fulfilment_offers_ready_not_allocate_serial(): void
    {
        $fulfilment = $this->fulfilment('RDE971030', HardwareFulfilmentState::Ingested, eligible: true);
        $ready = app(HardwareShipmentEligibility::class)->inspect($fulfilment);
        $row = app(HardwareFulfilmentOperationalClassifier::class)->fromFulfilment($fulfilment, $ready);

        $this->assertSame(HardwareFulfilmentOperationalStage::AwaitingFulfilment, $row->stage);
        $this->assertSame('Ready for Fulfilment', $row->nextAction);
        $this->assertNotSame('Allocate Serial', $row->nextAction);
        $this->assertTrue($row->mutatingAction);
        $this->assertSame('hardware-mark-ready', $row->nextAnchor);
    }

    public function test_ingested_blocked_fulfilment_shows_blocker_not_allocate_serial(): void
    {
        $fulfilment = $this->fulfilment('RDE971031', HardwareFulfilmentState::Ingested, eligible: false);
        $ready = app(HardwareShipmentEligibility::class)->inspect($fulfilment);
        $row = app(HardwareFulfilmentOperationalClassifier::class)->fromFulfilment($fulfilment, $ready);

        $this->assertSame('View', $row->nextAction);
        $this->assertNotSame('Allocate Serial', $row->nextAction);
        $this->assertFalse($row->mutatingAction);
        $this->assertNotNull($row->blocker);
        $this->assertSame(HardwareFulfilmentOperationalStage::BlockedReview, $row->stage);
    }

    public function test_label_without_package_photo_requests_pickup(): void
    {
        $fulfilment = $this->fulfilment('RDE971003', HardwareFulfilmentState::AwbAssigned);
        $ready = $this->readiness([
            'alreadyCreated' => true,
            'invoice' => 'INV-1',
            'serials' => ['10532319'],
            'awb' => 'AWB1',
            'labelUrl' => '/label.pdf',
            'pickupStatus' => 'Not requested',
        ]);
        $row = app(HardwareFulfilmentOperationalClassifier::class)->fromFulfilment($fulfilment, $ready);

        $this->assertSame('Request Pickup', $row->nextAction);
        $this->assertSame(HardwareDashboardQueue::Pickup, $row->dashboardQueue());
        $this->assertFalse($row->packagePhotoRecorded);
        $this->assertNotSame('Record Packing', $row->nextAction);
    }

    public function test_shipped_without_package_photo_stays_open_for_evidence(): void
    {
        $fulfilment = $this->fulfilment('RDE971004', HardwareFulfilmentState::Shipped);
        $ready = $this->readiness([
            'alreadyCreated' => true,
            'invoice' => 'INV-1',
            'serials' => ['10532319'],
            'awb' => 'AWB1',
            'labelUrl' => '/label.pdf',
            'pickupStatus' => 'Requested',
            'manifestStatus' => 'Available',
        ]);
        $row = app(HardwareFulfilmentOperationalClassifier::class)->fromFulfilment($fulfilment, $ready);

        $this->assertSame('Upload Package Photo', $row->nextAction);
        $this->assertSame('Ready / Open evidence', $row->operatorStatus());
        $this->assertSame(HardwareDashboardQueue::Ready, $row->dashboardQueue());
        $this->assertTrue($row->mutatingAction);
    }

    public function test_valid_selected_courier_advances_to_create_shipment_even_when_options_remain(): void
    {
        $fulfilment = $this->fulfilment('RDE971010', HardwareFulfilmentState::InvoiceIssued);
        $ready = $this->readiness([
            'alreadyCreated' => false,
            'canCreate' => true,
            'canSelectCourier' => true,
            'canFetchCourierOptions' => true,
            'selectedCourierId' => '15084',
            'selectedCourierName' => 'Delhivery_Surface',
            'courierOptions' => [['courier_id' => '15084', 'courier_name' => 'Delhivery_Surface']],
            'actionLabel' => 'Create Shipment',
            'awb' => null,
            'labelUrl' => null,
        ]);
        $row = app(HardwareFulfilmentOperationalClassifier::class)->fromFulfilment($fulfilment, $ready);

        $this->assertTrue($ready->canCreate);
        $this->assertSame('Create Shipment', $row->nextAction);
        $this->assertSame('hardware-shipment-create', $row->nextAnchor);
        $this->assertTrue($row->mutatingAction);
        $this->assertNotSame('Select Courier', $row->nextAction);
    }

    public function test_options_without_selection_still_require_select_courier(): void
    {
        $fulfilment = $this->fulfilment('RDE971011', HardwareFulfilmentState::InvoiceIssued);
        $ready = $this->readiness([
            'alreadyCreated' => false,
            'canCreate' => false,
            'canSelectCourier' => true,
            'canFetchCourierOptions' => true,
            'selectedCourierId' => null,
            'courierOptions' => [['courier_id' => '15084']],
            'actionLabel' => 'Create Shipment',
            'awb' => null,
            'labelUrl' => null,
        ]);
        $row = app(HardwareFulfilmentOperationalClassifier::class)->fromFulfilment($fulfilment, $ready);

        $this->assertSame('Select Courier', $row->nextAction);
        $this->assertSame('hardware-courier', $row->nextAnchor);
    }

    public function test_measured_parcel_is_required_before_courier_options(): void
    {
        $fulfilment = $this->fulfilment('RDE971015', HardwareFulfilmentState::InvoiceIssued);
        $ready = $this->readiness([
            'alreadyCreated' => false,
            'canCreate' => false,
            'canSelectCourier' => false,
            'canFetchCourierOptions' => false,
            'canAttachMeasuredParcel' => true,
            'selectedCourierId' => null,
            'actionLabel' => 'Create Shipment',
            'awb' => null,
            'labelUrl' => null,
        ]);
        $row = app(HardwareFulfilmentOperationalClassifier::class)->fromFulfilment($fulfilment, $ready);

        $this->assertSame('Enter Package Dimensions', $row->nextAction);
        $this->assertSame('hardware-parcel-measure', $row->nextAnchor);
    }

    public function test_no_options_and_no_selection_asks_for_courier_options(): void
    {
        $fulfilment = $this->fulfilment('RDE971012', HardwareFulfilmentState::InvoiceIssued);
        $ready = $this->readiness([
            'alreadyCreated' => false,
            'canCreate' => false,
            'canSelectCourier' => false,
            'canFetchCourierOptions' => true,
            'selectedCourierId' => null,
            'courierOptions' => [],
            'actionLabel' => 'Create Shipment',
            'awb' => null,
            'labelUrl' => null,
        ]);
        $row = app(HardwareFulfilmentOperationalClassifier::class)->fromFulfilment($fulfilment, $ready);

        $this->assertSame('Get Courier Options', $row->nextAction);
        $this->assertSame('hardware-courier', $row->nextAnchor);
    }

    public function test_recoverable_provider_rejection_with_valid_courier_offers_create_shipment(): void
    {
        $fulfilment = $this->fulfilment('RDE971020', HardwareFulfilmentState::InvoiceIssued);
        $ready = $this->readiness([
            'alreadyCreated' => false,
            'canCreate' => true,
            'canSelectCourier' => true,
            'canFetchCourierOptions' => true,
            'selectedCourierId' => '15137',
            'selectedCourierName' => 'Bluedart_Surface',
            'courierOptions' => [['courier_id' => '15137', 'courier_name' => 'Bluedart_Surface']],
            'actionLabel' => 'Create Shipment',
            'awb' => null,
            'labelUrl' => null,
            'providerRejection' => 'Shiprocket rejected shipment creation: HTTP 400 — Address 1 and Address 2 combined cannot be greater 190 than characters',
        ]);
        $row = app(HardwareFulfilmentOperationalClassifier::class)->fromFulfilment($fulfilment, $ready);

        $this->assertSame('Create Shipment', $row->nextAction);
        $this->assertSame('Ready for Shipment', $row->operatorStatus());
        $this->assertSame('hardware-shipment-create', $row->nextAnchor);
        $this->assertTrue($row->mutatingAction);
        $this->assertSame(HardwareFulfilmentOperationalStage::ReadyForShipment, $row->stage);
        $this->assertSame(HardwareDashboardQueue::Ready, $row->dashboardQueue());
        $this->assertNotSame('Blocked', $row->operatorStatus());
        $this->assertNotSame('View', $row->nextAction);
    }

    public function test_unrecoverable_provider_rejection_stays_blocked(): void
    {
        $fulfilment = $this->fulfilment('RDE971021', HardwareFulfilmentState::InvoiceIssued);
        $ready = $this->readiness([
            'alreadyCreated' => false,
            'canCreate' => false,
            'canSelectCourier' => false,
            'canFetchCourierOptions' => false,
            'canAttachMeasuredParcel' => false,
            'selectedCourierId' => null,
            'actionLabel' => 'Create Shipment',
            'awb' => null,
            'labelUrl' => null,
            'providerRejection' => 'Shiprocket rejected shipment creation: authentication failed.',
        ]);
        $row = app(HardwareFulfilmentOperationalClassifier::class)->fromFulfilment($fulfilment, $ready);

        $this->assertSame('Blocked', $row->operatorStatus());
        $this->assertSame('View', $row->nextAction);
        $this->assertFalse($row->mutatingAction);
        $this->assertSame(HardwareFulfilmentOperationalStage::BlockedReview, $row->stage);
        $this->assertSame(HardwareOperationsSection::Exceptions, $row->section);
    }

    public function test_provider_rejection_with_expired_courier_asks_for_courier_options(): void
    {
        $fulfilment = $this->fulfilment('RDE971022', HardwareFulfilmentState::InvoiceIssued);
        $ready = $this->readiness([
            'alreadyCreated' => false,
            'canCreate' => false,
            'canSelectCourier' => false,
            'canFetchCourierOptions' => true,
            'selectedCourierId' => null,
            'courierOptions' => [],
            'actionLabel' => 'Create Shipment',
            'awb' => null,
            'labelUrl' => null,
            'providerRejection' => 'Shiprocket rejected shipment creation: HTTP 400',
        ]);
        $row = app(HardwareFulfilmentOperationalClassifier::class)->fromFulfilment($fulfilment, $ready);

        $this->assertSame('Get Courier Options', $row->nextAction);
        $this->assertSame('hardware-courier', $row->nextAnchor);
        $this->assertTrue($row->mutatingAction);
        $this->assertNotSame('Blocked', $row->operatorStatus());
    }

    public function test_provider_rejection_with_options_but_no_selection_asks_for_select_courier(): void
    {
        $fulfilment = $this->fulfilment('RDE971023', HardwareFulfilmentState::InvoiceIssued);
        $ready = $this->readiness([
            'alreadyCreated' => false,
            'canCreate' => false,
            'canSelectCourier' => true,
            'canFetchCourierOptions' => true,
            'selectedCourierId' => null,
            'courierOptions' => [['courier_id' => '15137']],
            'actionLabel' => 'Create Shipment',
            'awb' => null,
            'labelUrl' => null,
            'providerRejection' => 'Shiprocket rejected shipment creation: HTTP 400',
        ]);
        $row = app(HardwareFulfilmentOperationalClassifier::class)->fromFulfilment($fulfilment, $ready);

        $this->assertSame('Select Courier', $row->nextAction);
        $this->assertSame('hardware-courier', $row->nextAnchor);
        $this->assertTrue($row->mutatingAction);
    }

    public function test_frozen_fulfilment_stays_blocked_even_with_can_create(): void
    {
        $fulfilment = $this->fulfilment(
            HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS[0],
            HardwareFulfilmentState::InvoiceIssued,
        );
        $ready = $this->readiness([
            'alreadyCreated' => false,
            'canCreate' => true,
            'canFetchCourierOptions' => true,
            'selectedCourierId' => '15137',
            'actionLabel' => 'Create Shipment',
            'awb' => null,
            'labelUrl' => null,
            'providerRejection' => 'Shiprocket rejected shipment creation: HTTP 400',
        ]);
        $row = app(HardwareFulfilmentOperationalClassifier::class)->fromFulfilment($fulfilment, $ready);

        $this->assertSame('Blocked', $row->operatorStatus());
        $this->assertSame('View', $row->nextAction);
        $this->assertFalse($row->mutatingAction);
        $this->assertSame('Owner-blocked. Do not advance from this queue.', $row->blocker);
    }

    public function test_hold_fulfilment_stays_blocked_even_with_can_create(): void
    {
        $fulfilment = $this->fulfilment(
            HardwareFulfilmentEligibility::HOLD_SOURCE_IDS[0],
            HardwareFulfilmentState::InvoiceIssued,
        );
        $ready = $this->readiness([
            'alreadyCreated' => false,
            'canCreate' => true,
            'selectedCourierId' => '15137',
            'actionLabel' => 'Create Shipment',
            'awb' => null,
            'labelUrl' => null,
        ]);
        $row = app(HardwareFulfilmentOperationalClassifier::class)->fromFulfilment($fulfilment, $ready);

        $this->assertSame('Blocked', $row->operatorStatus());
        $this->assertSame('View', $row->nextAction);
        $this->assertFalse($row->mutatingAction);
    }

    public function test_can_create_reconcile_takes_precedence_over_select_courier(): void
    {
        $fulfilment = $this->fulfilment('RDE971013', HardwareFulfilmentState::InvoiceIssued);
        $ready = $this->readiness([
            'alreadyCreated' => false,
            'canCreate' => true,
            'canSelectCourier' => true,
            'canFetchCourierOptions' => true,
            'selectedCourierId' => '12',
            'actionLabel' => 'Reconcile Shipment',
            'awb' => null,
            'labelUrl' => null,
        ]);
        $row = app(HardwareFulfilmentOperationalClassifier::class)->fromFulfilment($fulfilment, $ready);

        $this->assertSame('Reconcile Shipment', $row->nextAction);
        $this->assertSame('hardware-shipment-create', $row->nextAnchor);
    }

    public function test_existing_shipment_without_awb_stays_on_assign_awb(): void
    {
        $fulfilment = $this->fulfilment('RDE971014', HardwareFulfilmentState::ShipmentCreated);
        $ready = $this->readiness([
            'alreadyCreated' => true,
            'canCreate' => false,
            'canSelectCourier' => true,
            'selectedCourierId' => '15084',
            'awb' => null,
            'labelUrl' => null,
        ]);
        $row = app(HardwareFulfilmentOperationalClassifier::class)->fromFulfilment($fulfilment, $ready);

        $this->assertSame('Assign AWB', $row->nextAction);
        $this->assertNotSame('Select Courier', $row->nextAction);
        $this->assertNotSame('Create Shipment', $row->nextAction);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function readiness(array $overrides = []): HardwareShipmentReadiness
    {
        return new HardwareShipmentReadiness(
            canCreate: (bool) ($overrides['canCreate'] ?? false),
            blockers: [],
            status: 'Created',
            pickupBranch: null,
            pickupLocation: null,
            shipTo: null,
            parcel: null,
            invoice: $overrides['invoice'] ?? 'INV-1',
            serials: $overrides['serials'] ?? ['10532319'],
            order: 'RDE1',
            product: 'MFS',
            alreadyCreated: $overrides['alreadyCreated'] ?? true,
            actionLabel: $overrides['actionLabel'] ?? 'Create Shipment',
            awb: array_key_exists('awb', $overrides) ? $overrides['awb'] : 'AWB1',
            canFetchCourierOptions: (bool) ($overrides['canFetchCourierOptions'] ?? false),
            canSelectCourier: (bool) ($overrides['canSelectCourier'] ?? false),
            courierOptions: $overrides['courierOptions'] ?? [],
            selectedCourierId: $overrides['selectedCourierId'] ?? null,
            selectedCourierName: $overrides['selectedCourierName'] ?? null,
            labelUrl: array_key_exists('labelUrl', $overrides) ? $overrides['labelUrl'] : '/label.pdf',
            pickupStatus: $overrides['pickupStatus'] ?? 'Not requested',
            manifestStatus: $overrides['manifestStatus'] ?? 'Not generated',
            packageBeforeLabelRecorded: $overrides['packageBeforeLabelRecorded'] ?? false,
            packageLabelAppliedRecorded: $overrides['packageLabelAppliedRecorded'] ?? false,
            canAttachMeasuredParcel: (bool) ($overrides['canAttachMeasuredParcel'] ?? false),
            providerRejection: $overrides['providerRejection'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function order(string $orderId, array $overrides = []): Order
    {
        $creator = User::factory()->create(['is_active' => true]);
        if (filled($overrides['cashfree_payment_id'] ?? null)) {
            $overrides['cashfree_payment_id'] = 'cf_'.$orderId;
        }

        $order = Order::query()->create(array_merge([
            'order_id' => $orderId,
            'product_name' => 'MFS 110',
            'status' => 'active',
            'created_by' => $creator->id,
        ], $overrides));

        if (isset($overrides['created_at'])) {
            $order->forceFill(['created_at' => $overrides['created_at']])->save();
        }

        return $order->fresh();
    }

    private function fulfilment(string $sourceId, HardwareFulfilmentState $state, bool $eligible = false): HardwareFulfilment
    {
        $order = $this->order($sourceId, [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-07 10:00:00',
        ]);
        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'received_at' => now(),
            'ordered_at' => $eligible ? '2026-09-07 10:00:00' : null,
            'support_order_id' => $order->id,
        ]);

        if ($eligible) {
            CommerceOrderItem::query()->create([
                'commerce_order_id' => $commerce->id,
                'line_no' => 1,
                'sku' => '951',
                'shipping_line_kind' => HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND,
                'requires_shipping' => true,
                'model_id' => 951,
                'description' => 'MSO1300',
                'qty' => 1,
                'unit_price' => 3049,
                'gst_percentage' => 18,
                'taxable_value' => 2583.90,
                'tax_total' => 465.10,
                'line_total' => 3049,
            ]);
        }

        return HardwareFulfilment::query()->create([
            'commerce_order_id' => $commerce->id,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'idempotency_key' => $commerce->idempotency_key,
            'state' => $state,
            'support_order_id' => $order->id,
            'ingested_at' => now(),
        ]);
    }
}

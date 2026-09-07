<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\HardwareFulfilmentSerialStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Events\Finance\OrderPaid;
use App\Listeners\HardwareFulfilment\CorrelateHardwareCashfreePayment;
use App\Models\CommerceOrder;
use App\Models\FinanceJournal;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentPaymentEvidence;
use App\Models\HardwareFulfilmentSerial;
use App\Models\InvoiceSequence;
use App\Models\InvoiceSequenceAllocation;
use App\Models\Order;
use App\Models\StatutoryInvoice;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\HardwareFulfilment\Data\HardwarePaymentEvidenceDraft;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\HardwareFulfilment\HardwareFulfilmentPaymentCorrelationService;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use App\Services\StatutoryInvoice\StatutoryBillingIssuer;
use App\Services\StatutoryInvoice\StatutoryLocationSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HardwareFulfilmentP2WorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const BOX_SECRET = 'test-radiumbox-secret';

    private HardwareFulfilmentPaymentCorrelationService $correlation;

    private HardwareFulfilmentWorkflowService $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'channel_ingest.secrets.rdservice_in' => 'test-rdservice-in-secret',
            'channel_ingest.secrets.radiumbox_com' => self::BOX_SECRET,
            'channel_ingest.auto_issue_invoice' => false,
            'channel_ingest.cutover_approved' => false,
            'hardware_fulfilment.correlate_cashfree' => false,
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
        ]);

        $this->correlation = app(HardwareFulfilmentPaymentCorrelationService::class);
        $this->workflow = app(HardwareFulfilmentWorkflowService::class);
    }

    public function test_verified_cashfree_evidence_correlates_existing_fulfilment_without_advancing_state(): void
    {
        $this->ingestHardware('RDE900201');
        $deskOrder = Order::query()->create([
            'order_id' => 'RDE900201',
            'product_name' => 'MSO1300',
            'status' => 'active',
            'cashfree_payment_id' => 'cf_pay_900201',
            'payment_amount' => 3049,
            'payment_method' => 'UPI',
        ]);

        $evidence = $this->correlation->recordFromDeskOrder($deskOrder);

        $fulfilment = HardwareFulfilment::query()->firstOrFail();
        $this->assertNotNull($evidence);
        $this->assertTrue($evidence->verified);
        $this->assertSame($fulfilment->id, $evidence->hardware_fulfilment_id);
        $this->assertSame((int) $deskOrder->id, (int) $evidence->support_order_id);
        $this->assertSame('cf_pay_900201', $fulfilment->fresh()->cashfree_payment_id);
        $this->assertNotNull($fulfilment->fresh()->paid_recognized_at);
        $this->assertSame(HardwareFulfilmentState::Ingested, $fulfilment->fresh()->state);
        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(0, HardwareFulfilmentSerial::query()->whereNotNull('allocated_at')->count());
    }

    public function test_repeated_identical_payment_callback_is_idempotent(): void
    {
        $this->ingestHardware('RDE900202');
        $draft = $this->successDraft('RDE900202', 'cf_pay_900202');

        $first = $this->correlation->recordPaidEvidence($draft);
        $second = $this->correlation->recordPaidEvidence($draft);

        $this->assertSame($first?->id, $second?->id);
        $this->assertSame(1, HardwareFulfilmentPaymentEvidence::query()->count());
        $this->assertSame(1, HardwareFulfilment::query()->count());
        $this->assertSame(HardwareFulfilmentState::Ingested, HardwareFulfilment::query()->firstOrFail()->state);
    }

    public function test_multiple_payment_ids_for_the_same_rde_do_not_duplicate_fulfilment(): void
    {
        $this->ingestHardware('RDE900203');

        $this->correlation->recordPaidEvidence($this->successDraft('RDE900203', 'cf_pay_900203_a'));
        $this->correlation->recordPaidEvidence($this->successDraft('RDE900203', 'cf_pay_900203_b'));

        $fulfilment = HardwareFulfilment::query()->firstOrFail();
        $this->assertSame(1, HardwareFulfilment::query()->count());
        $this->assertSame(2, $fulfilment->paymentEvidence()->count());
        $this->assertSame('cf_pay_900203_a', $fulfilment->fresh()->cashfree_payment_id);
        $this->assertSame('statutory:radiumbox_com:commerce_order:RDE900203', $fulfilment->idempotency_key);
    }

    public function test_unknown_payment_status_does_not_mark_fulfilment_ready_or_paid(): void
    {
        $this->ingestHardware('RDE900204');

        $evidence = $this->correlation->recordPaidEvidence(new HardwarePaymentEvidenceDraft(
            sourceId: 'RDE900204',
            cashfreePaymentId: 'cf_pay_900204_pending',
            paymentStatus: 'PENDING',
        ));

        $fulfilment = HardwareFulfilment::query()->firstOrFail();
        $this->assertNotNull($evidence);
        $this->assertFalse($evidence->verified);
        $this->assertNull($fulfilment->paid_recognized_at);
        $this->assertSame(HardwareFulfilmentState::Ingested, $fulfilment->state);
        $this->assertNull($fulfilment->cashfree_payment_id);
        $this->expectException(ValidationException::class);
        $this->workflow->assertCanAllocateSerials($fulfilment);
    }

    public function test_out_of_order_events_cannot_regress_state(): void
    {
        $fulfilment = $this->ingestHardware('RDE900205');
        $this->workflow->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);
        $ready = $this->workflow->transition($fulfilment->fresh(), HardwareFulfilmentState::SerialsAllocated);

        $this->assertSame(HardwareFulfilmentState::SerialsAllocated, $ready->state);

        foreach ([
            HardwareFulfilmentState::Paid,
            HardwareFulfilmentState::Ingested,
            HardwareFulfilmentState::ReadyForFulfilment,
        ] as $back) {
            try {
                $this->workflow->transition($ready, $back);
                $this->fail('Expected regression to '.$back->value.' to fail.');
            } catch (ValidationException) {
                $this->assertSame(HardwareFulfilmentState::SerialsAllocated, $ready->fresh()->state);
            }
        }
    }

    public function test_ready_for_fulfilment_cannot_become_invoice_issued_without_serials(): void
    {
        $fulfilment = $this->ingestHardware('RDE900206');
        $ready = $this->workflow->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);

        $this->assertFalse($ready->state->canTransitionTo(HardwareFulfilmentState::InvoiceIssued));

        try {
            $this->workflow->transition($ready, HardwareFulfilmentState::InvoiceIssued);
            $this->fail('READY must not skip to INVOICE_ISSUED.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('SERIALS_ALLOCATED', implode(' ', $exception->errors()['state'] ?? $exception->errors()['invoice'] ?? []));
        }

        $this->expectException(ValidationException::class);
        $this->workflow->assertCanIssueInvoice($ready->fresh());
    }

    public function test_serials_allocated_can_progress_toward_invoice_issuance_without_minting(): void
    {
        $fulfilment = $this->ingestHardware('RDE900207');
        $this->workflow->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);
        $allocated = $this->workflow->transition($fulfilment->fresh(), HardwareFulfilmentState::SerialsAllocated);

        $this->assertTrue($allocated->state->canTransitionTo(HardwareFulfilmentState::InvoiceIssued));
        $this->workflow->assertCanIssueInvoice($allocated);
        $this->assertSame([], $this->workflow->allocatedSerialNumbers($allocated));
        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(HardwareFulfilmentState::SerialsAllocated, $allocated->fresh()->state);
    }

    public function test_payment_first_evidence_attaches_on_later_ingest(): void
    {
        $this->correlation->recordPaidEvidence($this->successDraft('RDE900208', 'cf_pay_900208'));
        $this->assertSame(1, HardwareFulfilmentPaymentEvidence::query()->count());
        $this->assertSame(0, HardwareFulfilment::query()->count());

        $this->ingestHardware('RDE900208');

        $fulfilment = HardwareFulfilment::query()->firstOrFail();
        $evidence = HardwareFulfilmentPaymentEvidence::query()->firstOrFail();
        $this->assertSame($fulfilment->id, $evidence->hardware_fulfilment_id);
        $this->assertSame('cf_pay_900208', $fulfilment->cashfree_payment_id);
        $this->assertNotNull($fulfilment->paid_recognized_at);
        $this->assertSame(HardwareFulfilmentState::Ingested, $fulfilment->state);
    }

    public function test_order_paid_listener_is_off_by_default_and_does_not_mint(): void
    {
        $this->ingestHardware('RDE900209');
        $order = Order::query()->create([
            'order_id' => 'RDE900209',
            'status' => 'active',
            'cashfree_payment_id' => 'cf_pay_900209',
        ]);

        app(CorrelateHardwareCashfreePayment::class)->handle(new OrderPaid($order));

        $this->assertSame(0, HardwareFulfilmentPaymentEvidence::query()->count());
        $this->assertFalse((bool) config('hardware_fulfilment.correlate_cashfree'));

        config(['hardware_fulfilment.correlate_cashfree' => true]);
        app(CorrelateHardwareCashfreePayment::class)->handle(new OrderPaid($order));

        $this->assertSame(1, HardwareFulfilmentPaymentEvidence::query()->count());
        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(HardwareFulfilmentState::Ingested, HardwareFulfilment::query()->firstOrFail()->state);
    }

    public function test_frozen_pending_rde_orders_are_not_processed_by_p2(): void
    {
        $this->assertSame([
            'RDE318360',
            'RDE318367',
            'RDE318378',
            'RDE318379',
            'RDE318382',
            'RDE318388',
            'RDE318391',
        ], HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS);

        foreach (HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS as $sourceId) {
            $this->assertTrue(HardwareFulfilmentEligibility::isFrozenSourceId($sourceId));
            $this->assertNull($this->correlation->recordPaidEvidence($this->successDraft($sourceId, 'cf_frozen_'.$sourceId)));
        }

        $this->assertSame(0, HardwareFulfilment::query()->count());
        $this->assertSame(0, HardwareFulfilmentPaymentEvidence::query()->count());
        $this->assertSame(0, CommerceOrder::query()->count());
    }

    public function test_large_order_serial_foundation_still_supports_two_hundred_references(): void
    {
        $fulfilment = $this->ingestHardware('RDE900210');
        $item = $fulfilment->commerceOrder?->items->first();
        $this->assertNotNull($item);

        for ($position = 1; $position <= 200; $position++) {
            HardwareFulfilmentSerial::query()->create([
                'hardware_fulfilment_id' => $fulfilment->id,
                'commerce_order_item_id' => $item->id,
                'line_no' => 1,
                'position' => $position,
                'serial_number' => sprintf('P2-FOUNDATION-%03d', $position),
                'status' => HardwareFulfilmentSerialStatus::Pending,
            ]);
        }

        $this->assertSame(200, $fulfilment->serials()->count());
        $this->assertSame([], $this->workflow->allocatedSerialNumbers($fulfilment));
        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_pos_product_issuer_remains_unchanged(): void
    {
        $this->assertSame(
            StatutoryLocationSeries::DELHI,
            app(StatutoryBillingIssuer::class)->requireForProductBranch('DELHI-RETAIL'),
        );
        $this->assertFalse((bool) config('channel_ingest.auto_issue_invoice'));
        $this->assertSame(0, InvoiceSequence::query()->count());
        $this->assertSame(0, InvoiceSequenceAllocation::query()->count());
        $this->assertSame(0, FinanceJournal::query()->count());
    }

    public function test_shipment_cannot_start_from_serials_allocated(): void
    {
        $fulfilment = $this->ingestHardware('RDE900211');
        $this->workflow->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);
        $allocated = $this->workflow->transition($fulfilment->fresh(), HardwareFulfilmentState::SerialsAllocated);

        $this->expectException(ValidationException::class);
        $this->workflow->assertCanCreateShipment($allocated);
    }

    private function ingestHardware(string $sourceId): HardwareFulfilment
    {
        $payload = [
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom->value,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'payment_status' => 'paid',
            'payment_provider' => 'cashfree',
            'payment_reference' => 'pay_'.$sourceId,
            'currency' => 'INR',
            'customer' => ['name' => 'Hardware Buyer', 'phone' => '9000000099'],
            'seller_gstin' => '07AAICP1128M1Z9',
            'place_of_supply_state' => 'Madhya Pradesh',
            'lines' => [[
                'description' => 'MSO1300',
                'sku' => '951',
                'qty' => 1,
                'unit_price' => 3049,
                'hsn_sac' => '84716050',
                'gst_percentage' => 18,
                'taxable_value' => 2583.90,
                'tax_total' => 465.10,
                'line_total' => 3049,
                'shipping_line_kind' => 'physical_merchandise',
                'requires_shipping' => true,
                'model_id' => 951,
            ]],
        ];

        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $this->call('POST', '/api/v1/channel-orders', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DESK_CHANNEL' => StatutoryInvoiceChannel::RadiumBoxCom->value,
            'HTTP_X_DESK_TIMESTAMP' => $timestamp,
            'HTTP_X_DESK_SIGNATURE' => (new ChannelIngestAuthenticator)->signature($timestamp, $body, self::BOX_SECRET),
        ], $body)->assertCreated();

        return HardwareFulfilment::query()->where('source_id', $sourceId)->firstOrFail();
    }

    private function successDraft(string $sourceId, string $paymentId): HardwarePaymentEvidenceDraft
    {
        return new HardwarePaymentEvidenceDraft(
            sourceId: $sourceId,
            cashfreePaymentId: $paymentId,
            paymentStatus: 'SUCCESS',
        );
    }
}

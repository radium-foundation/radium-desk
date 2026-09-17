<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Enums\CommerceOrderStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\CommerceOrder;
use App\Services\StatutoryInvoice\EInvoiceEligibility;
use App\Services\StatutoryInvoice\EInvoiceIrnPayloadMapper;
use App\Services\StatutoryInvoice\EInvoiceIssuancePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesStatutoryInvoicesForEinvoice;
use Tests\TestCase;

class EInvoiceEligibilityAndMapperTest extends TestCase
{
    use CreatesStatutoryInvoicesForEinvoice;
    use RefreshDatabase;

    public function test_b2b_with_complete_gst_is_eligible(): void
    {
        $invoice = $this->makeHardwareTaxInvoice();

        $decision = app(EInvoiceEligibility::class)->evaluate($invoice);

        $this->assertTrue($decision->eligible);
        $this->assertSame('b2b_eligible', $decision->reason);
    }

    public function test_phase_a_b2b_service_is_not_eligible(): void
    {
        $invoice = $this->makeTaxInvoice();

        $decision = app(EInvoiceEligibility::class)->evaluate($invoice);

        $this->assertFalse($decision->eligible);
        $this->assertSame(EInvoiceIssuancePolicy::SKIP_SERVICE, $decision->reason);
    }

    public function test_b2c_without_gstin_is_rejected(): void
    {
        $invoice = $this->makeTaxInvoice(['buyer_gstin' => null]);

        $decision = app(EInvoiceEligibility::class)->evaluate($invoice);

        $this->assertFalse($decision->eligible);
        $this->assertSame('b2c_not_eligible', $decision->reason);
    }

    public function test_cancelled_invoice_is_not_eligible(): void
    {
        $invoice = $this->makeTaxInvoice([
            'status' => StatutoryInvoiceStatus::Cancelled,
        ]);

        $decision = app(EInvoiceEligibility::class)->evaluate($invoice);

        $this->assertFalse($decision->eligible);
        $this->assertSame('invoice_cancelled', $decision->reason);
    }

    public function test_invoice_before_scope_start_is_not_eligible(): void
    {
        $invoice = $this->makeTaxInvoice([
            'issued_at' => '2026-08-31 23:59:59',
        ]);

        $decision = app(EInvoiceEligibility::class)->evaluate($invoice);

        $this->assertFalse($decision->eligible);
        $this->assertSame('outside_invoice_scope', $decision->reason);
    }

    public function test_gstin_like_value_is_not_treated_as_b2b(): void
    {
        $invoice = $this->makeTaxInvoice(['buyer_gstin' => '07AAAAA0000A1']);

        $decision = app(EInvoiceEligibility::class)->evaluate($invoice);

        $this->assertFalse($decision->eligible);
        $this->assertSame('invalid_buyer_gstin', $decision->reason);
    }

    public function test_null_cgst_is_not_an_irn_candidate(): void
    {
        $invoice = $this->makeTaxInvoice(
            ['cgst' => null],
            ['cgst' => null],
        );

        $decision = app(EInvoiceEligibility::class)->evaluate($invoice);

        $this->assertFalse($decision->eligible);
        $this->assertSame('incomplete_gst', $decision->reason);
    }

    public function test_credit_note_is_unsupported(): void
    {
        $invoice = $this->makeTaxInvoice([
            'document_type' => StatutoryInvoiceDocumentType::CreditNote,
        ]);

        $decision = app(EInvoiceEligibility::class)->evaluate($invoice);

        $this->assertFalse($decision->eligible);
        $this->assertSame('unsupported_document_type', $decision->reason);
    }

    public function test_debit_note_is_unsupported(): void
    {
        $invoice = $this->makeTaxInvoice([
            'document_type' => StatutoryInvoiceDocumentType::DebitNote,
        ]);

        $decision = app(EInvoiceEligibility::class)->evaluate($invoice);

        $this->assertFalse($decision->eligible);
        $this->assertSame('unsupported_document_type', $decision->reason);
    }

    public function test_payload_copies_stored_tax_and_lists_irp_gaps(): void
    {
        $invoice = $this->makeTaxInvoice(
            [
                'taxable_value' => '422.88',
                'tax_total' => '76.12',
                'cgst' => '38.06',
                'sgst' => '38.06',
                'igst' => '0.00',
                'invoice_value' => '499.00',
            ],
            [
                'unit_price' => '422.88',
                'taxable_value' => '422.88',
                'gst_percentage' => '18.00',
                'cgst' => '38.06',
                'sgst' => '38.06',
                'igst' => '0.00',
                'tax_total' => '76.12',
                'line_total' => '499.00',
            ],
        );

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);

        $this->assertSame('422.88', $payload->values['taxable_value']);
        $this->assertSame('18.00', $payload->items[0]['gst_percentage']);
        $this->assertSame('38.06', $payload->values['cgst']);
        $this->assertSame('38.06', $payload->values['sgst']);
        $this->assertSame('0.00', $payload->values['igst']);
        $this->assertSame('499.00', $payload->values['invoice_value']);
        $this->assertSame('OTH', $payload->items[0]['unit']);
        $this->assertSame('Y', $payload->items[0]['is_servc']);
        $this->assertNotContains('missing_uqc', $payload->gaps);
        $this->assertContains('missing_seller_pin', $payload->gaps);
        $this->assertContains('missing_seller_loc', $payload->gaps);
        $this->assertContains('missing_buyer_pin', $payload->gaps);
        $this->assertContains('missing_buyer_loc', $payload->gaps);
        $this->assertNotContains('missing_is_servc', $payload->gaps);
        $this->assertFalse($payload->isSubmittable());
    }

    public function test_mapper_fills_seller_and_buyer_snapshot_but_still_fails_closed_without_uqc(): void
    {
        config([
            'statutory_invoices.legal_name' => 'Phil Technologies (P) Limited',
            'statutory_invoices.location_series.locations.delhi.gstin' => '07AAICP1128M1Z9',
            'statutory_invoices.location_series.locations.delhi.pin' => '110019',
            'statutory_invoices.location_series.locations.delhi.loc' => 'New Delhi',
        ]);
        $invoice = $this->makeTaxInvoice();
        CommerceOrder::query()->create([
            'order_no' => 'CO-'.$invoice->source_id,
            'channel' => $invoice->channel,
            'source_type' => 'commerce_order',
            'source_id' => $invoice->source_id,
            'source_order_id' => $invoice->source_id,
            'idempotency_key' => 'statutory:einvoice:commerce:'.$invoice->id,
            'payload_hash' => hash('sha256', 'einvoice-'.$invoice->id),
            'status' => CommerceOrderStatus::Invoiced,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Buyer Industries',
            'buyer_gstin' => $invoice->buyer_gstin,
            'billing_address' => '1 Test Street, Delhi',
            'billing_address_structured' => [
                'line1' => '1 Test Street',
                'city' => 'New Delhi',
                'state' => 'Delhi',
                'pincode' => '110001',
            ],
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 100,
            'tax_total' => 18,
            'order_value' => 118,
            'statutory_invoice_id' => $invoice->id,
            'ordered_at' => '2026-09-10 10:00:00',
            'received_at' => now(),
        ]);

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice->fresh(['items']));

        $this->assertSame('110019', $payload->seller['pin']);
        $this->assertSame('New Delhi', $payload->seller['location']);
        $this->assertNotNull($payload->seller['address']);
        $this->assertSame('110001', $payload->buyer['pin']);
        $this->assertSame('New Delhi', $payload->buyer['location']);
        $this->assertSame('07', $payload->buyer['pos_code']);
        $this->assertSame('Y', $payload->items[0]['is_servc']);
        $this->assertSame('B2B', $payload->supplyType);
        $this->assertSame('INV', $payload->document['type']);
        $this->assertSame('OTH', $payload->items[0]['unit']);
        $this->assertNotContains('missing_uqc', $payload->gaps);
        $this->assertTrue($payload->isSubmittable());
    }

    public function test_hardware_channel_sets_is_servc_n(): void
    {
        $invoice = $this->makeTaxInvoice(
            ['channel' => StatutoryInvoiceChannel::DeskPos],
            ['sku' => 'MFS110', 'hsn_sac' => '84716050', 'description' => 'Mantra MFS 110'],
        );

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);

        $this->assertSame('N', $payload->items[0]['is_servc']);
        $this->assertNotContains('missing_is_servc', $payload->gaps);
    }

    public function test_unknown_channel_fails_closed_for_is_servc(): void
    {
        $invoice = $this->makeTaxInvoice(['channel' => StatutoryInvoiceChannel::RadiumSignCom]);

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);

        $this->assertNull($payload->items[0]['is_servc']);
        $this->assertContains('missing_is_servc', $payload->gaps);
    }

    public function test_mapper_does_not_recalculate_stored_tax(): void
    {
        $invoice = $this->makeTaxInvoice(
            [
                'taxable_value' => '100.00',
                'tax_total' => '18.00',
                'cgst' => '9.00',
                'sgst' => '9.00',
                'igst' => '0.00',
                'invoice_value' => '118.00',
            ],
            [
                'gst_percentage' => '18.00',
                'taxable_value' => '100.00',
                'tax_total' => '18.00',
                'cgst' => '9.00',
                'sgst' => '9.00',
                'igst' => '0.00',
            ],
        );

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);

        $this->assertSame('18.00', $payload->values['tax_total']);
        $this->assertSame('18.00', $payload->items[0]['tax_total']);
        $this->assertNotSame('18.01', $payload->values['tax_total']);
    }
}

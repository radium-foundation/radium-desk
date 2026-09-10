<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Enums\CommerceOrderStatus;
use App\Enums\EInvoiceIssuanceKind;
use App\Enums\EInvoiceIssuancePolicyMode;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Services\StatutoryInvoice\EInvoiceEligibility;
use App\Services\StatutoryInvoice\EInvoiceIrnPayloadMapper;
use App\Services\StatutoryInvoice\EInvoiceIssuanceClassifier;
use App\Services\StatutoryInvoice\EInvoiceIssuancePolicy;
use App\Services\StatutoryInvoice\EInvoiceServiceClassification;
use App\Services\StatutoryInvoice\Whitebooks\WhitebooksNicPayloadFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesStatutoryInvoicesForEinvoice;
use Tests\TestCase;

class EInvoiceServicePayloadTest extends TestCase
{
    use CreatesStatutoryInvoicesForEinvoice;
    use RefreshDatabase;

    public function test_phase_b_service_payload_uses_the_same_mapper_pipeline(): void
    {
        config([
            'statutory_invoices.einvoice.issuance_policy' => EInvoiceIssuancePolicyMode::AllEligibleB2b->value,
            'statutory_invoices.legal_name' => 'Phil Technologies (P) Limited',
            'statutory_invoices.location_series.locations.delhi.gstin' => '07AAICP1128M1Z9',
            'statutory_invoices.location_series.locations.delhi.pin' => '110019',
            'statutory_invoices.location_series.locations.delhi.loc' => 'New Delhi',
        ]);
        $this->app->forgetInstance(EInvoiceIssuancePolicy::class);
        $this->app->forgetInstance(EInvoiceEligibility::class);

        $invoice = $this->makeTaxInvoice(
            [
                'channel' => StatutoryInvoiceChannel::RdServiceIn,
                'invoice_number' => 'INV-076750',
                'seller_gstin' => '07AAICP1128M1Z9',
                'buyer_gstin' => '07AAAAA0000A1Z5',
                'place_of_supply_state' => 'Delhi',
                'taxable_value' => '422.88',
                'tax_total' => '76.12',
                'cgst' => '38.06',
                'sgst' => '38.06',
                'igst' => '0.00',
                'invoice_value' => '499.00',
            ],
            [
                'sku' => 'RD-SVC',
                'description' => 'RD Service',
                'hsn_sac' => '998313',
                'qty' => 1,
                'unit_price' => '422.88',
                'uqc' => 'NOS',
                'gst_percentage' => '18.00',
                'taxable_value' => '422.88',
                'cgst' => '38.06',
                'sgst' => '38.06',
                'igst' => '0.00',
                'tax_total' => '76.12',
                'line_total' => '499.00',
            ],
        );
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
            'taxable_value' => 422.88,
            'tax_total' => 76.12,
            'order_value' => 499,
            'statutory_invoice_id' => $invoice->id,
            'ordered_at' => '2026-09-10 10:00:00',
            'received_at' => now(),
        ]);

        $this->assertSame(EInvoiceIssuanceKind::Service, app(EInvoiceIssuanceClassifier::class)->classify($invoice));
        $this->assertTrue(app(EInvoiceEligibility::class)->evaluate($invoice)->eligible);
        $this->assertSame('Y', app(EInvoiceServiceClassification::class)->isServc($invoice, $invoice->items->first()));

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice->fresh(['items']));
        $this->assertSame('07AAICP1128M1Z9', $payload->seller['gstin']);
        $this->assertSame('07AAAAA0000A1Z5', $payload->buyer['gstin']);
        $this->assertSame('INV-076750', $payload->document['number']);
        $this->assertSame('INV', $payload->document['type']);
        $this->assertNotSame('', (string) ($payload->document['date'] ?? ''));
        $this->assertSame('998313', $payload->items[0]['hsn_sac']);
        $this->assertSame('Y', $payload->items[0]['is_servc']);
        $this->assertSame(1, (int) $payload->items[0]['qty']);
        $this->assertSame('NOS', $payload->items[0]['unit']);
        $this->assertSame('422.88', $payload->items[0]['taxable_value']);
        $this->assertSame('38.06', $payload->items[0]['cgst']);
        $this->assertSame('38.06', $payload->items[0]['sgst']);
        $this->assertSame('0.00', $payload->items[0]['igst']);
        $this->assertSame('499.00', $payload->values['invoice_value']);
        $this->assertSame('07', $payload->buyer['pos_code']);
        $this->assertNotContains('missing_uqc', $payload->gaps);
        $this->assertTrue($payload->isSubmittable());

        $body = (new WhitebooksNicPayloadFactory)->generateBody($payload);
        $this->assertIsArray($body);
        $this->assertSame('B2B', $body['TranDtls']['SupTyp'] ?? null);
        $this->assertSame('INV-076750', $body['DocDtls']['No'] ?? null);
        $this->assertSame('07AAICP1128M1Z9', $body['SellerDtls']['Gstin'] ?? null);
        $this->assertSame('07AAAAA0000A1Z5', $body['BuyerDtls']['Gstin'] ?? null);
        $this->assertSame('Y', $body['ItemList'][0]['IsServc'] ?? null);
        $this->assertSame('998313', $body['ItemList'][0]['HsnCd'] ?? null);
        $this->assertSame('NOS', $body['ItemList'][0]['Unit'] ?? null);
        $this->assertSame(1.0, $body['ItemList'][0]['Qty'] ?? null);
        $this->assertSame(422.88, $body['ItemList'][0]['UnitPrice'] ?? null);
        $this->assertSame(422.88, $body['ItemList'][0]['AssAmt'] ?? null);
        $this->assertSame(499.0, $body['ValDtls']['TotInvVal'] ?? null);
    }

    public function test_phase_a_does_not_change_service_payload_construction(): void
    {
        $invoice = $this->makeTaxInvoice([], ['uqc' => 'NOS']);
        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);

        $this->assertFalse(app(EInvoiceEligibility::class)->evaluate($invoice)->eligible);
        $this->assertSame('Y', $payload->items[0]['is_servc']);
        $this->assertSame('998313', $payload->items[0]['hsn_sac']);
        $this->assertSame('NOS', $payload->items[0]['unit']);
    }
}

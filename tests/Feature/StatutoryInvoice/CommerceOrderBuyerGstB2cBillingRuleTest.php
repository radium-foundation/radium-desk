<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\BuyerGstVerificationStatus;
use App\Enums\CommerceOrderStatus;
use App\Enums\EInvoiceRecordStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderBuyerGstB2cConversion;
use App\Models\CommerceOrderBuyerGstVerification;
use App\Models\EInvoiceRecord;
use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\CommerceOrderBuyerGstB2cConversionService;
use App\Services\StatutoryInvoice\Data\StatutoryInvoicePdfPayload;
use App\Services\StatutoryInvoice\EInvoiceEligibility;
use App\Services\StatutoryInvoice\ServiceStatutoryGstB2bClassification;
use App\Services\StatutoryInvoice\SimplePdfRenderer;
use App\Services\StatutoryInvoice\StatutoryInvoiceB2cFooterRemarkResolver;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommerceOrderBuyerGstB2cBillingRuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'statutory_invoices.buyer_gstin_verification.enabled' => true,
            'statutory_invoices.buyer_gstin_verification.b2c_fallback.enabled' => true,
            'statutory_invoices.einvoice.provider' => 'none',
        ]);
    }

    public function test_authorized_conversion_yields_blank_buyer_gstin_for_mint(): void
    {
        [$order] = $this->orderWithApprovedConversion();

        $decision = app(ServiceStatutoryGstB2bClassification::class)->resolveForServiceOrder($order->fresh());
        $this->assertFalse($decision->issueAsB2b);
        $this->assertNull($decision->buyerGstinForMint);
        $this->assertSame('07AAICB5215F2ZX', $order->fresh()->buyer_gstin);
    }

    public function test_attach_issued_statutory_invoice_links_conversion_without_clearing_order_gstin(): void
    {
        [$order, $conversion] = $this->orderWithApprovedConversion();

        $invoice = $this->issuedB2cInvoice($order);
        app(CommerceOrderBuyerGstB2cConversionService::class)->attachIssuedStatutoryInvoice($order, $invoice);

        $conversion->refresh();
        $this->assertSame($invoice->id, $conversion->statutory_invoice_id);
        $this->assertNull($invoice->fresh()->buyer_gstin);
        $this->assertSame('07AAICB5215F2ZX', $order->fresh()->buyer_gstin);
        $this->assertSame('07AAICB5215F2ZX', $conversion->submitted_gstin);
    }

    public function test_footer_resolver_returns_masked_conversion_remark_only_for_b2c_commerce_invoice(): void
    {
        [$order, $conversion] = $this->orderWithApprovedConversion();
        $invoice = $this->issuedB2cInvoice($order);
        app(CommerceOrderBuyerGstB2cConversionService::class)->attachIssuedStatutoryInvoice($order, $invoice);

        $remark = app(StatutoryInvoiceB2cFooterRemarkResolver::class)->forInvoice($invoice->fresh());
        $this->assertSame($conversion->invoice_remark, $remark);
        $this->assertStringNotContainsString('07AAICB5215F2ZX', (string) $remark);
    }

    public function test_b2c_invoice_with_blank_gstin_is_not_einvoice_eligible(): void
    {
        $invoice = $this->issuedB2cInvoice($this->orderWithApprovedConversion()[0]);
        $result = app(EInvoiceEligibility::class)->evaluate($invoice->fresh());

        $this->assertFalse($result->eligible);
        $this->assertSame('b2c_not_eligible', $result->reason);
    }

    public function test_queue_einvoice_marks_skipped_for_b2c_conversion_invoice(): void
    {
        $order = $this->orderWithApprovedConversion()[0];
        $invoice = $this->issuedB2cInvoice($order);
        app(StatutoryInvoiceService::class)->queueEinvoiceIfEligible($invoice->fresh());

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($record);
        $this->assertSame(EInvoiceRecordStatus::Skipped->value, $record->status);
    }

    public function test_pdf_payload_footer_renders_for_b2c_conversion_remark(): void
    {
        $remark = 'B2C issued: buyer GSTIN 07******2ZX registration status Suspended (GST verification).';
        $payload = new StatutoryInvoicePdfPayload(
            invoiceNumber: 'INV-TEST',
            issuedAt: now()->toDateTimeString(),
            sellerLegalName: 'Seller',
            sellerGstin: '07AAICP1128M1Z9',
            sellerAddress: 'Addr',
            sellerState: 'Delhi',
            buyerName: 'Buyer',
            buyerGstin: null,
            billingAddress: 'Bill',
            placeOfSupply: 'Rajasthan',
            lines: [],
            taxableValue: '100.00',
            gstRate: '18',
            taxTotal: '18.00',
            cgst: '0.00',
            sgst: '0.00',
            igst: '18.00',
            invoiceValue: '118.00',
            footerRemark: $remark,
        );

        $binary = (new SimplePdfRenderer)->render($payload);
        $this->assertNotEmpty($binary);
        $this->assertStringStartsWith('%PDF', $binary);
        $this->assertGreaterThan(strlen($remark) + 500, strlen($binary));
    }

    /**
     * @return array{0: CommerceOrder, 1: CommerceOrderBuyerGstB2cConversion}
     */
    private function orderWithApprovedConversion(): array
    {
        $suffix = bin2hex(random_bytes(4));
        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-BILLING-'.$suffix,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => 'RBP-BILL-'.$suffix,
            'source_order_id' => 'RBP-BILL-'.$suffix,
            'idempotency_key' => 'test:billing:'.$suffix,
            'payload_hash' => hash('sha256', 'billing:'.$suffix),
            'status' => CommerceOrderStatus::Validated,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Buyer',
            'buyer_gstin' => '07AAICB5215F2ZX',
            'billing_address_structured' => ['state' => 'Rajasthan', 'city' => 'Jodhpur', 'pincode' => '304012'],
            'place_of_supply_state' => 'Rajasthan',
            'billing_state' => 'Rajasthan',
            'order_value' => 100,
            'taxable_value' => 84.75,
            'tax_total' => 15.25,
            'ordered_at' => now(),
            'received_at' => now(),
        ]);

        CommerceOrderBuyerGstVerification::query()->create([
            'commerce_order_id' => $order->id,
            'submitted_gstin' => '07AAICB5215F2ZX',
            'verification_status' => BuyerGstVerificationStatus::ReviewRequired,
            'registration_status' => 'Suspended',
            'review_reason' => 'inactive_registration',
            'provider_payload_hash' => hash('sha256', 'fixture'),
        ]);

        $conversion = app(CommerceOrderBuyerGstB2cConversionService::class)->ensureAutomaticConversion($order->fresh());
        $this->assertNotNull($conversion);

        return [$order->fresh(), $conversion];
    }

    private function issuedB2cInvoice(CommerceOrder $order): StatutoryInvoice
    {
        return StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-B2C-'.bin2hex(random_bytes(3)),
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => $order->channel,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => $order->source_id,
            'idempotency_key' => 'statutory:b2c:'.bin2hex(random_bytes(4)),
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Phil Technologies (P) Limited',
            'buyer_name' => $order->customer_name,
            'buyer_gstin' => null,
            'billing_address' => 'Test billing',
            'place_of_supply_state' => $order->place_of_supply_state,
            'taxable_value' => 84.75,
            'tax_total' => 15.25,
            'cgst' => 0,
            'sgst' => 0,
            'igst' => 15.25,
            'rounding' => 0,
            'invoice_value' => 100,
            'issued_at' => now(),
        ]);
    }
}

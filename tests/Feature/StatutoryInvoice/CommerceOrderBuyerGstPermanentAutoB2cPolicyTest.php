<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\BuyerGstB2cConversionStatus;
use App\Enums\BuyerGstVerificationStatus;
use App\Enums\CommerceOrderStatus;
use App\Enums\EInvoiceRecordStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderBuyerGstB2cConversion;
use App\Models\CommerceOrderBuyerGstVerification;
use App\Models\EInvoiceRecord;
use App\Services\StatutoryInvoice\CommerceBuyerGstMintGate;
use App\Services\StatutoryInvoice\CommerceOrderBuyerGstB2cConversionService;
use App\Services\StatutoryInvoice\EInvoiceEligibility;
use App\Services\StatutoryInvoice\ServiceStatutoryGstB2bClassification;
use App\Support\StatutoryInvoice\BuyerGstB2cConversionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * P-20-23 / P-20-27 — Permanent automatic B2C for confirmed B2B ineligibility (verification on; not Stage B).
 */
#[Group('permanent_regression')]
class CommerceOrderBuyerGstPermanentAutoB2cPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'statutory_invoices.buyer_gstin_verification.enabled' => true,
            'statutory_invoices.buyer_gstin_verification.b2c_fallback.enabled' => true,
        ]);
    }

    public function test_confirmed_inactive_registration_converts_without_manual_approval(): void
    {
        $order = $this->orderWithVerification(
            BuyerGstVerificationStatus::ReviewRequired,
            'inactive_registration',
            'Suspended',
        );

        $conversion = app(CommerceOrderBuyerGstB2cConversionService::class)->ensureAutomaticConversion($order);
        $this->assertNotNull($conversion);
        $this->assertSame(BuyerGstB2cConversionStatus::Approved, $conversion->status);
        $this->assertNull($conversion->authorized_by);
        $this->assertSame('07AAICB5215F2ZX', $conversion->submitted_gstin);
        $this->assertEmpty(app(CommerceBuyerGstMintGate::class)->blockingReasonsForOrder($order->fresh()));
    }

    public function test_provider_uncertainty_does_not_auto_convert(): void
    {
        $order = $this->orderWithVerification(
            BuyerGstVerificationStatus::ReviewRequired,
            'provider_timeout',
            null,
        );

        $this->assertFalse(BuyerGstB2cConversionPolicy::isEligibleForAutomaticConversion(
            CommerceOrderBuyerGstVerification::query()->first(),
            $order,
        ));
        $this->assertNull(app(CommerceOrderBuyerGstB2cConversionService::class)->ensureAutomaticConversion($order));
        $this->assertNotEmpty(app(CommerceBuyerGstMintGate::class)->blockingReasonsForOrder($order->fresh()));
    }

    public function test_service_and_gate_share_approved_conversion_without_gst_review(): void
    {
        $order = $this->orderWithVerification(
            BuyerGstVerificationStatus::ReviewRequired,
            'inactive_registration',
            'Cancelled',
        );

        $decision = app(ServiceStatutoryGstB2bClassification::class)->resolveForServiceOrder($order->fresh());
        $this->assertFalse($decision->issueAsB2b);
        $this->assertNull($decision->buyerGstinForMint);
        $this->assertFalse($decision->requiresGstReview);

        $conversion = CommerceOrderBuyerGstB2cConversion::query()->firstOrFail();
        $this->assertNull($conversion->authorized_by);
    }

    public function test_b2c_decision_skips_b2b_irn_queue(): void
    {
        $order = $this->orderWithVerification(
            BuyerGstVerificationStatus::ReviewRequired,
            'inactive_registration',
            'Suspended',
        );
        app(CommerceOrderBuyerGstB2cConversionService::class)->ensureAutomaticConversion($order);

        $invoice = \App\Models\StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-P20-23-'.bin2hex(random_bytes(3)),
            'document_type' => \App\Enums\StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => \App\Enums\StatutoryInvoiceStatus::Issued,
            'channel' => $order->channel,
            'source_type' => 'commerce_order',
            'source_id' => $order->source_id,
            'idempotency_key' => 'p20-23:'.bin2hex(random_bytes(4)),
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Seller',
            'buyer_name' => $order->customer_name,
            'buyer_gstin' => null,
            'billing_address' => 'Test',
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

        $result = app(EInvoiceEligibility::class)->evaluate($invoice);
        $this->assertFalse($result->eligible);
        $this->assertSame('b2c_not_eligible', $result->reason);

        EInvoiceRecord::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => 'none',
            'status' => EInvoiceRecordStatus::Skipped->value,
            'response_payload' => ['skip_reason' => $result->reason],
        ]);
    }

    public function test_stage_b_fallback_off_still_auto_converts_confirmed_ineligibility(): void
    {
        config(['statutory_invoices.buyer_gstin_verification.b2c_fallback.enabled' => false]);
        $order = $this->orderWithVerification(
            BuyerGstVerificationStatus::ReviewRequired,
            'inactive_registration',
            'Suspended',
        );

        $conversion = app(CommerceOrderBuyerGstB2cConversionService::class)->ensureAutomaticConversion($order);
        $this->assertNotNull($conversion);
        $this->assertNull($conversion->authorized_by);
        $decision = app(ServiceStatutoryGstB2bClassification::class)->resolveForServiceOrder($order->fresh());
        $this->assertFalse($decision->issueAsB2b);
        $this->assertFalse($decision->requiresGstReview);
    }

    public function test_stage_b_off_provider_uncertainty_still_holds(): void
    {
        config(['statutory_invoices.buyer_gstin_verification.b2c_fallback.enabled' => false]);
        $order = $this->orderWithVerification(
            BuyerGstVerificationStatus::ReviewRequired,
            'provider_timeout',
            null,
        );

        $this->assertNull(app(CommerceOrderBuyerGstB2cConversionService::class)->ensureAutomaticConversion($order));
        $this->assertNotEmpty(app(CommerceBuyerGstMintGate::class)->blockingReasonsForOrder($order->fresh()));
    }

    private function orderWithVerification(
        BuyerGstVerificationStatus $status,
        string $reviewReason,
        ?string $registrationStatus,
    ): CommerceOrder {
        $suffix = bin2hex(random_bytes(4));
        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-P20-23-'.$suffix,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => 'RBP-P20-23-'.$suffix,
            'source_order_id' => 'RBP-P20-23-'.$suffix,
            'idempotency_key' => 'test:p20-23:'.$suffix,
            'payload_hash' => hash('sha256', 'p20-23:'.$suffix),
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
            'verification_status' => $status,
            'registration_status' => $registrationStatus,
            'review_reason' => $reviewReason,
            'provider_payload_hash' => hash('sha256', 'p20-23-fixture'),
        ]);

        return $order->fresh();
    }
}
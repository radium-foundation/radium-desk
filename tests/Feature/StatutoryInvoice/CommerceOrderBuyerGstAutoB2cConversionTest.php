<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\BuyerGstB2cConversionStatus;
use App\Enums\BuyerGstVerificationStatus;
use App\Enums\CommerceOrderStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderBuyerGstB2cConversion;
use App\Models\CommerceOrderBuyerGstVerification;
use App\Services\StatutoryInvoice\CommerceOrderBuyerGstB2cConversionService;
use App\Services\StatutoryInvoice\ServiceStatutoryGstB2bClassification;
use App\Support\StatutoryInvoice\BuyerGstB2cConversionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommerceOrderBuyerGstAutoB2cConversionTest extends TestCase
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

    public function test_duplicate_automatic_conversion_is_idempotent(): void
    {
        $order = $this->fixtureOrderWithVerification();
        $service = app(CommerceOrderBuyerGstB2cConversionService::class);

        $first = $service->ensureAutomaticConversion($order);
        $second = $service->ensureAutomaticConversion($order->fresh());

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second?->id);
        $this->assertSame(1, CommerceOrderBuyerGstB2cConversion::query()->count());
    }

    public function test_fallback_disabled_preserves_gst_review_hold(): void
    {
        config(['statutory_invoices.buyer_gstin_verification.b2c_fallback.enabled' => false]);
        $order = $this->fixtureOrderWithVerification();

        $this->assertNull(app(CommerceOrderBuyerGstB2cConversionService::class)->ensureAutomaticConversion($order));
        $decision = app(ServiceStatutoryGstB2bClassification::class)->resolveForServiceOrder($order->fresh());
        $this->assertTrue($decision->requiresGstReview);
    }

    public function test_local_invalid_gstin_converts_without_verification_record(): void
    {
        $order = $this->commerceOrder(['buyer_gstin' => 'NOT-A-VALID-GSTIN']);

        $this->assertTrue(BuyerGstB2cConversionPolicy::localGstinBlocksB2b($order));
        $conversion = app(CommerceOrderBuyerGstB2cConversionService::class)->ensureAutomaticConversion($order);
        $this->assertNotNull($conversion);
        $this->assertSame(BuyerGstB2cConversionStatus::Approved, $conversion->status);
        $this->assertNull($conversion->commerce_order_buyer_gst_verification_id);

        $decision = app(ServiceStatutoryGstB2bClassification::class)->resolveForServiceOrder($order->fresh());
        $this->assertFalse($decision->issueAsB2b);
        $this->assertNull($decision->buyerGstinForMint);
    }

    private function fixtureOrderWithVerification(): CommerceOrder
    {
        $order = $this->commerceOrder();

        CommerceOrderBuyerGstVerification::query()->create([
            'commerce_order_id' => $order->id,
            'submitted_gstin' => '07AAICB5215F2ZX',
            'verification_status' => BuyerGstVerificationStatus::ReviewRequired,
            'registration_status' => 'Suspended',
            'review_reason' => 'inactive_registration',
            'provider_payload_hash' => hash('sha256', 'fixture'),
        ]);

        return $order->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function commerceOrder(array $overrides = []): CommerceOrder
    {
        $suffix = bin2hex(random_bytes(4));

        return CommerceOrder::query()->create(array_merge([
            'order_no' => 'CO-AUTO-B2C-'.$suffix,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => 'RBP-AUTO-'.$suffix,
            'source_order_id' => 'RBP-AUTO-'.$suffix,
            'idempotency_key' => 'test:auto-b2c:'.$suffix,
            'payload_hash' => hash('sha256', 'auto-b2c:'.$suffix),
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
        ], $overrides));
    }
}

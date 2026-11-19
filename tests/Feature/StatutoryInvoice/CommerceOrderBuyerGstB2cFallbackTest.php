<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\BuyerGstB2cConversionStatus;
use App\Enums\CommerceOrderStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderBuyerGstVerification;
use App\Models\User;
use App\Services\StatutoryInvoice\CommerceBuyerGstMintGate;
use App\Services\StatutoryInvoice\CommerceOrderBuyerGstB2cConversionService;
use App\Services\StatutoryInvoice\CommerceOrderBuyerGstVerificationService;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceMintRequest;
use App\Services\StatutoryInvoice\ServiceStatutoryGstB2bClassification;
use App\Support\StatutoryInvoice\BuyerGstB2cConversionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CommerceOrderBuyerGstB2cFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'statutory_invoices.buyer_gstin_verification.enabled' => true,
            'statutory_invoices.buyer_gstin_verification.provider' => 'whitebooks',
            'statutory_invoices.buyer_gstin_verification.b2c_fallback.enabled' => true,
            'statutory_invoices.einvoice.gsp_base_url' => 'https://api.whitebooks.in',
            'statutory_invoices.einvoice.gsp_client_id' => 'test-client',
            'statutory_invoices.einvoice.gsp_client_secret' => 'test-secret',
            'statutory_invoices.einvoice.gsp_email' => 'gsp@example.test',
        ]);
    }

    public function test_verified_active_registration_stays_b2b_without_conversion(): void
    {
        Http::fake([
            'https://api.whitebooks.in/public/search*' => Http::response([
                'data' => [
                    'gstin' => '07AAICB5215F2ZX',
                    'lgnm' => 'Active Co',
                    'sts' => 'Active',
                    'pradr' => ['addr' => ['bno' => '1', 'dst' => 'Delhi', 'stcd' => 'Delhi', 'pncd' => '110001']],
                ],
            ], 200),
        ]);

        $order = $this->commerceOrder();
        app(CommerceOrderBuyerGstVerificationService::class)->ensureSubmittedSnapshot($order);
        app(CommerceOrderBuyerGstVerificationService::class)->verifyAndPersist($order->fresh());

        $decision = app(ServiceStatutoryGstB2bClassification::class)->resolveForServiceOrder($order->fresh());
        $this->assertTrue($decision->issueAsB2b);
        $this->assertFalse($decision->requiresGstReview);
    }

    public function test_suspended_registration_requires_review_until_manual_approval(): void
    {
        $this->seedInactiveVerification('Suspended');

        $order = $this->commerceOrder();
        $decision = app(ServiceStatutoryGstB2bClassification::class)->resolveForServiceOrder($order->fresh());
        $this->assertTrue($decision->requiresGstReview);
        $this->assertNotEmpty(app(CommerceBuyerGstMintGate::class)->blockingReasonsForOrder($order->fresh()));
    }

    public function test_authorized_conversion_allows_b2c_mint_without_recipient_gstin(): void
    {
        $order = $this->seedInactiveVerification('Suspended');
        $user = User::factory()->create();

        $conversion = app(CommerceOrderBuyerGstB2cConversionService::class)->approve(
            $order->fresh(),
            $user,
            'Finance/CA authorized B2C for inactive registration.',
        );

        $this->assertSame(BuyerGstB2cConversionStatus::Approved, $conversion->status);
        $this->assertSame('07AAICB5215F2ZX', $conversion->submitted_gstin);
        $this->assertStringContainsString('07******2ZX', $conversion->invoice_remark);
        $this->assertStringNotContainsString('07AAICB5215F2ZX', $conversion->invoice_remark);

        $decision = app(ServiceStatutoryGstB2bClassification::class)->resolveForServiceOrder($order->fresh());
        $this->assertFalse($decision->issueAsB2b);
        $this->assertNull($decision->buyerGstinForMint);
        $this->assertFalse($decision->requiresGstReview);
        $this->assertSame($conversion->invoice_remark, $decision->c360Note());

        $this->assertEmpty(app(CommerceBuyerGstMintGate::class)->blockingReasonsForOrder($order->fresh()));
    }

    public function test_provider_timeout_is_not_eligible_for_conversion(): void
    {
        Http::fake([
            'https://api.whitebooks.in/public/search*' => fn () => throw new ConnectionException('timeout'),
        ]);

        $order = $this->commerceOrder();
        app(CommerceOrderBuyerGstVerificationService::class)->ensureSubmittedSnapshot($order);
        app(CommerceOrderBuyerGstVerificationService::class)->verifyAndPersist($order->fresh());

        $record = CommerceOrderBuyerGstVerification::query()->first();
        $this->assertFalse(BuyerGstB2cConversionPolicy::isEligibleForManualConversion($record));

        $user = User::factory()->create();
        $this->expectException(ValidationException::class);
        app(CommerceOrderBuyerGstB2cConversionService::class)->approve($order->fresh(), $user, 'Should fail');
    }

    public function test_provider_rate_limited_is_not_eligible_for_conversion(): void
    {
        Http::fake([
            'https://api.whitebooks.in/public/search*' => Http::response([], 429),
        ]);

        $order = $this->commerceOrder();
        app(CommerceOrderBuyerGstVerificationService::class)->ensureSubmittedSnapshot($order);
        app(CommerceOrderBuyerGstVerificationService::class)->verifyAndPersist($order->fresh());

        $record = CommerceOrderBuyerGstVerification::query()->first();
        $this->assertSame('provider_rate_limited', $record?->review_reason);
        $this->assertFalse(BuyerGstB2cConversionPolicy::isEligibleForManualConversion($record));
    }

    public function test_provider_5xx_is_not_eligible_for_conversion(): void
    {
        Http::fake([
            'https://api.whitebooks.in/public/search*' => Http::response('upstream error', 503),
        ]);

        $order = $this->commerceOrder();
        app(CommerceOrderBuyerGstVerificationService::class)->ensureSubmittedSnapshot($order);
        $record = app(CommerceOrderBuyerGstVerificationService::class)->verifyAndPersist($order->fresh());
        $this->assertContains($record?->review_reason, ['provider_unavailable', 'provider_error']);
        $this->assertFalse(BuyerGstB2cConversionPolicy::isEligibleForManualConversion($record));
    }

    public function test_conversion_requires_fallback_policy_enabled(): void
    {
        config(['statutory_invoices.buyer_gstin_verification.b2c_fallback.enabled' => false]);
        $order = $this->seedInactiveVerification('Cancelled');
        $user = User::factory()->create();

        $this->expectException(ValidationException::class);
        app(CommerceOrderBuyerGstB2cConversionService::class)->approve($order->fresh(), $user, 'NA');
    }

    public function test_duplicate_approval_is_idempotent(): void
    {
        $order = $this->seedInactiveVerification('Cancelled');
        $user = User::factory()->create();
        $service = app(CommerceOrderBuyerGstB2cConversionService::class);

        $first = $service->approve($order->fresh(), $user, 'First');
        $second = $service->approve($order->fresh(), $user, 'Second');

        $this->assertSame($first->id, $second->id);
        $this->assertSame('First', $second->decision_reason);
    }

    public function test_rejected_conversion_blocks_subsequent_approval(): void
    {
        $order = $this->seedInactiveVerification('Cancelled');
        $user = User::factory()->create();
        $service = app(CommerceOrderBuyerGstB2cConversionService::class);

        $service->reject($order->fresh(), $user, 'Hold for buyer contact');

        $this->expectException(ValidationException::class);
        $service->approve($order->fresh(), $user, 'Retry');
    }

    public function test_b2b_mint_request_blocked_after_authorized_conversion(): void
    {
        $order = $this->seedInactiveVerification('Suspended');
        $user = User::factory()->create();
        app(CommerceOrderBuyerGstB2cConversionService::class)->approve($order->fresh(), $user, 'OK');

        $request = new StatutoryInvoiceMintRequest(
            channel: $order->channel,
            sourceType: StatutoryInvoiceSourceType::CommerceOrder,
            sourceId: $order->source_id,
            lines: [],
            buyerGstin: '07AAICB5215F2ZX',
        );

        $reasons = app(CommerceBuyerGstMintGate::class)->blockingReasonsForMintRequest($request);
        $this->assertContains('B2B mint is blocked after authorized B2C conversion.', $reasons);
    }

    private function seedInactiveVerification(string $status): CommerceOrder
    {
        Http::fake([
            'https://api.whitebooks.in/public/search*' => Http::response([
                'data' => [
                    'gstin' => '07AAICB5215F2ZX',
                    'lgnm' => 'Inactive Co',
                    'sts' => $status,
                    'pradr' => ['addr' => ['bno' => '1', 'dst' => 'Delhi', 'stcd' => 'Delhi', 'pncd' => '110001']],
                ],
            ], 200),
        ]);

        $order = $this->commerceOrder();
        app(CommerceOrderBuyerGstVerificationService::class)->ensureSubmittedSnapshot($order);
        app(CommerceOrderBuyerGstVerificationService::class)->verifyAndPersist($order->fresh());

        return $order;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function commerceOrder(array $overrides = []): CommerceOrder
    {
        $suffix = $overrides['source_id'] ?? bin2hex(random_bytes(4));

        return CommerceOrder::query()->create(array_merge([
            'order_no' => 'CO-B2C-FALLBACK-'.$suffix,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => 'RBP-B2C-'.$suffix,
            'source_order_id' => 'RBP-B2C-'.$suffix,
            'idempotency_key' => 'test:b2c-fallback:'.$suffix,
            'payload_hash' => hash('sha256', 'b2c-fallback:'.$suffix),
            'status' => CommerceOrderStatus::Validated,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Buyer',
            'buyer_gstin' => '07AAICB5215F2ZX',
            'billing_address_structured' => [
                'state' => 'Rajasthan',
                'city' => 'Jodhpur',
                'pincode' => '304012',
            ],
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

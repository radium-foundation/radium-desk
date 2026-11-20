<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\BuyerGstB2cConversionStatus;
use App\Enums\BuyerGstVerificationStatus;
use App\Enums\CommerceOrderStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderBuyerGstB2cConversion;
use App\Models\CommerceOrderBuyerGstVerification;
use App\Models\StatutoryInvoice;
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
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('permanent_regression')]
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

    public function test_suspended_registration_converts_automatically_without_manual_approval(): void
    {
        $order = $this->seedInactiveVerification('Suspended');

        $decision = app(ServiceStatutoryGstB2bClassification::class)->resolveForServiceOrder($order->fresh());
        $this->assertFalse($decision->issueAsB2b);
        $this->assertNull($decision->buyerGstinForMint);
        $this->assertFalse($decision->requiresGstReview);
        $this->assertEmpty(app(CommerceBuyerGstMintGate::class)->blockingReasonsForOrder($order->fresh()));

        $conversion = CommerceOrderBuyerGstB2cConversion::query()->first();
        $this->assertNotNull($conversion);
        $this->assertNull($conversion->authorized_by);
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

    public function test_provider_timeout_holds_without_automatic_conversion(): void
    {
        Http::fake([
            'https://api.whitebooks.in/public/search*' => fn () => throw new ConnectionException('timeout'),
        ]);

        $order = $this->commerceOrder();
        app(CommerceOrderBuyerGstVerificationService::class)->ensureSubmittedSnapshot($order);
        app(CommerceOrderBuyerGstVerificationService::class)->verifyAndPersist($order->fresh());

        $record = CommerceOrderBuyerGstVerification::query()->first();
        $this->assertFalse(BuyerGstB2cConversionPolicy::isEligibleForAutomaticConversion($record, $order->fresh()));
        $this->assertTrue(BuyerGstB2cConversionPolicy::isEligibleForManualConversion($record, $order->fresh()));

        $this->assertNull(CommerceOrderBuyerGstB2cConversion::query()->first());
        $this->assertNotEmpty(app(CommerceBuyerGstMintGate::class)->blockingReasonsForOrder($order->fresh()));
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
        $this->assertFalse(BuyerGstB2cConversionPolicy::isEligibleForAutomaticConversion($record, $order->fresh()));
        $this->assertTrue(BuyerGstB2cConversionPolicy::isEligibleForManualConversion($record, $order->fresh()));
        $this->assertNull(CommerceOrderBuyerGstB2cConversion::query()->first());
    }

    public function test_provider_5xx_holds_without_automatic_conversion(): void
    {
        Http::fake([
            'https://api.whitebooks.in/public/search*' => Http::response('upstream error', 503),
        ]);

        $order = $this->commerceOrder();
        app(CommerceOrderBuyerGstVerificationService::class)->ensureSubmittedSnapshot($order);
        $record = app(CommerceOrderBuyerGstVerificationService::class)->verifyAndPersist($order->fresh());
        $this->assertContains($record?->review_reason, ['provider_unavailable', 'provider_error']);
        $this->assertFalse(BuyerGstB2cConversionPolicy::isEligibleForAutomaticConversion($record, $order->fresh()));
        $this->assertNull(CommerceOrderBuyerGstB2cConversion::query()->first());
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
        $order = $this->seedInactiveVerification('Cancelled', autoConvert: false);
        $user = User::factory()->create();
        $service = app(CommerceOrderBuyerGstB2cConversionService::class);

        $first = $service->approve($order->fresh(), $user, 'First');
        $second = $service->approve($order->fresh(), $user, 'Second');

        $this->assertSame($first->id, $second->id);
        $this->assertSame('First', $second->decision_reason);
    }

    public function test_rejected_conversion_blocks_subsequent_approval(): void
    {
        $order = $this->seedInactiveVerification('Cancelled', autoConvert: false);
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

    public function test_b2c_conversion_rejected_after_persisted_statutory_invoice_mint(): void
    {
        $order = $this->seedInactiveVerification('Suspended', autoConvert: false);
        $verificationBefore = CommerceOrderBuyerGstVerification::query()->firstOrFail();
        $verificationSnapshot = $verificationBefore->only([
            'submitted_gstin',
            'verification_status',
            'registration_status',
            'review_reason',
            'provider_payload_hash',
        ]);

        $invoice = $this->persistedCommerceStatutoryInvoice($order, buyerGstin: '07AAICB5215F2ZX');
        $invoiceSnapshot = $invoice->only(['buyer_gstin', 'source_id', 'status']);

        $user = User::factory()->create();
        try {
            app(CommerceOrderBuyerGstB2cConversionService::class)->approve($order->fresh(), $user, 'Too late');
            $this->fail('Expected conversion rejection after invoice mint.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'Cannot approve B2C conversion after statutory invoice issuance.',
                implode(' ', $exception->validator->errors()->all()),
            );
        }

        $this->assertSame(0, CommerceOrderBuyerGstB2cConversion::query()->count());
        $verificationAfter = CommerceOrderBuyerGstVerification::query()->firstOrFail();
        $this->assertSame($verificationSnapshot, $verificationAfter->only(array_keys($verificationSnapshot)));

        $freshInvoice = StatutoryInvoice::query()->findOrFail($invoice->id);
        $this->assertSame($invoiceSnapshot['buyer_gstin'], $freshInvoice->buyer_gstin);
        $this->assertSame($invoiceSnapshot['status'], $freshInvoice->status);
    }

    public function test_provisional_registration_stays_b2b_and_is_not_conversion_eligible(): void
    {
        Http::fake([
            'https://api.whitebooks.in/public/search*' => Http::response([
                'data' => [
                    'gstin' => '07AAICB5215F2ZX',
                    'lgnm' => 'Provisional Co',
                    'sts' => 'Provisional',
                    'pradr' => ['addr' => ['bno' => '1', 'dst' => 'Delhi', 'stcd' => 'Delhi', 'pncd' => '110001']],
                ],
            ], 200),
        ]);

        $order = $this->commerceOrder();
        app(CommerceOrderBuyerGstVerificationService::class)->ensureSubmittedSnapshot($order);
        $record = app(CommerceOrderBuyerGstVerificationService::class)->verifyAndPersist($order->fresh());
        $this->assertSame(BuyerGstVerificationStatus::Verified, $record?->verification_status);

        $decision = app(ServiceStatutoryGstB2bClassification::class)->resolveForServiceOrder($order->fresh());
        $this->assertTrue($decision->issueAsB2b);
        $this->assertFalse(BuyerGstB2cConversionPolicy::isEligibleForAutomaticConversion($record, $order->fresh()));
    }

    public function test_malformed_provider_response_holds_until_exceptional_manual_b2c(): void
    {
        Http::fake([
            'https://api.whitebooks.in/public/search*' => Http::response('not-json', 200),
        ]);

        $order = $this->commerceOrder();
        app(CommerceOrderBuyerGstVerificationService::class)->ensureSubmittedSnapshot($order);
        $record = app(CommerceOrderBuyerGstVerificationService::class)->verifyAndPersist($order->fresh());
        $this->assertSame('provider_malformed_response', $record?->review_reason);

        $this->assertNull(CommerceOrderBuyerGstB2cConversion::query()->first());

        $decision = app(ServiceStatutoryGstB2bClassification::class)->resolveForServiceOrder($order->fresh());
        $this->assertTrue($decision->requiresGstReview);

        $user = User::factory()->create();
        $conversion = app(CommerceOrderBuyerGstB2cConversionService::class)->approve(
            $order->fresh(),
            $user,
            'Exceptional manual B2C after malformed provider response.',
        );
        $this->assertStringContainsString('unusable response', $conversion->invoice_remark);
    }

    public function test_provider_error_payload_holds_without_automatic_conversion(): void
    {
        Http::fake([
            'https://api.whitebooks.in/public/search*' => Http::response([
                'error' => 'Invalid auth token',
            ], 401),
        ]);

        $order = $this->commerceOrder();
        app(CommerceOrderBuyerGstVerificationService::class)->ensureSubmittedSnapshot($order);
        $record = app(CommerceOrderBuyerGstVerificationService::class)->verifyAndPersist($order->fresh());
        $this->assertSame('provider_error', $record?->review_reason);
        $this->assertNull(CommerceOrderBuyerGstB2cConversion::query()->first());
    }

    public function test_invalid_gstin_response_converts_on_verify_without_manual_approval(): void
    {
        Http::fake([
            'https://api.whitebooks.in/public/search*' => Http::response([
                'data' => [
                    'gstin' => 'INVALID',
                    'lgnm' => 'Bad',
                    'sts' => 'Active',
                    'pradr' => ['addr' => ['bno' => '1', 'dst' => 'Delhi', 'stcd' => 'Delhi', 'pncd' => '110001']],
                ],
            ], 200),
        ]);

        $order = $this->commerceOrder();
        app(CommerceOrderBuyerGstVerificationService::class)->ensureSubmittedSnapshot($order);
        $record = app(CommerceOrderBuyerGstVerificationService::class)->verifyAndPersist($order->fresh());
        $this->assertSame('invalid_gstin_response', $record?->review_reason);

        $conversion = CommerceOrderBuyerGstB2cConversion::query()->first();
        $this->assertNotNull($conversion);
        $this->assertSame('07AAICB5215F2ZX', $conversion->submitted_gstin);
        $this->assertNull($conversion->authorized_by);
    }

    public function test_order_retains_submitted_gstin_after_authorized_conversion(): void
    {
        $order = $this->seedInactiveVerification('Cancelled');
        $user = User::factory()->create();
        app(CommerceOrderBuyerGstB2cConversionService::class)->approve($order->fresh(), $user, 'OK');

        $this->assertSame('07AAICB5215F2ZX', $order->fresh()->buyer_gstin);
    }

    public function test_conversion_audit_records_approver_and_provider_hash(): void
    {
        $order = $this->seedInactiveVerification('Suspended', autoConvert: false);
        $user = User::factory()->create(['name' => 'Finance Approver']);
        $verification = CommerceOrderBuyerGstVerification::query()->firstOrFail();

        $conversion = app(CommerceOrderBuyerGstB2cConversionService::class)->approve(
            $order->fresh(),
            $user,
            'Documented CA authorization ref TEST-001',
        );

        $this->assertSame($user->id, $conversion->authorized_by);
        $this->assertNotNull($conversion->authorized_at);
        $this->assertSame($verification->id, $conversion->commerce_order_buyer_gst_verification_id);
        $this->assertSame($verification->provider_payload_hash, $conversion->provider_payload_hash);
        $this->assertSame('Documented CA authorization ref TEST-001', $conversion->decision_reason);
    }

    private function persistedCommerceStatutoryInvoice(CommerceOrder $order, ?string $buyerGstin): StatutoryInvoice
    {
        return StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-GST-B2C-'.bin2hex(random_bytes(3)),
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => $order->channel,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => $order->source_id,
            'idempotency_key' => 'statutory:gst-b2c-post-mint:'.bin2hex(random_bytes(4)),
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Phil Technologies (P) Limited',
            'buyer_name' => $order->customer_name,
            'buyer_gstin' => $buyerGstin,
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

    private function seedInactiveVerification(string $status, bool $autoConvert = true): CommerceOrder
    {
        $order = $this->commerceOrder();

        if (! $autoConvert) {
            config(['statutory_invoices.buyer_gstin_verification.enabled' => false]);
            CommerceOrderBuyerGstVerification::query()->create([
                'commerce_order_id' => $order->id,
                'submitted_gstin' => '07AAICB5215F2ZX',
                'verification_status' => BuyerGstVerificationStatus::ReviewRequired,
                'registration_status' => $status,
                'review_reason' => 'inactive_registration',
                'provider_payload_hash' => hash('sha256', 'b2c-fallback-manual-fixture'),
            ]);
            config(['statutory_invoices.buyer_gstin_verification.enabled' => true]);

            return $order->fresh();
        }

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

        app(CommerceOrderBuyerGstVerificationService::class)->ensureSubmittedSnapshot($order);
        app(CommerceOrderBuyerGstVerificationService::class)->verifyAndPersist($order->fresh());

        return $order->fresh();
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

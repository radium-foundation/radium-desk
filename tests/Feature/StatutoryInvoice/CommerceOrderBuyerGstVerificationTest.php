<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\BuyerGstVerificationStatus;
use App\Enums\CommerceOrderStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderBuyerGstVerification;
use App\Services\StatutoryInvoice\CommerceBuyerGstMintGate;
use App\Services\StatutoryInvoice\CommerceOrderBillToResolver;
use App\Services\StatutoryInvoice\CommerceOrderBuyerGstVerificationService;
use App\Services\StatutoryInvoice\ServiceStatutoryGstB2bClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CommerceOrderBuyerGstVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'statutory_invoices.buyer_gstin_verification.enabled' => true,
            'statutory_invoices.buyer_gstin_verification.provider' => 'whitebooks',
            'statutory_invoices.einvoice.gsp_base_url' => 'https://api.whitebooks.in',
            'statutory_invoices.einvoice.gsp_client_id' => 'test-client',
            'statutory_invoices.einvoice.gsp_client_secret' => 'test-secret',
            'statutory_invoices.einvoice.gsp_email' => 'gsp@example.test',
        ]);
    }

    public function test_state_mismatch_resolves_with_verified_bill_to_without_touching_ship_to(): void
    {
        $shipping = [
            'line1' => 'JUANA-303',
            'city' => 'Jodhpur',
            'state' => 'Rajasthan',
            'pincode' => '304012',
        ];
        $order = $this->commerceOrder([
            'buyer_gstin' => '07AAICB5215F2ZX',
            'billing_address_structured' => $shipping,
            'shipping_address_structured' => $shipping,
            'place_of_supply_state' => 'Rajasthan',
        ]);

        $fixture = json_decode(
            (string) file_get_contents(base_path('tests/fixtures/buyer-gstin/rbp2295-whitebooks-search-response.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        Http::fake([
            'https://api.whitebooks.in/public/search*' => Http::response($fixture, 200),
        ]);

        app(CommerceOrderBuyerGstVerificationService::class)->ensureSubmittedSnapshot($order);
        $record = app(CommerceOrderBuyerGstVerificationService::class)->verifyAndPersist($order->fresh());
        $this->assertNotNull($record);
        $this->assertSame(BuyerGstVerificationStatus::Verified, $record->verification_status);
        $this->assertSame('Delhi', $record->bill_to_address_structured['state'] ?? null);

        $orderFresh = $order->fresh();
        $this->assertSame($shipping, $orderFresh->shipping_address_structured);
        $this->assertSame('Rajasthan', $orderFresh->billing_address_structured['state'] ?? null);

        $decision = app(ServiceStatutoryGstB2bClassification::class)->resolveForServiceOrder($orderFresh);
        $this->assertTrue($decision->issueAsB2b);
        $this->assertFalse($decision->requiresGstReview);

        $billTo = app(CommerceOrderBillToResolver::class)->resolveForMint($orderFresh);
        $this->assertSame('Delhi', $billTo['structured']['state'] ?? null);
        $this->assertEmpty(app(CommerceBuyerGstMintGate::class)->blockingReasonsForOrder($orderFresh));
    }

    public function test_provider_timeout_keeps_pending_without_treating_gstin_valid(): void
    {
        Http::fake([
            'https://api.whitebooks.in/public/search*' => fn () => throw new ConnectionException('timeout'),
        ]);

        $order = $this->commerceOrder([
            'buyer_gstin' => '07AAICB5215F2ZX',
            'billing_address_structured' => [
                'state' => 'Rajasthan',
                'city' => 'Jodhpur',
                'pincode' => '304012',
            ],
        ]);

        app(CommerceOrderBuyerGstVerificationService::class)->ensureSubmittedSnapshot($order);
        $record = app(CommerceOrderBuyerGstVerificationService::class)->verifyAndPersist($order->fresh());
        $this->assertSame(BuyerGstVerificationStatus::Pending, $record?->verification_status);

        $decision = app(ServiceStatutoryGstB2bClassification::class)->resolveForServiceOrder($order->fresh());
        $this->assertTrue($decision->requiresGstReview);
        $this->assertNotEmpty(app(CommerceBuyerGstMintGate::class)->blockingReasonsForOrder($order->fresh()));
    }

    public function test_inactive_registration_routes_to_gst_review(): void
    {
        Http::fake([
            'https://api.whitebooks.in/public/search*' => Http::response([
                'data' => [
                    'gstin' => '07AAICB5215F2ZX',
                    'lgnm' => 'Example Co',
                    'sts' => 'Cancelled',
                    'pradr' => [
                        'addr' => [
                            'bno' => '1',
                            'st' => 'Main',
                            'dst' => 'Delhi',
                            'stcd' => 'Delhi',
                            'pncd' => '110001',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $order = $this->commerceOrder(['buyer_gstin' => '07AAICB5215F2ZX']);
        app(CommerceOrderBuyerGstVerificationService::class)->ensureSubmittedSnapshot($order);
        $record = app(CommerceOrderBuyerGstVerificationService::class)->verifyAndPersist($order->fresh());
        $this->assertSame(BuyerGstVerificationStatus::ReviewRequired, $record?->verification_status);
        $this->assertSame('inactive_registration', $record?->review_reason);
    }

    public function test_verification_is_idempotent_for_same_order(): void
    {
        Http::fake([
            'https://api.whitebooks.in/public/search*' => Http::response([
                'data' => [
                    'gstin' => '09AAJFV1437D1Z7',
                    'lgnm' => 'Match Co',
                    'sts' => 'Active',
                    'pradr' => [
                        'addr' => [
                            'bno' => '1',
                            'dst' => 'Lucknow',
                            'stcd' => 'Uttar Pradesh',
                            'pncd' => '226001',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $order = $this->commerceOrder([
            'buyer_gstin' => '09AAJFV1437D1Z7',
            'billing_address_structured' => [
                'state' => 'Uttar Pradesh',
                'city' => 'Lucknow',
                'pincode' => '226001',
            ],
        ]);

        $service = app(CommerceOrderBuyerGstVerificationService::class);
        $service->ensureSubmittedSnapshot($order);
        $first = $service->verifyAndPersist($order->fresh());
        $second = $service->verifyAndPersist($order->fresh());
        $this->assertSame($first?->id, $second?->id);
        $this->assertSame(1, CommerceOrderBuyerGstVerification::query()->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function commerceOrder(array $overrides = []): CommerceOrder
    {
        return CommerceOrder::query()->create(array_merge([
            'order_no' => 'CO-TEST',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => 'RBP2295',
            'source_order_id' => 'RBP2295',
            'idempotency_key' => 'test:rbp2295',
            'payload_hash' => hash('sha256', 'rbp2295'),
            'status' => CommerceOrderStatus::Validated,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Buyer',
            'billing_state' => 'Rajasthan',
            'order_value' => 100,
            'taxable_value' => 84.75,
            'tax_total' => 15.25,
            'ordered_at' => now(),
            'received_at' => now(),
        ], $overrides));
    }
}

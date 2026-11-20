<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\BuyerGstVerificationStatus;
use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderBuyerGstB2cConversion;
use App\Models\CommerceOrderBuyerGstVerification;
use App\Models\HardwareFulfilment;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventoryUserBranch;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\HardwareFulfilment\HardwareFulfilmentInvoiceService;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use App\Services\HardwareFulfilment\HardwareSerialAllocationService;
use App\Services\HardwareFulfilment\HardwareStatutoryInvoiceIssuer;
use App\Services\Inventory\InventoryStockService;
use App\Services\StatutoryInvoice\CommerceBuyerGstMintGate;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * P-20-20 — Hardware statutory mint must enforce CommerceBuyerGstMintGate before mint.
 */
#[Group('permanent_regression')]
class HardwareCommerceBuyerGstMintGateTest extends TestCase
{
    use RefreshDatabase;

    private const BOX_SECRET = 'test-radiumbox-secret';

    private StatutoryInvoiceService $invoices;

    private HardwareSerialAllocationService $allocation;

    private HardwareFulfilmentWorkflowService $workflow;

    private User $actor;

    private InventoryProduct $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        $this->configureLocationSellerIdentity();
        config([
            'channel_ingest.secrets.radiumbox_com' => self::BOX_SECRET,
            'channel_ingest.auto_issue_invoice' => false,
            'hardware_fulfilment.correlate_cashfree' => false,
            'hardware_fulfilment.sku_map' => [],
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
            'statutory_invoices.invoice_scope_starts_at' => '2026-09-01 00:00:00',
            'statutory_invoices.buyer_gstin_verification.enabled' => true,
            'statutory_invoices.buyer_gstin_verification.b2c_fallback.enabled' => false,
        ]);

        Http::fake();

        $this->invoices = app(StatutoryInvoiceService::class);
        $this->allocation = app(HardwareSerialAllocationService::class);
        $this->workflow = app(HardwareFulfilmentWorkflowService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->product = InventoryProduct::query()->create([
            'sku' => 'DESK-P20-20-HW',
            'name' => 'P-20-20 hardware GST gate product',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 3049,
            'is_serialized' => true,
            'is_active' => true,
        ]);
    }

    public function test_hardware_manual_issue_blocked_when_gst_review_required(): void
    {
        $fulfilment = $this->serialsAllocatedWithGstHold('RBP-P20-20-01', '07AAICB5215F2ZX');
        $order = $fulfilment->commerceOrder;

        try {
            $this->invoices->issueFromCommerceOrder($order->fresh(['items']), $this->actor);
            $this->fail('Hardware Finance path must not mint when GST review is required.');
        } catch (ValidationException $exception) {
            $this->assertContains(
                'Buyer GSTIN requires GST review.',
                $exception->errors()['eligibility'] ?? [],
            );
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(0, CommerceOrderBuyerGstB2cConversion::query()->count());
    }

    public function test_hardware_issue_invoice_service_blocked_when_gst_review_required(): void
    {
        $fulfilment = $this->serialsAllocatedWithGstHold('RBP-P20-20-02', '07AAICB5215F2ZX');

        try {
            app(HardwareFulfilmentInvoiceService::class)->issueInvoice($fulfilment->fresh(['commerceOrder.items']), $this->actor);
            $this->fail('HardwareFulfilmentInvoiceService must not mint when GST review is required.');
        } catch (ValidationException $exception) {
            $this->assertContains(
                'Buyer GSTIN requires GST review.',
                $exception->errors()['eligibility'] ?? [],
            );
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_hardware_post_serial_auto_issue_blocked_when_gst_review_required(): void
    {
        $fulfilment = $this->readyFulfilment('RBP-P20-20-03', 'DELHI-RETAIL', 1, '07AAICB5215F2ZX');
        $order = $fulfilment->commerceOrder;
        $this->attachGstReviewHold($order, '07AAICB5215F2ZX', 'provider_timeout', null);
        $this->stockAt('DELHI-RETAIL', ['SN-P20-20-03']);

        $allocated = $this->allocation->allocateSerials($fulfilment->fresh(), ['SN-P20-20-03'], $this->actor);

        $this->assertNull($allocated->statutory_invoice_id);
        $this->assertSame(HardwareFulfilmentState::SerialsAllocated, $allocated->fresh()->state);
        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_hardware_statutory_issuer_auto_path_blocked_when_gst_review_required(): void
    {
        $fulfilment = $this->serialsAllocatedWithGstHold('RBP-P20-20-04', '07AAICB5215F2ZX');

        app(HardwareStatutoryInvoiceIssuer::class)->issueAfterSerialsAllocated($fulfilment->fresh(), $this->actor);

        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(HardwareFulfilmentState::SerialsAllocated, $fulfilment->fresh()->state);
    }

    public function test_finance_hub_http_issue_matches_authoritative_gate(): void
    {
        $fulfilment = $this->serialsAllocatedWithGstHold('RBP-P20-20-05', '07AAICB5215F2ZX');
        $order = $fulfilment->commerceOrder;

        $this->actingAs($this->actor)
            ->from(route('finance.invoices.commerce-orders.show', $order))
            ->post(route('finance.invoices.commerce-orders.issue', $order))
            ->assertRedirect(route('finance.invoices.commerce-orders.show', $order))
            ->assertSessionHasErrors('eligibility');

        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_hardware_b2b_mints_when_verified_and_gate_clear(): void
    {
        $fulfilment = $this->readyFulfilment('RBP-P20-20-06', 'DELHI-RETAIL', 1, '09AAJFV1437D1Z7');
        $order = $fulfilment->commerceOrder;
        $order->forceFill([
            'buyer_gstin' => '09AAJFV1437D1Z7',
            'billing_state' => 'Rajasthan',
            'billing_address_structured' => [
                'line1' => 'JUANA-303',
                'city' => 'Jodhpur',
                'state' => 'Rajasthan',
                'pincode' => '304012',
            ],
        ])->save();
        CommerceOrderBuyerGstVerification::query()->create([
            'commerce_order_id' => $order->id,
            'submitted_gstin' => '09AAJFV1437D1Z7',
            'verification_status' => BuyerGstVerificationStatus::Verified,
            'verification_source' => 'whitebooks',
            'bill_to_address_structured' => [
                'line1' => '1 Main',
                'city' => 'Lucknow',
                'state' => 'Uttar Pradesh',
                'pincode' => '226001',
            ],
        ]);
        $this->stockAt('DELHI-RETAIL', ['SN-P20-20-06']);

        $this->assertEmpty(app(CommerceBuyerGstMintGate::class)->blockingReasonsForOrder($order->fresh()));

        $allocated = $this->allocation->allocateSerials($fulfilment->fresh(), ['SN-P20-20-06'], $this->actor);
        $this->assertNotNull($allocated->statutory_invoice_id);

        $invoice = StatutoryInvoice::query()->findOrFail($allocated->statutory_invoice_id);
        $this->assertSame('09AAJFV1437D1Z7', $invoice->buyer_gstin);
        $this->assertSame(1, StatutoryInvoice::query()->count());
    }

    public function test_hardware_missing_gstin_native_b2c_not_blocked_by_b2b_gate(): void
    {
        $fulfilment = $this->readyFulfilment('RBP-P20-20-07', 'DELHI-RETAIL', 1, null);
        $order = $fulfilment->commerceOrder;
        $this->assertNull($order->buyer_gstin);
        $this->stockAt('DELHI-RETAIL', ['SN-P20-20-07']);
        $this->allocation->allocateSerials($fulfilment->fresh(), ['SN-P20-20-07'], $this->actor);

        $invoice = StatutoryInvoice::query()->firstOrFail();

        $this->assertNull($invoice->buyer_gstin);
        $this->assertSame(0, CommerceOrderBuyerGstVerification::query()->where('commerce_order_id', $order->id)->count());
        Http::assertNothingSent();
    }

    public function test_provider_pending_blocks_mint_without_b2c_conversion(): void
    {
        $gstin = '07AAICB5215F2ZX';
        $fulfilment = $this->readyFulfilment('RBP-P20-20-08', 'DELHI-RETAIL', 1, $gstin);
        $order = $fulfilment->commerceOrder;
        $this->ensureProviderVerificationRequired($order);
        CommerceOrderBuyerGstVerification::query()->create([
            'commerce_order_id' => $order->id,
            'submitted_gstin' => $gstin,
            'verification_status' => BuyerGstVerificationStatus::Pending,
            'verification_source' => 'whitebooks',
        ]);
        $this->stockAt('DELHI-RETAIL', ['SN-P20-20-08']);
        $this->allocation->allocateSerials($fulfilment->fresh(), ['SN-P20-20-08'], $this->actor);

        try {
            $this->invoices->issueFromCommerceOrder($order->fresh(['items']), $this->actor);
            $this->fail('Pending provider verification must block mint.');
        } catch (ValidationException $exception) {
            $this->assertContains(
                'Buyer GSTIN verification is pending.',
                $exception->errors()['eligibility'] ?? [],
            );
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(0, CommerceOrderBuyerGstB2cConversion::query()->count());
    }

    public function test_hardware_inactive_registration_auto_b2c_mints_blank_buyer_gstin_with_verification_on(): void
    {
        config(['statutory_invoices.buyer_gstin_verification.b2c_fallback.enabled' => false]);

        $fulfilment = $this->readyFulfilment('RBP-P20-23-HW', 'DELHI-RETAIL', 1, '07AAICB5215F2ZX');
        $order = $fulfilment->commerceOrder;
        $this->attachReviewRequiredVerification($order, '07AAICB5215F2ZX');
        $this->stockAt('DELHI-RETAIL', ['SN-P20-23-HW']);

        $allocated = $this->allocation->allocateSerials($fulfilment->fresh(), ['SN-P20-23-HW'], $this->actor);
        $this->assertNotNull($allocated->statutory_invoice_id);

        $invoice = StatutoryInvoice::query()->findOrFail($allocated->statutory_invoice_id);
        $this->assertNull($invoice->buyer_gstin);
        $this->assertSame('07AAICB5215F2ZX', $order->fresh()->buyer_gstin);

        $conversion = CommerceOrderBuyerGstB2cConversion::query()->firstOrFail();
        $this->assertNull($conversion->authorized_by);
        $this->assertSame($invoice->id, $conversion->statutory_invoice_id);
    }

    public function test_repeated_blocked_attempts_remain_idempotent(): void
    {
        $fulfilment = $this->serialsAllocatedWithGstHold('RBP-P20-20-09', '07AAICB5215F2ZX');
        $order = $fulfilment->commerceOrder;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $this->invoices->issueFromCommerceOrder($order->fresh(['items']), $this->actor);
                $this->fail('Expected block on attempt '.($attempt + 1));
            } catch (ValidationException) {
                // expected
            }
        }

        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_service_commerce_path_still_uses_assert_order_can_mint(): void
    {
        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-RD-P20-20',
            'channel' => StatutoryInvoiceChannel::RdServiceIn,
            'source_type' => 'commerce_order',
            'source_id' => 'RD3520001',
            'source_order_id' => 'RD3520001',
            'idempotency_key' => 'statutory:rdservice_in:commerce_order:RD3520001',
            'payload_hash' => hash('sha256', 'RD3520001'),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Service Buyer',
            'buyer_gstin' => null,
            'billing_state' => 'Delhi',
            'branch_code' => 'DELHI-RETAIL',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 100,
            'tax_total' => 18,
            'order_value' => 118,
            'ordered_at' => '2026-09-01 10:00:00',
            'received_at' => now(),
        ]);
        $order->items()->create([
            'line_no' => 1,
            'description' => 'Information technology (IT) consulting & support services',
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => 100,
            'gst_percentage' => 18,
            'taxable_value' => 100,
            'tax_total' => 18,
            'line_total' => 118,
        ]);

        $invoice = $this->invoices->issueFromCommerceOrder($order->fresh(['items']), $this->actor);

        $this->assertSame(1, StatutoryInvoice::query()->count());
        $this->assertNull($invoice->buyer_gstin);
    }

    private function attachReviewRequiredVerification(CommerceOrder $order, string $gstin): void
    {
        $this->attachGstReviewHold($order, $gstin, 'inactive_registration', 'Suspended');
    }

    private function attachGstReviewHold(
        CommerceOrder $order,
        string $gstin,
        string $reviewReason,
        ?string $registrationStatus = null,
    ): void {
        $this->ensureProviderVerificationRequired($order);

        CommerceOrderBuyerGstVerification::query()->create([
            'commerce_order_id' => $order->id,
            'submitted_gstin' => $gstin,
            'verification_status' => BuyerGstVerificationStatus::ReviewRequired,
            'verification_source' => 'whitebooks',
            'review_reason' => $reviewReason,
            'registration_status' => $registrationStatus,
        ]);
    }

    private function ensureProviderVerificationRequired(CommerceOrder $order): void
    {
        $order->forceFill([
            'billing_state' => 'Rajasthan',
            'billing_address_structured' => [
                'line1' => 'JUANA-303',
                'city' => 'Jodhpur',
                'state' => 'Rajasthan',
                'pincode' => '304012',
            ],
        ])->save();
    }

    private function serialsAllocatedWithGstHold(string $sourceId, string $buyerGstin): HardwareFulfilment
    {
        $fulfilment = $this->readyFulfilment($sourceId, 'DELHI-RETAIL', 1, $buyerGstin);
        $this->attachGstReviewHold($fulfilment->commerceOrder, $buyerGstin, 'provider_timeout', null);
        $this->stockAt('DELHI-RETAIL', ['SN-'.$sourceId]);

        return $this->allocation->allocateSerials($fulfilment->fresh(), ['SN-'.$sourceId], $this->actor)
            ->fresh(['commerceOrder.items']) ?? $fulfilment;
    }

    private function readyFulfilment(
        string $sourceId,
        string $branchCode,
        int $qty,
        ?string $buyerGstin = null,
    ): HardwareFulfilment {
        $this->mapModel(951);
        $fulfilment = $this->ingestHardware($sourceId, $qty, $buyerGstin);
        $this->assignBranch($fulfilment, $branchCode);
        $this->workflow->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);

        return $fulfilment->fresh(['commerceOrder.items']) ?? $fulfilment;
    }

    /**
     * @param  list<string>  $serials
     */
    private function stockAt(string $branchCode, array $serials): InventoryBranch
    {
        $branch = $this->assignBranch(new HardwareFulfilment, $branchCode);
        app(InventoryStockService::class)->stockInSerialized($this->product, $branch, $serials, $this->actor);

        return $branch;
    }

    private function assignBranch(?HardwareFulfilment $fulfilment, string $code): InventoryBranch
    {
        $branch = InventoryBranch::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $code, 'is_active' => true],
        );
        InventoryUserBranch::query()->firstOrCreate([
            'user_id' => $this->actor->id,
            'branch_id' => $branch->id,
        ]);
        if ($fulfilment?->exists) {
            $fulfilment->forceFill(['fulfilment_branch_id' => $branch->id])->save();
        }

        return $branch;
    }

    private function mapModel(int $modelId): void
    {
        ChannelSkuMap::query()->firstOrCreate(
            [
                'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
                'model_id' => $modelId,
            ],
            [
                'inventory_product_id' => $this->product->id,
                'catalog_sku' => 'MSO1300',
                'channel_sku' => '951',
                'notes' => 'P-20-20 test map',
            ],
        );
    }

    private function ingestHardware(string $sourceId, int $qty = 1, ?string $buyerGstin = null): HardwareFulfilment
    {
        $taxable = round(2583.90 * $qty, 2);
        $tax = round($taxable * 0.18, 2);
        $lineTotal = round($taxable + $tax, 2);
        $payload = [
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom->value,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'payment_status' => 'paid',
            'payment_provider' => 'cashfree',
            'payment_reference' => 'pay_'.$sourceId,
            'currency' => 'INR',
            'customer' => [
                'name' => 'Hardware Buyer',
                'phone' => '9000000099',
                'gstin' => $buyerGstin,
            ],
            'seller_gstin' => '07AAICP1128M1Z9',
            'place_of_supply_state' => 'Delhi',
            'lines' => [[
                'description' => 'MSO1300',
                'sku' => '951',
                'qty' => $qty,
                'unit_price' => 3049,
                'hsn_sac' => '84716050',
                'gst_percentage' => 18,
                'taxable_value' => $taxable,
                'tax_total' => $tax,
                'line_total' => $lineTotal,
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
}
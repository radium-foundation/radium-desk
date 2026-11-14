<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentPaymentEvidence;
use App\Models\InventoryProduct;
use App\Models\CommerceOrderItem;
use App\Models\Order;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\ChannelIngest\Data\ChannelOrderIngestRequest;
use App\Services\ChannelIngest\Data\ChannelOrderLineDraft;
use App\Services\HardwareFulfilment\Data\HardwarePaymentEvidenceDraft;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\HardwareFulfilment\HardwareFulfilmentFoundationService;
use App\Services\HardwareFulfilment\HardwareFulfilmentIsolatedWorkflowService;
use App\Services\HardwareFulfilment\HardwareFulfilmentPaymentCorrelationService;
use App\Services\HardwareFulfilment\HardwareRecoveredFulfilmentAuthorization;
use App\Services\HardwareFulfilment\RbpHardwareFulfilmentGapMonitor;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HardwareRbpFulfilmentRegressionTest extends TestCase
{
    use RefreshDatabase;

    private const BOX_SECRET = 'test-radiumbox-secret';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config([
            'channel_ingest.secrets.radiumbox_com' => self::BOX_SECRET,
            'channel_ingest.auto_issue_invoice' => false,
            'hardware_fulfilment.correlate_cashfree' => false,
        ]);

        $product = InventoryProduct::query()->create([
            'sku' => 'RBMFS110L1',
            'name' => 'MFS110',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 2499,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        ChannelSkuMap::query()->create([
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'model_id' => 946,
            'inventory_product_id' => $product->id,
            'catalog_sku' => 'RBMFS110L1',
            'channel_sku' => '946',
        ]);
    }

    public function test_cancelled_metadata_rbp_ingest_does_not_open_fulfilment(): void
    {
        $payload = $this->eligibleBoxPayload('RBP920001');
        $payload['metadata'] = ['order_status' => 'cancelled'];

        $this->signedBoxPost($payload)->assertCreated();
        $this->assertSame(1, CommerceOrder::query()->count());
        $this->assertSame(0, HardwareFulfilment::query()->count());
    }

    public function test_cancelled_metadata_retry_is_idempotent_and_still_has_no_fulfilment(): void
    {
        $payload = $this->eligibleBoxPayload('RBP920002');
        $payload['metadata'] = ['radiumbox_order_status' => 'Order Cancelled'];

        $this->signedBoxPost($payload)->assertCreated();
        $this->signedBoxPost($payload)->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame(1, CommerceOrder::query()->count());
        $this->assertSame(0, HardwareFulfilment::query()->count());
    }

    public function test_failed_commerce_status_blocks_fulfilment_on_duplicate_ingest_retry(): void
    {
        $payload = $this->eligibleBoxPayload('RBP920003');
        $this->signedBoxPost($payload)->assertCreated();
        $this->assertSame(1, HardwareFulfilment::query()->count());

        CommerceOrder::query()->firstOrFail()->update([
            'status' => CommerceOrderStatus::Failed,
        ]);
        HardwareFulfilment::query()->delete();

        $this->signedBoxPost($payload)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame(0, HardwareFulfilment::query()->count());
    }

    public function test_cashfree_tender_reference_mismatch_rejects_rbp_ingest(): void
    {
        $sourceId = 'RBP920004';
        Order::query()->create([
            'order_id' => $sourceId,
            'product_name' => 'MFS110',
            'status' => 'active',
            'cashfree_payment_id' => 'cf_pay_expected',
            'payment_amount' => 2499,
        ]);

        $payload = $this->eligibleBoxPayload($sourceId);
        $payload['tenders'] = [
            ['type' => 'cashfree', 'amount' => 2499, 'reference' => 'cf_pay_wrong'],
        ];

        $response = $this->signedBoxPost($payload);
        $this->assertSame(422, $response->getStatusCode(), (string) $response->getContent());

        $this->assertSame(0, CommerceOrder::query()->count());
        $this->assertSame(0, HardwareFulfilment::query()->count());
    }

    public function test_payment_evidence_for_unknown_rbp_source_does_not_create_fulfilment(): void
    {
        $this->signedBoxPost($this->eligibleBoxPayload('RBP920005'))->assertCreated();

        $correlation = app(HardwareFulfilmentPaymentCorrelationService::class);
        $correlation->recordPaidEvidence(new HardwarePaymentEvidenceDraft(
            sourceId: 'RBP920099',
            cashfreePaymentId: 'cf_pay_orphan',
            paymentStatus: HardwareFulfilmentPaymentCorrelationService::VERIFIED_STATUS,
        ));

        $this->assertSame(1, HardwareFulfilment::query()->count());
        $this->assertSame('RBP920005', HardwareFulfilment::query()->value('source_id'));
        $this->assertNull(
            HardwareFulfilmentPaymentEvidence::query()
                ->where('cashfree_payment_id', 'cf_pay_orphan')
                ->value('hardware_fulfilment_id'),
        );
    }

    public function test_rbp2138_channel_foundation_then_owner_recovery_yield_one_fulfilment(): void
    {
        $order = $this->rbp2138Fixture();
        $request = $this->rbpChannelIngestRequest('RBP2138', '6998469575');

        app(HardwareFulfilmentFoundationService::class)->ensureIngested($order, $request);
        $this->assertSame(1, HardwareFulfilment::query()->count());

        $auth = app(HardwareRecoveredFulfilmentAuthorization::class)->authorizeOne(
            $order->fresh(['items']),
            HardwareRecoveredFulfilmentAuthorization::PURPOSE_OWNER_EXISTING_COMMERCE,
            'P-14-90',
        );
        $this->assertTrue($auth['ok']);

        $isolated = app(HardwareFulfilmentIsolatedWorkflowService::class);
        $this->assertTrue($isolated->run(identifier: 'RBP2138', step: 'ingest')['duplicate']);
        $this->assertTrue($isolated->run(identifier: 'RBP2138', step: 'ingest')['duplicate']);
        $this->assertSame(1, HardwareFulfilment::query()->count());
        $this->assertSame((int) $order->id, (int) HardwareFulfilment::query()->value('commerce_order_id'));
    }

    public function test_rbp2138_owner_recovery_then_channel_foundation_still_yields_one_fulfilment(): void
    {
        $this->seed(\Database\Seeders\FinanceMasterDataSeeder::class);
        $order = $this->rbp2138Fixture();
        $request = $this->rbpChannelIngestRequest('RBP2138', '6998469575');

        $authResult = app(HardwareRecoveredFulfilmentAuthorization::class)->authorizeOne(
            $order->fresh(['items']),
            HardwareRecoveredFulfilmentAuthorization::PURPOSE_OWNER_EXISTING_COMMERCE,
            'P-14-90',
        );
        $this->assertTrue($authResult['ok']);

        app(HardwareFulfilmentIsolatedWorkflowService::class)->run(identifier: 'RBP2138', step: 'ingest');
        app(HardwareFulfilmentFoundationService::class)->ensureIngested($order->fresh(['items']), $request);

        $this->assertSame(1, HardwareFulfilment::query()->count());
        $this->assertContains(
            HardwareFulfilment::query()->firstOrFail()->state,
            [HardwareFulfilmentState::Ingested, HardwareFulfilmentState::ReadyForFulfilment],
        );
    }

    public function test_gap_monitor_ignores_cancelled_metadata_rbp_commerce(): void
    {
        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-092000',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => 'RBP920010',
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:RBP920010',
            'payload_hash' => hash('sha256', 'cancel-gap'),
            'status' => CommerceOrderStatus::Validated,
            'payment_status' => 'paid',
            'payment_reference' => 'pay_gap',
            'currency' => 'INR',
            'metadata' => ['order_status' => 'cancelled'],
            'received_at' => now()->subHours(2),
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);
        $order->items()->create([
            'line_no' => 1,
            'sku' => '946',
            'description' => 'MFS110',
            'qty' => 1,
            'model_id' => 946,
            'shipping_line_kind' => 'physical_merchandise',
            'requires_shipping' => true,
            'unit_price' => 2499,
            'line_total' => 2499,
        ]);

        $gaps = app(RbpHardwareFulfilmentGapMonitor::class)->findGaps();
        $this->assertSame([], $gaps);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signedBoxPost(array $payload)
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();

        return $this->call('POST', '/api/v1/channel-orders', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DESK_CHANNEL' => StatutoryInvoiceChannel::RadiumBoxCom->value,
            'HTTP_X_DESK_TIMESTAMP' => $timestamp,
            'HTTP_X_DESK_SIGNATURE' => (new ChannelIngestAuthenticator)->signature($timestamp, $body, self::BOX_SECRET),
        ], $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function rbpChannelIngestRequest(string $sourceId, string $paymentReference): ChannelOrderIngestRequest
    {
        return new ChannelOrderIngestRequest(
            channel: StatutoryInvoiceChannel::RadiumBoxCom,
            sourceType: StatutoryInvoiceSourceType::CommerceOrder,
            sourceId: $sourceId,
            lines: [
                new ChannelOrderLineDraft(
                    description: 'Mantra MFS110 L1',
                    qty: 1,
                    unitPrice: 2499,
                    sku: '946',
                    shippingLineKind: HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND,
                    requiresShipping: true,
                    modelId: 946,
                ),
            ],
            paymentStatus: 'paid',
            paymentReference: $paymentReference,
            currency: 'INR',
        );
    }

    private function rbp2138Fixture(): CommerceOrder
    {
        Order::query()->create([
            'order_id' => 'RBP2138',
            'product_name' => 'MFS110',
            'status' => 'active',
            'created_by' => User::factory()->create(['is_active' => true])->id,
            'cashfree_payment_id' => '6998469575',
            'payment_amount' => 2499,
        ]);

        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-022155',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => 'RBP2138',
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:RBP2138',
            'payload_hash' => hash('sha256', 'rbp2138-race'),
            'status' => CommerceOrderStatus::Validated,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'payment_provider' => 'cashfree',
            'payment_reference' => '6998469575',
            'currency' => 'INR',
            'customer_name' => 'RBP buyer',
            'ordered_at' => '2026-09-06 10:00:00',
            'paid_at' => '2026-09-06 10:05:00',
            'received_at' => now(),
        ]);

        CommerceOrderItem::query()->create([
            'commerce_order_id' => $commerce->id,
            'line_no' => 1,
            'model_id' => 946,
            'shipping_line_kind' => 'physical_merchandise',
            'requires_shipping' => true,
            'description' => 'Mantra MFS110 L1',
            'qty' => 1,
            'unit_price' => 2499,
            'gst_percentage' => 18,
            'taxable_value' => 2117.8,
            'tax_total' => 381.2,
            'line_total' => 2499,
        ]);

        return $commerce->fresh(['items']);
    }

    /**
     * @return array<string, mixed>
     */
    private function eligibleBoxPayload(string $sourceId): array
    {
        return [
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom->value,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'payment_status' => 'paid',
            'payment_provider' => 'cashfree',
            'payment_reference' => 'pay_'.$sourceId,
            'currency' => 'INR',
            'ordered_at' => '2026-09-06T10:00:00+05:30',
            'customer' => [
                'name' => 'Hardware Buyer',
                'phone' => '9000000099',
            ],
            'seller_gstin' => '07AAICP1128M1Z9',
            'place_of_supply_state' => 'Delhi',
            'lines' => [[
                'description' => 'MFS110',
                'sku' => '946',
                'qty' => 1,
                'unit_price' => 2499,
                'hsn_sac' => '84716050',
                'gst_percentage' => 18,
                'taxable_value' => 2117.80,
                'tax_total' => 381.20,
                'line_total' => 2499,
                'shipping_line_kind' => 'physical_merchandise',
                'requires_shipping' => true,
                'model_id' => 946,
            ]],
        ];
    }
}
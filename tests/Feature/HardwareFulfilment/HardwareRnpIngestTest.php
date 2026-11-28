<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\InventoryProduct;
use App\Models\Order;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\HardwareFulfilment\HardwareNeedsActionSqlQuery;
use App\Services\PreShipmentCancellation\PreShipmentCancellationEligibilityService;
use App\Services\Refunds\WalletRefundDestinationResolver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HardwareRnpIngestTest extends TestCase
{
    use RefreshDatabase;

    private const NET_SECRET = 'test-rdservice-net-hardware-secret';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config([
            'channel_ingest.secrets.rdservice_net' => self::NET_SECRET,
            'channel_ingest.auto_issue_invoice' => false,
            'channel_ingest.cutover_approved' => false,
            'hardware_fulfilment.correlate_cashfree' => false,
            'pre_shipment_cancellation.enabled' => true,
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.worker_may_mint' => false,
        ]);
    }

    public function test_rnp30_shaped_paid_ingest_creates_commerce_and_fulfilment(): void
    {
        $this->mapNetHardwareSku();
        $payload = $this->rnpPayload('RNP30');

        $this->signedPost($payload)->assertCreated();

        $this->assertSame(1, CommerceOrder::query()->count());
        $this->assertSame(1, HardwareFulfilment::query()->count());
        $order = CommerceOrder::query()->firstOrFail();
        $fulfilment = HardwareFulfilment::query()->firstOrFail();

        $this->assertSame('rdservice_net', $order->channel->value);
        $this->assertSame('RNP30', $order->source_id);
        $this->assertSame('statutory:rdservice_net:commerce_order:RNP30', $order->idempotency_key);
        $this->assertSame('rdservice.net', $order->metadata['source'] ?? null);
        $this->assertSame('hardware_direct_buy', $order->metadata['source_order_type'] ?? null);
        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, $fulfilment->state);
        $this->assertSame('RNP30', $fulfilment->source_id);
    }

    public function test_legacy_desk_order_row_is_linked_without_duplicate_orders(): void
    {
        $this->mapNetHardwareSku();
        $legacy = Order::query()->create([
            'order_id' => 'RNP30',
            'status' => 'active',
            'amount' => 2735,
            'payment_amount' => 2735,
            'payment_method' => 'UPI',
            'payment_date' => '2026-11-20 13:02:34',
            'cashfree_payment_id' => '7048442580',
            'gateway_order_id' => '7491943884',
            'bank_reference' => '100881682792',
        ]);

        $payload = $this->rnpPayload('RNP30');
        $payload['payment_reference'] = '7048442580';
        $payload['lines'][0]['line_total'] = 2735;
        $payload['lines'][0]['taxable_value'] = 2317.8;
        $payload['lines'][0]['tax_total'] = 417.2;
        $payload['lines'][0]['unit_price'] = 2735;

        $this->signedPost($payload)->assertCreated();

        $this->assertSame(1, Order::query()->count());
        $this->assertSame($legacy->id, (int) Order::query()->value('id'));
        $order = CommerceOrder::query()->firstOrFail();
        $fulfilment = HardwareFulfilment::query()->firstOrFail();
        $this->assertSame($legacy->id, (int) $order->support_order_id);
        $this->assertSame($legacy->id, (int) $fulfilment->support_order_id);
        $this->assertSame('7048442580', $fulfilment->cashfree_payment_id);
    }

    public function test_legacy_payment_conflict_fails_closed(): void
    {
        $this->mapNetHardwareSku();
        Order::query()->create([
            'order_id' => 'RNP40',
            'status' => 'active',
            'payment_amount' => 2735,
            'cashfree_payment_id' => 'existing-payment-id',
        ]);

        $payload = $this->rnpPayload('RNP40');
        $payload['payment_reference'] = 'different-payment-id';

        $this->signedPost($payload)->assertStatus(422);
        $this->assertSame(0, CommerceOrder::query()->count());
        $this->assertSame(0, HardwareFulfilment::query()->count());
    }

    public function test_rnp30_shaped_payload_uses_hardware_hash_duplicate_and_conflict(): void
    {
        $this->mapNetHardwareSku();
        $payload = $this->rnpPayload('RNP30');
        $this->signedPost($payload)->assertCreated();

        $this->signedPost($payload)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame(1, CommerceOrder::query()->where('source_id', 'RNP30')->count());

        ChannelSkuMap::query()->updateOrCreate(
            [
                'channel' => StatutoryInvoiceChannel::RdServiceNet,
                'model_id' => 91002,
            ],
            [
                'inventory_product_id' => InventoryProduct::query()->where('sku', 'RBMFS110L1')->value('id'),
                'catalog_sku' => 'mantra-fingerprint-alt',
                'channel_sku' => 'mantra-fingerprint-alt',
            ],
        );

        $changedModel = $payload;
        $changedModel['lines'][0]['model_id'] = 91002;
        $changedModel['lines'][0]['catalog_sku'] = 'mantra-fingerprint-alt';
        $this->signedPost($changedModel)->assertStatus(409);
        $this->assertSame(1, CommerceOrder::query()->where('source_id', 'RNP30')->count());

    }

    public function test_ingest_replay_is_idempotent(): void
    {
        $this->mapNetHardwareSku();
        $payload = $this->rnpPayload('RNP41');

        $first = $this->signedPost($payload);
        $second = $this->signedPost($payload);

        $first->assertCreated();
        $second->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame(1, CommerceOrder::query()->count());
        $this->assertSame(1, HardwareFulfilment::query()->count());
    }

    public function test_unpaid_and_non_physical_rnp_fail_closed(): void
    {
        $this->mapNetHardwareSku();

        $unpaid = $this->rnpPayload('RNP42');
        $unpaid['payment_status'] = 'pending';
        $this->signedPost($unpaid)->assertStatus(422);

        $nonPhysical = $this->rnpPayload('RNP43');
        $nonPhysical['lines'][0]['shipping_line_kind'] = 'service';
        $nonPhysical['lines'][0]['requires_shipping'] = false;
        unset($nonPhysical['lines'][0]['model_id']);
        $this->signedPost($nonPhysical)->assertStatus(422);

        $this->assertSame(0, HardwareFulfilment::query()->count());
    }

    public function test_rnp_cannot_use_rdservice_in_channel(): void
    {
        $this->mapNetHardwareSku();
        $payload = $this->rnpPayload('RNP44');
        $payload['channel'] = StatutoryInvoiceChannel::RdServiceIn->value;

        config(['channel_ingest.secrets.rdservice_in' => 'rin-secret']);

        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $this->call('POST', '/api/v1/channel-orders', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DESK_CHANNEL' => StatutoryInvoiceChannel::RdServiceIn->value,
            'HTTP_X_DESK_TIMESTAMP' => $timestamp,
            'HTTP_X_DESK_SIGNATURE' => (new ChannelIngestAuthenticator)->signature($timestamp, $body, 'rin-secret'),
        ], $body)->assertStatus(422);
    }

    public function test_needs_action_workspace_includes_rnp_fulfilment(): void
    {
        $this->mapNetHardwareSku();
        $this->signedPost($this->rnpPayload('RNP45'))->assertCreated();

        $fulfilment = HardwareFulfilment::query()->firstOrFail();
        $from = Carbon::now('Asia/Kolkata')->subDay();
        $to = Carbon::now('Asia/Kolkata')->addDay();
        $counts = app(HardwareNeedsActionSqlQuery::class)->filterCounts($from, $to, 'RNP45');

        $this->assertGreaterThanOrEqual(1, $counts['needs_action'] ?? 0);
        $this->assertSame($fulfilment->id, HardwareFulfilment::query()->where('source_id', 'RNP45')->value('id'));
    }

    public function test_preship_cancellation_eligible_for_paid_rnp_hardware(): void
    {
        $this->mapNetHardwareSku();
        Order::query()->create([
            'order_id' => 'RNP46',
            'status' => 'active',
            'payment_amount' => 2735,
            'cashfree_payment_id' => '7048442580',
        ]);
        $this->signedPost($this->rnpPayload('RNP46'))->assertCreated();

        $order = Order::query()->where('order_id', 'RNP46')->firstOrFail();
        $assessment = app(PreShipmentCancellationEligibilityService::class)->assess($order);
        $this->assertTrue($assessment['eligible']);
        $this->assertSame('B', $assessment['phase']);
    }

    public function test_wallet_refund_destination_still_treats_rnp_as_rdservice_net(): void
    {
        $resolver = app(WalletRefundDestinationResolver::class);
        $this->assertTrue($resolver->isRdServiceNet('RNP1'));
        $this->assertTrue($resolver->isRdServiceNet('RN1'));
    }

    private function mapNetHardwareSku(): void
    {
        $product = InventoryProduct::query()->firstOrCreate(
            ['sku' => 'RBMFS110L1'],
            [
                'name' => 'MFS110 L1',
                'hsn_code' => '84716050',
                'gst_percentage' => 18,
                'unit_price' => 2735,
                'is_serialized' => true,
                'is_active' => true,
            ],
        );

        ChannelSkuMap::query()->updateOrCreate(
            [
                'channel' => StatutoryInvoiceChannel::RdServiceNet,
                'model_id' => 91001,
            ],
            [
                'inventory_product_id' => $product->id,
                'catalog_sku' => 'mantra-fingerprint',
                'channel_sku' => 'mantra-fingerprint',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function rnpPayload(string $sourceId): array
    {
        return [
            'channel' => StatutoryInvoiceChannel::RdServiceNet->value,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'payment_status' => 'paid',
            'payment_provider' => 'cashfree',
            'payment_reference' => '7048442580',
            'payment_method' => 'UPI',
            'currency' => 'INR',
            'seller_gstin' => '07AAICP1128M1Z9',
            'place_of_supply_state' => 'Delhi',
            'ordered_at' => '2026-11-20T13:02:12+05:30',
            'customer' => [
                'name' => 'Hardware Buyer',
                'phone' => '9000000030',
                'email' => 'buyer@example.com',
            ],
            'shipping_address' => [
                'line1' => '1 Test Street',
                'city' => 'New Delhi',
                'state' => 'Delhi',
                'pincode' => '110001',
            ],
            'billing_address' => [
                'line1' => '1 Test Street',
                'city' => 'New Delhi',
                'state' => 'Delhi',
                'pincode' => '110001',
            ],
            'metadata' => [
                'source' => 'rdservice.net',
                'source_order_type' => 'hardware_direct_buy',
                'source_product_id' => 'mantra-fingerprint',
            ],
            'lines' => [[
                'description' => 'Mantra MFS110 L1 RD Service',
                'sku' => 'RBMFS110L1',
                'catalog_sku' => 'mantra-fingerprint',
                'qty' => 1,
                'unit_price' => 2735,
                'hsn_sac' => '84716050',
                'gst_percentage' => 18,
                'taxable_value' => 2317.8,
                'tax_total' => 417.2,
                'line_total' => 2735,
                'shipping_line_kind' => 'physical_merchandise',
                'requires_shipping' => true,
                'model_id' => 91001,
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signedPost(array $payload)
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();

        return $this->call('POST', '/api/v1/channel-orders', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DESK_CHANNEL' => StatutoryInvoiceChannel::RdServiceNet->value,
            'HTTP_X_DESK_TIMESTAMP' => $timestamp,
            'HTTP_X_DESK_SIGNATURE' => (new ChannelIngestAuthenticator)->signature($timestamp, $body, self::NET_SECRET),
        ], $body);
    }
}

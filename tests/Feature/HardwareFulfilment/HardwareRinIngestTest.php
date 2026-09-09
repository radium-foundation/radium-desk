<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentSerial;
use App\Models\InventoryProduct;
use App\Models\OutboxEvent;
use App\Models\Shipment;
use App\Models\StatutoryInvoice;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HardwareRinIngestTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE_SECRET = 'test-rdservice-in-secret';

    private const BOX_SECRET = 'test-radiumbox-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'channel_ingest.secrets.rdservice_in' => self::SERVICE_SECRET,
            'channel_ingest.secrets.radiumbox_com' => self::BOX_SECRET,
            'channel_ingest.secrets.rdservice_net' => '',
            'channel_ingest.auto_issue_invoice' => false,
            'channel_ingest.cutover_approved' => false,
            'hardware_fulfilment.correlate_cashfree' => false,
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'shipping.enabled' => true,
            'shipping.http_enabled' => false,
        ]);
    }

    public function test_rin_hardware_creates_one_commerce_and_ingested_fulfilment(): void
    {
        $this->mapRinSkus();
        $payload = $this->rinPayload('RIN3512344');

        $this->signedPost($payload, StatutoryInvoiceChannel::RdServiceIn, self::SERVICE_SECRET)->assertCreated();

        $this->assertSame(1, CommerceOrder::query()->count());
        $this->assertSame(1, HardwareFulfilment::query()->count());
        $order = CommerceOrder::query()->firstOrFail();
        $item = $order->items->first();
        $fulfilment = HardwareFulfilment::query()->firstOrFail();

        $this->assertSame('rdservice_in', $order->channel->value);
        $this->assertSame('RIN3512344', $order->source_id);
        $this->assertSame('statutory:rdservice_in:commerce_order:RIN3512344', $order->idempotency_key);
        $this->assertSame('rdservice.in', $order->metadata['source'] ?? null);
        $this->assertSame('hardware_direct_buy', $order->metadata['source_order_type'] ?? null);
        $this->assertSame('RBFM220UFP', $item?->sku);
        $this->assertSame('startek-fingerprint', $item?->catalog_sku);
        $this->assertSame(91004, (int) $item?->model_id);
        $this->assertSame(1, (int) $item?->qty);
        $this->assertSame('84716050', $item?->hsn_sac);
        $this->assertSame('physical_merchandise', $item?->shipping_line_kind);
        $this->assertSame(2649.0, (float) $item?->line_total);
        $this->assertSame('TELIPARA TEA GARDEN CT D', $order->shipping_address_structured['city'] ?? null);
        $this->assertSame('West Bengal', $order->shipping_address_structured['state'] ?? null);
        $this->assertSame('Tea garden gate', $order->shipping_address_structured['line2'] ?? null);
        $this->assertArrayNotHasKey('country', $order->shipping_address_structured ?? []);
        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, $fulfilment->state);
        $this->assertSame('RIN3512344', $fulfilment->source_id);
        $this->assertNoFulfilmentMutations();
    }

    public function test_rin_retry_is_idempotent(): void
    {
        $this->mapRinSkus();
        $payload = $this->rinPayload('RIN3512331', catalog: 'mantra-fingerprint');

        $first = $this->signedPost($payload, StatutoryInvoiceChannel::RdServiceIn, self::SERVICE_SECRET);
        $second = $this->signedPost($payload, StatutoryInvoiceChannel::RdServiceIn, self::SERVICE_SECRET);

        $first->assertCreated();
        $second->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame($first->json('order_no'), $second->json('order_no'));
        $this->assertSame(1, CommerceOrder::query()->count());
        $this->assertSame(1, HardwareFulfilment::query()->count());
        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, HardwareFulfilment::query()->firstOrFail()->state);
        $this->assertNoFulfilmentMutations();
    }

    public function test_numeric_rin_suffix_does_not_collide_with_rde_or_service(): void
    {
        $this->mapRinSkus();
        $rin = $this->rinPayload('RIN900555', catalog: 'mantra-fingerprint');
        $rde = $this->boxPayload('RDE900555');
        $service = $this->servicePayload('RD900555');

        $this->signedPost($rin, StatutoryInvoiceChannel::RdServiceIn, self::SERVICE_SECRET)->assertCreated();
        $this->signedPost($rde, StatutoryInvoiceChannel::RadiumBoxCom, self::BOX_SECRET)->assertCreated();
        $this->signedPost($service, StatutoryInvoiceChannel::RdServiceIn, self::SERVICE_SECRET)->assertCreated();

        $this->assertSame(3, CommerceOrder::query()->count());
        $this->assertSame(2, HardwareFulfilment::query()->count());
        $this->assertTrue(CommerceOrder::query()->where('source_id', 'RIN900555')->where('channel', StatutoryInvoiceChannel::RdServiceIn)->exists());
        $this->assertTrue(CommerceOrder::query()->where('source_id', 'RDE900555')->where('channel', StatutoryInvoiceChannel::RadiumBoxCom)->exists());
        $this->assertTrue(CommerceOrder::query()->where('source_id', 'RD900555')->exists());
        $this->assertFalse(HardwareFulfilment::query()->where('source_id', 'RD900555')->exists());
    }

    public function test_missing_sku_map_fails_closed(): void
    {
        $payload = $this->rinPayload('RIN900556', catalog: 'mantra-fingerprint');

        $this->signedPost($payload, StatutoryInvoiceChannel::RdServiceIn, self::SERVICE_SECRET)
            ->assertStatus(422);

        $this->assertSame(0, CommerceOrder::query()->count());
        $this->assertSame(0, HardwareFulfilment::query()->count());
    }

    public function test_wrong_sku_and_missing_model_fail_closed(): void
    {
        $this->mapRinSkus();
        $wrongSku = $this->rinPayload('RIN900557', catalog: 'mantra-fingerprint');
        $wrongSku['lines'][0]['sku'] = 'RBFM220CFP';

        $this->signedPost($wrongSku, StatutoryInvoiceChannel::RdServiceIn, self::SERVICE_SECRET)
            ->assertStatus(422);

        $missingModel = $this->rinPayload('RIN900558', catalog: 'mantra-fingerprint');
        unset($missingModel['lines'][0]['model_id']);

        $this->signedPost($missingModel, StatutoryInvoiceChannel::RdServiceIn, self::SERVICE_SECRET)
            ->assertStatus(422);

        $this->assertSame(0, CommerceOrder::query()->count());
    }

    public function test_insufficient_and_changed_payment_fail_closed(): void
    {
        $this->mapRinSkus();
        $unpaid = $this->rinPayload('RIN900559', catalog: 'mantra-fingerprint');
        $unpaid['payment_status'] = 'pending';

        $this->signedPost($unpaid, StatutoryInvoiceChannel::RdServiceIn, self::SERVICE_SECRET)
            ->assertStatus(422);

        $paid = $this->rinPayload('RIN900560', catalog: 'mantra-fingerprint');
        $this->signedPost($paid, StatutoryInvoiceChannel::RdServiceIn, self::SERVICE_SECRET)->assertCreated();

        $changed = $paid;
        $changed['payment_reference'] = 'other-ref';
        $this->signedPost($changed, StatutoryInvoiceChannel::RdServiceIn, self::SERVICE_SECRET)
            ->assertStatus(409);

        $this->assertSame(1, CommerceOrder::query()->count());
        $this->assertSame('RIN900560', CommerceOrder::query()->value('payment_reference'));
    }

    public function test_long_address_is_intact_and_india_is_not_invented(): void
    {
        $this->mapRinSkus();
        $line1 = str_repeat('Address token ', 22).'end';
        $this->assertGreaterThan(255, strlen($line1));
        $this->assertLessThanOrEqual(500, strlen($line1));
        $payload = $this->rinPayload('RIN900561', catalog: 'mantra-fingerprint');
        $payload['shipping_address']['line1'] = $line1;
        unset($payload['shipping_address']['country']);

        $this->signedPost($payload, StatutoryInvoiceChannel::RdServiceIn, self::SERVICE_SECRET)->assertCreated();

        $structured = CommerceOrder::query()->firstOrFail()->shipping_address_structured;
        $this->assertSame($line1, $structured['line1'] ?? null);
        $this->assertArrayNotHasKey('country', $structured ?? []);
    }

    public function test_invalid_hmac_is_rejected_and_rin_cannot_use_box_channel(): void
    {
        $this->mapRinSkus();
        $payload = $this->rinPayload('RIN900562', catalog: 'mantra-fingerprint');

        $this->signedPost($payload, StatutoryInvoiceChannel::RdServiceIn, 'wrong-secret')
            ->assertStatus(401);

        $asBox = $payload;
        $asBox['channel'] = StatutoryInvoiceChannel::RadiumBoxCom->value;
        $this->signedPost($asBox, StatutoryInvoiceChannel::RadiumBoxCom, self::BOX_SECRET)
            ->assertStatus(422);

        $this->assertSame(0, CommerceOrder::query()->count());
    }

    public function test_isolated_ingest_stays_ingested_without_serial_invoice_or_ship(): void
    {
        $this->mapRinSkus();
        $sourceId = 'RIN900563';
        $this->signedPost(
            $this->rinPayload($sourceId, catalog: 'mantra-fingerprint'),
            StatutoryInvoiceChannel::RdServiceIn,
            self::SERVICE_SECRET,
        )->assertCreated();

        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, HardwareFulfilment::query()->firstOrFail()->state);
        $this->assertNoFulfilmentMutations();
    }

    public function test_existing_rde_hardware_ingest_is_unchanged(): void
    {
        $payload = $this->boxPayload('RDE900564');
        $this->signedPost($payload, StatutoryInvoiceChannel::RadiumBoxCom, self::BOX_SECRET)->assertCreated();
        $this->signedPost($payload, StatutoryInvoiceChannel::RadiumBoxCom, self::BOX_SECRET)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $this->assertSame(1, CommerceOrder::query()->count());
        $this->assertSame('radiumbox_com', CommerceOrder::query()->value('channel')?->value);
        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, HardwareFulfilment::query()->firstOrFail()->state);
        $this->assertNoFulfilmentMutations();
    }

    public function test_sku_map_seed_command_is_idempotent_and_dry_run_writes_nothing(): void
    {
        $this->createInventoryProducts();

        $this->artisan('desk:seed-rdservice-in-hardware-sku-maps', ['--dry-run' => true])
            ->assertSuccessful();
        $this->assertSame(0, ChannelSkuMap::query()->count());

        $this->artisan('desk:seed-rdservice-in-hardware-sku-maps', ['--apply' => true])
            ->assertSuccessful();
        $this->assertSame(4, ChannelSkuMap::query()->where('channel', StatutoryInvoiceChannel::RdServiceIn)->count());

        $this->artisan('desk:seed-rdservice-in-hardware-sku-maps', ['--apply' => true])
            ->assertSuccessful();
        $this->assertSame(4, ChannelSkuMap::query()->count());
        $this->assertTrue(ChannelSkuMap::query()->where('model_id', 91004)->where('channel_sku', 'startek-fingerprint')->exists());
    }

    private function mapRinSkus(): void
    {
        $this->createInventoryProducts();
        $this->artisan('desk:seed-rdservice-in-hardware-sku-maps', ['--apply' => true])
            ->assertSuccessful();
    }

    private function createInventoryProducts(): void
    {
        $products = [
            'RBMFS110L1' => 'MFS110 L1',
            'RBMIS100IR' => 'MIS100',
            'RBIMSOE3L1' => 'MSO1300 E3',
            'RBFM220UFP' => 'FM220 USB',
        ];
        foreach ($products as $sku => $name) {
            InventoryProduct::query()->firstOrCreate(
                ['sku' => $sku],
                [
                    'name' => $name,
                    'hsn_code' => '84716050',
                    'gst_percentage' => 18,
                    'unit_price' => 2649,
                    'is_serialized' => true,
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signedPost(array $payload, StatutoryInvoiceChannel $channel, string $secret)
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();

        return $this->call('POST', '/api/v1/channel-orders', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DESK_CHANNEL' => $channel->value,
            'HTTP_X_DESK_TIMESTAMP' => $timestamp,
            'HTTP_X_DESK_SIGNATURE' => (new ChannelIngestAuthenticator)->signature($timestamp, $body, $secret),
        ], $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function rinPayload(string $sourceId, string $catalog = 'startek-fingerprint'): array
    {
        $maps = [
            'mantra-fingerprint' => ['model_id' => 91001, 'sku' => 'RBMFS110L1', 'name' => 'MFS110 L1 (STQC) Fingerprint Scanner'],
            'startek-fingerprint' => ['model_id' => 91004, 'sku' => 'RBFM220UFP', 'name' => 'FM220U L1 Single Fingerprint Scanner'],
        ];
        $map = $maps[$catalog];

        return [
            'channel' => StatutoryInvoiceChannel::RdServiceIn->value,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'payment_status' => 'paid',
            'payment_provider' => 'cashfree',
            'payment_reference' => $sourceId,
            'payment_method' => 'cashfree',
            'currency' => 'INR',
            'customer' => [
                'name' => 'Hardware Buyer',
                'phone' => '9000000099',
                'email' => 'buyer@example.com',
            ],
            'shipping_address' => [
                'line1' => 'House 12 TELIPARA',
                'line2' => 'Tea garden gate',
                'city' => 'TELIPARA TEA GARDEN CT D',
                'state' => 'West Bengal',
                'pincode' => '735203',
            ],
            'billing_address' => [
                'line1' => 'House 12 TELIPARA',
                'city' => 'TELIPARA TEA GARDEN CT D',
                'state' => 'West Bengal',
                'pincode' => '735203',
            ],
            'place_of_supply_state' => 'West Bengal',
            'ordered_at' => '2026-09-06T21:44:19+05:30',
            'metadata' => [
                'source' => 'rdservice.in',
                'source_order_type' => 'hardware_direct_buy',
                'source_product_id' => $catalog,
            ],
            'lines' => [[
                'description' => $map['name'],
                'sku' => $map['sku'],
                'catalog_sku' => $catalog,
                'qty' => 1,
                'unit_price' => 2649,
                'hsn_sac' => '84716050',
                'gst_percentage' => 18,
                'taxable_value' => 2244.92,
                'tax_total' => 404.08,
                'line_total' => 2649,
                'shipping_line_kind' => 'physical_merchandise',
                'requires_shipping' => true,
                'model_id' => $map['model_id'],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function boxPayload(string $sourceId): array
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
            'customer' => [
                'name' => 'Hardware Buyer',
                'phone' => '9000000099',
            ],
            'place_of_supply_state' => 'Madhya Pradesh',
            'ordered_at' => '2026-09-06T21:44:19+05:30',
            'metadata' => [
                'ordertype' => 'hardware',
            ],
            'lines' => [[
                'description' => 'MSO1300',
                'sku' => '951',
                'catalog_sku' => 'MSO1300',
                'qty' => 1,
                'unit_price' => 3049,
                'hsn_sac' => '84716050',
                'gst_percentage' => 18,
                'taxable_value' => 2583.90,
                'tax_total' => 465.10,
                'line_total' => 3049,
                'shipping_line_kind' => 'physical_merchandise',
                'requires_shipping' => true,
                'model_id' => 951,
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function servicePayload(string $sourceId): array
    {
        return [
            'channel' => StatutoryInvoiceChannel::RdServiceIn->value,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'payment_status' => 'paid',
            'payment_provider' => 'cashfree',
            'payment_reference' => 'pay_'.$sourceId,
            'currency' => 'INR',
            'customer' => [
                'name' => 'Walk-in',
                'phone' => '9000000001',
            ],
            'place_of_supply_state' => 'Delhi',
            'lines' => [[
                'description' => 'RD Service',
                'sku' => 'RD-SVC',
                'qty' => 1,
                'unit_price' => 100,
                'hsn_sac' => '998313',
                'gst_percentage' => 18,
                'taxable_value' => 100,
                'tax_total' => 18,
                'line_total' => 118,
            ]],
        ];
    }

    private function assertNoFulfilmentMutations(): void
    {
        $this->assertSame(0, HardwareFulfilmentSerial::query()->count());
        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(0, Shipment::query()->count());
        $this->assertSame(0, OutboxEvent::query()->where('event_type', 'like', 'shipping%')->count());
    }
}

<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\FinanceJournal;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentSerial;
use App\Models\InvoiceSequence;
use App\Models\InvoiceSequenceAllocation;
use App\Models\Order;
use App\Models\StatutoryInvoice;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\StatutoryInvoice\StatutoryBillingIssuer;
use App\Services\StatutoryInvoice\StatutoryLocationSeries;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HardwareFulfilmentP1FoundationTest extends TestCase
{
    use RefreshDatabase;

    private const BOX_SECRET = 'test-radiumbox-secret';

    private const SERVICE_SECRET = 'test-rdservice-in-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'channel_ingest.secrets.rdservice_in' => self::SERVICE_SECRET,
            'channel_ingest.secrets.radiumbox_com' => self::BOX_SECRET,
            'channel_ingest.secrets.rdservice_net' => '',
            'channel_ingest.auto_issue_invoice' => false,
            'channel_ingest.cutover_approved' => false,
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
        ]);
    }

    public function test_same_hardware_order_retry_is_idempotent_and_does_not_duplicate_fulfilment(): void
    {
        $payload = $this->hardwarePayload('RDE900101');

        $first = $this->signedPost($payload, StatutoryInvoiceChannel::RadiumBoxCom, self::BOX_SECRET);
        $second = $this->signedPost($payload, StatutoryInvoiceChannel::RadiumBoxCom, self::BOX_SECRET);

        $first->assertCreated();
        $second->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame($first->json('order_no'), $second->json('order_no'));
        $this->assertSame(1, CommerceOrder::query()->count());
        $this->assertSame(1, HardwareFulfilment::query()->count());

        $fulfilment = HardwareFulfilment::query()->firstOrFail();
        $this->assertSame(HardwareFulfilmentState::Ingested, $fulfilment->state);
        $this->assertSame('statutory:radiumbox_com:commerce_order:RDE900101', $fulfilment->idempotency_key);
        $this->assertSame($fulfilment->idempotency_key, CommerceOrder::query()->value('idempotency_key'));
        $this->assertNoStatutorySideEffects();
    }

    public function test_meaningful_hardware_payload_change_is_conflicted(): void
    {
        $payload = $this->hardwarePayload('RDE900102');
        $this->signedPost($payload, StatutoryInvoiceChannel::RadiumBoxCom, self::BOX_SECRET)->assertCreated();

        $conflict = $payload;
        $conflict['lines'][0]['model_id'] = 946;
        $conflict['lines'][0]['gst_percentage'] = 12;

        $this->signedPost($conflict, StatutoryInvoiceChannel::RadiumBoxCom, self::BOX_SECRET)
            ->assertStatus(409)
            ->assertJsonPath('status', 'conflict');

        $this->assertSame(1, CommerceOrder::query()->count());
        $this->assertSame(1, HardwareFulfilment::query()->count());
        $this->assertSame(951, (int) CommerceOrder::query()->firstOrFail()->items->first()?->model_id);
        $this->assertNoStatutorySideEffects();
    }

    public function test_transport_timestamp_changes_do_not_conflict(): void
    {
        $payload = $this->hardwarePayload('RDE900103');
        $payload['paid_at'] = '2026-09-07T10:00:00+05:30';
        $payload['ordered_at'] = '2026-09-07T09:55:00+05:30';

        $this->signedPost($payload, StatutoryInvoiceChannel::RadiumBoxCom, self::BOX_SECRET)->assertCreated();

        $retry = $payload;
        $retry['paid_at'] = '2026-09-07T12:40:00+05:30';
        $retry['ordered_at'] = '2026-09-07T12:39:00+05:30';
        $retry['metadata']['retry_count'] = 3;
        $retry['metadata']['enqueued_at'] = '2026-09-07T12:40:01+05:30';

        $this->signedPost($retry, StatutoryInvoiceChannel::RadiumBoxCom, self::BOX_SECRET)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $this->assertSame(1, CommerceOrder::query()->count());
        $this->assertSame(1, HardwareFulfilment::query()->count());
    }

    public function test_support_order_id_is_preserved_and_not_a_second_identity(): void
    {
        $payload = $this->hardwarePayload('RDE900104');
        $payload['support_order_id'] = 4411;

        $this->signedPost($payload, StatutoryInvoiceChannel::RadiumBoxCom, self::BOX_SECRET)->assertCreated();

        $retry = $payload;
        unset($retry['support_order_id']);
        $retry['paid_at'] = '2026-09-07T13:00:00+05:30';

        $this->signedPost($retry, StatutoryInvoiceChannel::RadiumBoxCom, self::BOX_SECRET)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $order = CommerceOrder::query()->firstOrFail();
        $fulfilment = HardwareFulfilment::query()->firstOrFail();
        $this->assertSame(4411, (int) $order->support_order_id);
        $this->assertSame(4411, (int) $fulfilment->support_order_id);
        $this->assertSame('statutory:radiumbox_com:commerce_order:RDE900104', $order->idempotency_key);
        $this->assertSame($order->idempotency_key, $fulfilment->idempotency_key);
    }

    public function test_cashfree_desk_order_is_linked_as_evidence_only(): void
    {
        $deskOrder = Order::query()->create([
            'order_id' => 'RDE900105',
            'product_name' => 'MSO1300',
            'status' => 'active',
            'cashfree_payment_id' => 'cf_pay_900105',
        ]);

        $payload = $this->hardwarePayload('RDE900105');
        unset($payload['support_order_id']);

        $this->signedPost($payload, StatutoryInvoiceChannel::RadiumBoxCom, self::BOX_SECRET)->assertCreated();

        $fulfilment = HardwareFulfilment::query()->firstOrFail();
        $this->assertSame((int) $deskOrder->id, (int) $fulfilment->support_order_id);
        $this->assertSame('cf_pay_900105', $fulfilment->cashfree_payment_id);
        $this->assertSame('pay_RDE900105', $fulfilment->payment_reference);
        $this->assertSame('statutory:radiumbox_com:commerce_order:RDE900105', $fulfilment->idempotency_key);
        $this->assertNotSame($fulfilment->cashfree_payment_id, $fulfilment->idempotency_key);
        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_database_prevents_duplicate_hardware_fulfilment_identities(): void
    {
        $this->signedPost($this->hardwarePayload('RDE900106'), StatutoryInvoiceChannel::RadiumBoxCom, self::BOX_SECRET)
            ->assertCreated();

        $existing = HardwareFulfilment::query()->firstOrFail();
        $secondOrder = CommerceOrder::query()->create([
            'order_no' => 'CO-999999',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => 'RDE900199',
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:RDE900199',
            'payload_hash' => hash('sha256', 'other'),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'received_at' => now(),
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        HardwareFulfilment::query()->create([
            'commerce_order_id' => $secondOrder->id,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $existing->source_id,
            'idempotency_key' => $existing->idempotency_key,
            'state' => HardwareFulfilmentState::Ingested,
            'ingested_at' => now(),
        ]);
    }

    public function test_service_commerce_ingest_does_not_open_hardware_fulfilment(): void
    {
        $payload = $this->servicePayload();

        $this->signedPost($payload, StatutoryInvoiceChannel::RdServiceIn, self::SERVICE_SECRET)->assertCreated();
        $this->signedPost($payload, StatutoryInvoiceChannel::RdServiceIn, self::SERVICE_SECRET)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $this->assertSame(1, CommerceOrder::query()->count());
        $this->assertSame(0, HardwareFulfilment::query()->count());
        $this->assertSame('rdservice_in', CommerceOrder::query()->value('channel')?->value);
        $this->assertNoStatutorySideEffects();
    }

    public function test_support_only_radiumbox_rde_without_physical_lines_does_not_open_fulfilment(): void
    {
        $payload = $this->hardwarePayload('RDE900107');
        unset(
            $payload['lines'][0]['shipping_line_kind'],
            $payload['lines'][0]['requires_shipping'],
            $payload['lines'][0]['model_id'],
            $payload['lines'][0]['product_id'],
        );

        $this->signedPost($payload, StatutoryInvoiceChannel::RadiumBoxCom, self::BOX_SECRET)->assertCreated();

        $this->assertSame(1, CommerceOrder::query()->count());
        $this->assertSame(0, HardwareFulfilment::query()->count());
        $this->assertNoStatutorySideEffects();
    }

    public function test_pos_product_branch_issuer_remains_delhi_not_delhi_b2c(): void
    {
        $location = app(StatutoryBillingIssuer::class)->requireForProductBranch('DELHI-RETAIL');

        $this->assertSame(StatutoryLocationSeries::DELHI, $location);
        $this->assertNotSame(StatutoryLocationSeries::DELHI_B2C, $location);
    }

    public function test_hardware_fields_and_structured_address_persist(): void
    {
        $payload = $this->hardwarePayload('RDE900108');
        $payload['billing_address'] = [
            'line1' => '12 Street',
            'city' => 'Indore',
            'state' => 'Madhya Pradesh',
            'pincode' => '452001',
            'country' => 'India',
        ];
        $payload['shipping_address'] = [
            'line1' => '12 Street',
            'city' => 'Indore',
            'state' => 'Madhya Pradesh',
            'pincode' => '452001',
        ];
        $payload['parcel'] = ['weight' => 1.5, 'length' => 20, 'breadth' => 10, 'height' => 8];

        $this->signedPost($payload, StatutoryInvoiceChannel::RadiumBoxCom, self::BOX_SECRET)->assertCreated();

        $order = CommerceOrder::query()->firstOrFail();
        $item = $order->items->first();
        $this->assertSame('physical_merchandise', $item?->shipping_line_kind);
        $this->assertTrue((bool) $item?->requires_shipping);
        $this->assertSame(951, (int) $item?->model_id);
        $this->assertSame('MSO1300', $item?->catalog_sku);
        $this->assertSame('Madhya Pradesh', $order->billing_state);
        $this->assertSame('Indore', $order->billing_address_structured['city'] ?? null);
        $this->assertSame(1.5, $order->parcel['weight'] ?? null);
        $this->assertSame(HardwareFulfilmentState::Ingested, $order->hardwareFulfilment?->state);
    }

    public function test_persistence_supports_two_hundred_serial_references_without_a_five_item_cap(): void
    {
        $this->signedPost($this->hardwarePayload('RDE900109'), StatutoryInvoiceChannel::RadiumBoxCom, self::BOX_SECRET)
            ->assertCreated();

        $fulfilment = HardwareFulfilment::query()->firstOrFail();
        $item = $fulfilment->commerceOrder?->items->first();
        $this->assertNotNull($item);

        for ($position = 1; $position <= 200; $position++) {
            HardwareFulfilmentSerial::query()->create([
                'hardware_fulfilment_id' => $fulfilment->id,
                'commerce_order_item_id' => $item->id,
                'line_no' => 1,
                'position' => $position,
                'serial_number' => sprintf('P1-FOUNDATION-%03d', $position),
            ]);
        }

        $this->assertSame(200, $fulfilment->serials()->count());
        $this->assertGreaterThan(5, $fulfilment->serials()->count());
        $this->assertSame(0, StatutoryInvoice::query()->count());
    }

    public function test_happy_path_records_serials_allocated_before_invoice_issued(): void
    {
        $path = array_map(
            static fn (HardwareFulfilmentState $state): string => $state->value,
            HardwareFulfilmentState::happyPath(),
        );

        $this->assertSame([
            'paid',
            'ingested',
            'ready_for_fulfilment',
            'serials_allocated',
            'invoice_issued',
            'shipment_created',
            'awb_assigned',
            'shipped',
            'synced',
        ], $path);

        $serials = array_search('serials_allocated', $path, true);
        $invoice = array_search('invoice_issued', $path, true);
        $this->assertNotFalse($serials);
        $this->assertNotFalse($invoice);
        $this->assertLessThan($invoice, $serials);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signedPost(array $payload, StatutoryInvoiceChannel $channel, string $secret)
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DESK_CHANNEL' => $channel->value,
            'HTTP_X_DESK_TIMESTAMP' => $timestamp,
            'HTTP_X_DESK_SIGNATURE' => (new ChannelIngestAuthenticator)->signature($timestamp, $body, $secret),
        ];

        return $this->call('POST', '/api/v1/channel-orders', [], [], [], $headers, $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function hardwarePayload(string $sourceId): array
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
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'RADium Desk',
            'place_of_supply_state' => 'Madhya Pradesh',
            'metadata' => [
                'ordertype' => 'hardware',
                'retry_count' => 1,
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
                'product_id' => 100,
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function servicePayload(): array
    {
        return [
            'channel' => StatutoryInvoiceChannel::RdServiceIn->value,
            'source_type' => 'commerce_order',
            'source_id' => 'RD-1001',
            'source_order_id' => 'RD-1001',
            'payment_status' => 'paid',
            'payment_provider' => 'cashfree',
            'payment_reference' => 'pay_RD-1001',
            'currency' => 'INR',
            'customer' => [
                'name' => 'Walk-in',
                'phone' => '9000000001',
            ],
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'RADium Desk',
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

    private function assertNoStatutorySideEffects(): void
    {
        $this->assertFalse((bool) config('channel_ingest.auto_issue_invoice'));
        $this->assertSame(0, StatutoryInvoice::query()->count());
        $this->assertSame(0, InvoiceSequence::query()->count());
        $this->assertSame(0, InvoiceSequenceAllocation::query()->count());
        $this->assertSame(0, FinanceJournal::query()->count());
    }
}

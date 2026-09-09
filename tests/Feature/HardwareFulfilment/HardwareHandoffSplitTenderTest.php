<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentPaymentEvidence;
use App\Models\InventoryProduct;
use App\Models\Order;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\HardwareFulfilment\HardwareFulfilmentIsolatedWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HardwareHandoffSplitTenderTest extends TestCase
{
    use RefreshDatabase;

    private const BOX_SECRET = 'test-radiumbox-secret';

    private HardwareFulfilmentIsolatedWorkflowService $isolated;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'channel_ingest.secrets.rdservice_in' => 'test-rdservice-in-secret',
            'channel_ingest.secrets.radiumbox_com' => self::BOX_SECRET,
            'channel_ingest.auto_issue_invoice' => false,
            'hardware_fulfilment.correlate_cashfree' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
        ]);

        $this->isolated = app(HardwareFulfilmentIsolatedWorkflowService::class);
        $product = InventoryProduct::query()->create([
            'sku' => 'RBMFS110L1',
            'name' => 'Mantra MFS 110 L1',
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

    public function test_single_cashfree_handoff_does_not_create_wallet_tender(): void
    {
        $sourceId = 'RDE900801';
        $this->isolated->run(identifier: $sourceId, step: 'ingest', payload: $this->fullCashfreePayload($sourceId));

        $order = CommerceOrder::query()->where('source_id', $sourceId)->firstOrFail();
        $this->assertSame('2499.00', $order->order_value);
        $this->assertNull($order->wallet_tender_amount);
        $this->assertNull($order->wallet_tender_reference);
        $this->assertSame('2499.00', $order->items()->first()?->line_total);
        $this->assertSame(0, HardwareFulfilmentPaymentEvidence::query()->count());
    }

    public function test_split_tender_persists_wallet_and_keeps_cashfree_and_line_intact(): void
    {
        $sourceId = 'RDE900802';
        $support = Order::query()->create([
            'order_id' => $sourceId,
            'product_name' => null,
            'status' => 'active',
            'cashfree_payment_id' => 'cf_pay_900802',
            'payment_amount' => 2000,
            'payment_method' => 'UPI',
        ]);

        $result = $this->isolated->run(
            identifier: $sourceId,
            step: 'ingest',
            payload: $this->splitPayload($sourceId, cashfreeAmount: 2000, walletAmount: 499, cashfreeReference: 'cf_pay_900802'),
        );

        $this->assertFalse($result['duplicate'] ?? false);
        $order = CommerceOrder::query()->where('source_id', $sourceId)->firstOrFail();
        $line = $order->items()->firstOrFail();
        $fulfilment = HardwareFulfilment::query()->where('source_id', $sourceId)->firstOrFail();

        $this->assertSame('2499.00', $order->order_value);
        $this->assertSame('499.00', $order->wallet_tender_amount);
        $this->assertNull($order->wallet_tender_reference);
        $this->assertSame('cashfree', $order->payment_provider);
        $this->assertSame('Mantra MFS 100 / 110 L1 Fingerprint Scanner', $line->description);
        $this->assertSame('946', $line->sku);
        $this->assertSame('PMTMFS110Z', $line->catalog_sku);
        $this->assertSame(946, (int) $line->model_id);
        $this->assertSame(944, (int) $line->product_id);
        $this->assertSame(1, (int) $line->qty);
        $this->assertSame('84716050', $line->hsn_sac);
        $this->assertSame('2499.00', $line->unit_price);
        $this->assertSame('2499.00', $line->line_total);
        $this->assertSame('cf_pay_900802', $fulfilment->cashfree_payment_id);
        $this->assertSame((int) $support->id, (int) $fulfilment->support_order_id);
        $this->assertSame('2000.00', $support->fresh()->payment_amount);
        $this->assertSame('cf_pay_900802', $support->fresh()->cashfree_payment_id);
        $this->assertSame(0, HardwareFulfilmentPaymentEvidence::query()->count());
    }

    public function test_repeated_split_handoff_is_idempotent(): void
    {
        $sourceId = 'RDE900803';
        Order::query()->create([
            'order_id' => $sourceId,
            'status' => 'active',
            'cashfree_payment_id' => 'cf_pay_900803',
            'payment_amount' => 2000,
        ]);
        $payload = $this->splitPayload($sourceId, cashfreeAmount: 2000, walletAmount: 499, cashfreeReference: 'cf_pay_900803');

        $this->isolated->run(identifier: $sourceId, step: 'ingest', payload: $payload);
        $second = $this->isolated->run(identifier: $sourceId, step: 'ingest', payload: $payload);

        $this->assertTrue($second['duplicate']);
        $this->assertSame(1, CommerceOrder::query()->count());
        $this->assertSame(1, CommerceOrder::query()->firstOrFail()->items()->count());
        $this->assertSame(1, HardwareFulfilment::query()->count());
        $this->assertSame(0, HardwareFulfilmentPaymentEvidence::query()->count());
        $this->assertSame('499.00', CommerceOrder::query()->firstOrFail()->wallet_tender_amount);
    }

    public function test_invalid_tender_sum_is_rejected_without_commerce_or_evidence(): void
    {
        $sourceId = 'RDE900804';
        Order::query()->create([
            'order_id' => $sourceId,
            'status' => 'active',
            'cashfree_payment_id' => 'cf_pay_900804',
            'payment_amount' => 2000,
        ]);

        try {
            $this->isolated->run(
                identifier: $sourceId,
                step: 'ingest',
                payload: $this->splitPayload($sourceId, cashfreeAmount: 2000, walletAmount: 400, cashfreeReference: 'cf_pay_900804'),
            );
            $this->fail('Invalid tender sum must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('tenders', $exception->errors());
        }

        $this->assertSame(0, CommerceOrder::query()->count());
        $this->assertSame(0, HardwareFulfilment::query()->count());
        $this->assertSame(0, HardwareFulfilmentPaymentEvidence::query()->count());
        $this->assertSame('2000.00', Order::query()->where('order_id', $sourceId)->value('payment_amount'));
    }

    public function test_wallet_cannot_reuse_cashfree_payment_id(): void
    {
        $sourceId = 'RDE900805';
        Order::query()->create([
            'order_id' => $sourceId,
            'status' => 'active',
            'cashfree_payment_id' => 'cf_pay_900805',
            'payment_amount' => 2000,
        ]);

        $payload = $this->splitPayload($sourceId, cashfreeAmount: 2000, walletAmount: 499, cashfreeReference: 'cf_pay_900805');
        $payload['tenders'][1]['reference'] = 'cf_pay_900805';

        $this->expectException(ValidationException::class);
        $this->isolated->run(identifier: $sourceId, step: 'ingest', payload: $payload);
    }

    public function test_changed_wallet_amount_conflicts_instead_of_creating_a_second_order(): void
    {
        $sourceId = 'RDE900806';
        Order::query()->create([
            'order_id' => $sourceId,
            'status' => 'active',
            'cashfree_payment_id' => 'cf_pay_900806',
            'payment_amount' => 2000,
        ]);
        $first = $this->splitPayload($sourceId, cashfreeAmount: 2000, walletAmount: 499, cashfreeReference: 'cf_pay_900806');
        $this->isolated->run(identifier: $sourceId, step: 'ingest', payload: $first);

        $changed = $first;
        $changed['tenders'][1]['reference'] = 'box-wallet-note-1';

        $this->signedBoxPost($changed)->assertStatus(409);

        $this->assertSame(1, CommerceOrder::query()->count());
        $this->assertSame('2499.00', CommerceOrder::query()->firstOrFail()->order_value);
        $this->assertSame('499.00', CommerceOrder::query()->firstOrFail()->wallet_tender_amount);
    }

    public function test_service_channel_rejects_tenders_without_creating_an_order(): void
    {
        $payload = [
            'channel' => StatutoryInvoiceChannel::RdServiceIn->value,
            'source_type' => 'commerce_order',
            'source_id' => 'RD900807',
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer' => ['name' => 'Walk-in', 'phone' => '9000000001'],
            'seller_gstin' => '07AAICP1128M1Z9',
            'place_of_supply_state' => 'Delhi',
            'tenders' => [
                ['type' => 'cashfree', 'amount' => 100],
                ['type' => 'wallet', 'amount' => 18],
            ],
            'lines' => [[
                'description' => 'RD Service',
                'sku' => 'RD-SVC',
                'qty' => 1,
                'unit_price' => 118,
                'hsn_sac' => '998313',
                'line_total' => 118,
            ]],
        ];

        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $this->call('POST', '/api/v1/channel-orders', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DESK_CHANNEL' => StatutoryInvoiceChannel::RdServiceIn->value,
            'HTTP_X_DESK_TIMESTAMP' => $timestamp,
            'HTTP_X_DESK_SIGNATURE' => (new ChannelIngestAuthenticator)->signature($timestamp, $body, 'test-rdservice-in-secret'),
        ], $body)->assertStatus(422);

        $this->assertSame(0, CommerceOrder::query()->count());
    }

    public function test_cashfree_tender_must_match_existing_support_payment_amount(): void
    {
        $sourceId = 'RDE900808';
        Order::query()->create([
            'order_id' => $sourceId,
            'status' => 'active',
            'cashfree_payment_id' => 'cf_pay_900808',
            'payment_amount' => 2000,
        ]);

        try {
            $this->isolated->run(
                identifier: $sourceId,
                step: 'ingest',
                payload: $this->splitPayload($sourceId, cashfreeAmount: 2100, walletAmount: 399, cashfreeReference: 'cf_pay_900808'),
            );
            $this->fail('Cashfree tender must not overwrite or disagree with support payment_amount.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('tenders', $exception->errors());
        }

        $this->assertSame(0, CommerceOrder::query()->count());
        $this->assertSame('2000.00', Order::query()->where('order_id', $sourceId)->value('payment_amount'));
    }

    public function test_cashfree_tender_reference_must_match_existing_support_payment_id(): void
    {
        $sourceId = 'RDE900813';
        Order::query()->create([
            'order_id' => $sourceId,
            'status' => 'active',
            'cashfree_payment_id' => 'cf_pay_900813',
            'payment_amount' => 2000,
        ]);

        try {
            $this->isolated->run(
                identifier: $sourceId,
                step: 'ingest',
                payload: $this->splitPayload($sourceId, cashfreeAmount: 2000, walletAmount: 499, cashfreeReference: 'cf_pay_WRONG'),
            );
            $this->fail('Cashfree tender reference must match the existing support payment id.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('tenders', $exception->errors());
        }

        $this->assertSame(0, CommerceOrder::query()->count());
        $this->assertSame('cf_pay_900813', Order::query()->where('order_id', $sourceId)->value('cashfree_payment_id'));
        $this->assertSame('2000.00', Order::query()->where('order_id', $sourceId)->value('payment_amount'));
    }

    public function test_missing_tender_amount_is_rejected(): void
    {
        $sourceId = 'RDE900809';
        Order::query()->create([
            'order_id' => $sourceId,
            'status' => 'active',
            'cashfree_payment_id' => 'cf_pay_900809',
            'payment_amount' => 2000,
        ]);
        $payload = $this->splitPayload($sourceId, cashfreeAmount: 2000, walletAmount: 499, cashfreeReference: 'cf_pay_900809');
        unset($payload['tenders'][1]['amount']);

        $this->signedBoxPost($payload)->assertStatus(422);
        $this->assertSame(0, CommerceOrder::query()->count());
        $this->assertSame(0, HardwareFulfilmentPaymentEvidence::query()->count());
    }

    public function test_invalid_tender_type_is_rejected(): void
    {
        $sourceId = 'RDE900810';
        Order::query()->create([
            'order_id' => $sourceId,
            'status' => 'active',
            'cashfree_payment_id' => 'cf_pay_900810',
            'payment_amount' => 2000,
        ]);
        $payload = $this->splitPayload($sourceId, cashfreeAmount: 2000, walletAmount: 499, cashfreeReference: 'cf_pay_900810');
        $payload['tenders'][1]['type'] = 'upi';

        $this->signedBoxPost($payload)->assertStatus(422);
        $this->assertSame(0, CommerceOrder::query()->count());
    }

    public function test_duplicate_cashfree_reference_on_another_source_is_rejected(): void
    {
        $first = 'RDE900811';
        $second = 'RDE900812';
        Order::query()->create([
            'order_id' => $first,
            'status' => 'active',
            'cashfree_payment_id' => 'cf_pay_900811',
            'payment_amount' => 2000,
        ]);
        Order::query()->create([
            'order_id' => $second,
            'status' => 'active',
            'cashfree_payment_id' => 'cf_pay_900812',
            'payment_amount' => 2000,
        ]);
        $this->isolated->run(
            identifier: $first,
            step: 'ingest',
            payload: $this->splitPayload($first, cashfreeAmount: 2000, walletAmount: 499, cashfreeReference: 'cf_pay_900811'),
        );

        try {
            $this->isolated->run(
                identifier: $second,
                step: 'ingest',
                payload: $this->splitPayload($second, cashfreeAmount: 2000, walletAmount: 499, cashfreeReference: 'cf_pay_900811'),
            );
            $this->fail('Duplicate Cashfree tender reference must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('tenders', $exception->errors());
        }

        $this->assertSame(1, CommerceOrder::query()->count());
        $this->assertSame($first, CommerceOrder::query()->value('source_id'));
        $this->assertSame('2000.00', Order::query()->where('order_id', $second)->value('payment_amount'));
    }

    /**
     * @return array<string, mixed>
     */
    private function fullCashfreePayload(string $sourceId): array
    {
        $payload = $this->splitPayload($sourceId, cashfreeAmount: 2499, walletAmount: 499);
        unset($payload['tenders']);
        $payload['payment_reference'] = 'pay_'.$sourceId;

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function splitPayload(
        string $sourceId,
        float $cashfreeAmount,
        float $walletAmount,
        ?string $cashfreeReference = null,
    ): array {
        return [
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom->value,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'payment_status' => 'paid',
            'payment_provider' => 'cashfree',
            'payment_reference' => $cashfreeReference ?? 'pay_'.$sourceId,
            'currency' => 'INR',
            'ordered_at' => '2026-09-07T17:40:21+05:30',
            'customer' => [
                'name' => 'Hardware Buyer',
                'phone' => '9000000099',
            ],
            'seller_gstin' => '07AAICP1128M1Z9',
            'place_of_supply_state' => 'West Bengal',
            'lines' => [[
                'description' => 'Mantra MFS 100 / 110 L1 Fingerprint Scanner',
                'sku' => '946',
                'catalog_sku' => 'PMTMFS110Z',
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
                'product_id' => 944,
            ]],
            'tenders' => [
                ['type' => 'cashfree', 'amount' => $cashfreeAmount, 'reference' => $cashfreeReference],
                ['type' => 'wallet', 'amount' => $walletAmount],
            ],
        ];
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
}

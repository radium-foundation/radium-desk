<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CashfreeWebhookLog;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\HardwareFulfilment;
use App\Models\InventoryProduct;
use App\Models\Order;
use App\Services\Cashfree\CashfreeWebhookPayloadParser;
use App\Services\HardwareFulfilment\RbpHardwareFulfilmentGapRecoveryService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbpHardwareFulfilmentGapRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'RBP940001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        config([
            'hardware_fulfilment.rbp_gap_recovery.allowlist' => [self::SOURCE],
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

    public function test_dry_run_does_not_create_fulfilment(): void
    {
        $this->seedEligibleGap();

        $result = app(RbpHardwareFulfilmentGapRecoveryService::class)->recoverOne(self::SOURCE, false);

        $this->assertSame('dry_run', $result['status']);
        $this->assertSame(0, HardwareFulfilment::query()->count());
    }

    public function test_execute_creates_single_fulfilment_when_cashfree_verified(): void
    {
        $this->seedEligibleGap();

        $result = app(RbpHardwareFulfilmentGapRecoveryService::class)->recoverOne(self::SOURCE, true);

        $this->assertSame('recovered', $result['status']);
        $this->assertSame(1, HardwareFulfilment::query()->count());
        $hf = HardwareFulfilment::query()->first();
        $this->assertSame(self::SOURCE, strtoupper($hf->source_id));
        $this->assertContains($hf->state, [HardwareFulfilmentState::Ingested, HardwareFulfilmentState::ReadyForFulfilment]);
    }

    public function test_second_execute_is_idempotent(): void
    {
        $this->seedEligibleGap();
        $svc = app(RbpHardwareFulfilmentGapRecoveryService::class);
        $svc->recoverOne(self::SOURCE, true);
        $again = $svc->recoverOne(self::SOURCE, true);

        $this->assertSame('skipped', $again['status']);
        $this->assertSame(1, HardwareFulfilment::query()->count());
    }

    public function test_rejects_order_not_on_allowlist(): void
    {
        $this->seedEligibleGap();
        $result = app(RbpHardwareFulfilmentGapRecoveryService::class)->recoverOne('RBP940002', true);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('not_allowlisted', $result['code']);
    }

    private function seedEligibleGap(): void
    {
        Order::query()->create([
            'order_id' => self::SOURCE,
            'cashfree_payment_id' => 'cf-pay-940001',
            'payment_amount' => 2499,
            'product_name' => 'Hardware',
            'status' => 'active',
            'created_at' => '2026-11-14 10:00:00',
        ]);

        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-'.self::SOURCE,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => self::SOURCE,
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:'.self::SOURCE,
            'payload_hash' => hash('sha256', self::SOURCE),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'payment_reference' => 'cf-pay-940001',
            'currency' => 'INR',
            'order_value' => 2499,
            'received_at' => now()->subDay(),
            'created_at' => now()->subDay(),
        ]);

        CommerceOrderItem::query()->create([
            'commerce_order_id' => $commerce->id,
            'line_no' => 1,
            'sku' => '946',
            'shipping_line_kind' => 'physical_merchandise',
            'requires_shipping' => true,
            'model_id' => 946,
            'description' => 'MFS110',
            'qty' => 1,
            'unit_price' => 2499,
            'line_total' => 2499,
        ]);

        $payload = [
            'type' => CashfreeWebhookPayloadParser::EVENT_PAYMENT_SUCCESS,
            'data' => [
                'payment' => [
                    'cf_payment_id' => 'cf-pay-940001',
                    'payment_status' => CashfreeWebhookPayloadParser::PAYMENT_STATUS_SUCCESS,
                    'payment_amount' => 2499,
                ],
            ],
        ];

        CashfreeWebhookLog::query()->create([
            'cf_payment_id' => 'cf-pay-940001',
            'request_payload' => $payload,
            'request_headers' => [],
            'raw_body' => json_encode($payload, JSON_THROW_ON_ERROR),
            'processing_status' => 'processed',
            'received_at' => now(),
        ]);
    }
}

<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\InventoryProduct;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\HardwareFulfilment\HardwareFulfilmentIsolatedWorkflowService;
use App\Services\HardwareFulfilment\HardwareOwnerAuthorizedExistingCommerceProfiles;
use App\Services\HardwareFulfilment\HardwareRecoveredFulfilmentAuthorization;
use App\Services\HardwareFulfilment\RbpHardwareFulfilmentGapMonitor;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class HardwareRbpAutomaticFulfilmentJourneyTest extends TestCase
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
            'hardware_fulfilment.rbp_gap_monitor.grace_minutes' => 30,
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

    public function test_paid_rbp_physical_channel_ingest_opens_exactly_one_hardware_fulfilment(): void
    {
        $this->signedBoxPost($this->eligibleBoxPayload('RBP910001'))->assertCreated();

        $this->assertSame(1, CommerceOrder::query()->count());
        $this->assertSame(1, HardwareFulfilment::query()->count());
        $fulfilment = HardwareFulfilment::query()->firstOrFail();
        $this->assertSame('RBP910001', $fulfilment->source_id);
        $this->assertSame(HardwareFulfilmentState::ReadyForFulfilment, $fulfilment->state);
    }

    #[Group('permanent_regression')]
    public function test_unpaid_rbp_does_not_open_hardware_fulfilment(): void
    {
        $payload = $this->eligibleBoxPayload('RBP910002');
        $payload['payment_status'] = 'pending';
        $this->signedBoxPost($payload)->assertCreated();

        $this->assertSame(1, CommerceOrder::query()->count());
        $this->assertSame(0, HardwareFulfilment::query()->count());
    }

    public function test_failed_payment_rbp_does_not_open_hardware_fulfilment(): void
    {
        $payload = $this->eligibleBoxPayload('RBP910003');
        $payload['payment_status'] = 'failed';
        $this->signedBoxPost($payload)->assertCreated();

        $this->assertSame(0, HardwareFulfilment::query()->count());
    }

    public function test_refunded_payment_rbp_does_not_open_hardware_fulfilment(): void
    {
        $payload = $this->eligibleBoxPayload('RBP910007');
        $payload['payment_status'] = 'refunded';
        $this->signedBoxPost($payload)->assertCreated();

        $this->assertSame(0, HardwareFulfilment::query()->count());
    }

    public function test_non_physical_rbp_does_not_open_hardware_fulfilment(): void
    {
        $payload = $this->eligibleBoxPayload('RBP910004');
        $payload['lines'] = [[
            'description' => 'Renewal',
            'sku' => 'renewal',
            'qty' => 1,
            'unit_price' => 499,
            'hsn_sac' => '998313',
            'gst_percentage' => 18,
            'taxable_value' => 423.73,
            'tax_total' => 75.27,
            'line_total' => 499,
            'shipping_line_kind' => 'service',
            'requires_shipping' => false,
        ]];
        $this->signedBoxPost($payload)->assertCreated();

        $this->assertSame(0, HardwareFulfilment::query()->count());
    }

    public function test_duplicate_box_handoff_is_idempotent_for_commerce_and_fulfilment(): void
    {
        $payload = $this->eligibleBoxPayload('RBP910005');
        $this->signedBoxPost($payload)->assertCreated();
        $this->signedBoxPost($payload)->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame(1, CommerceOrder::query()->count());
        $this->assertSame(1, HardwareFulfilment::query()->count());
    }

    public function test_repeated_channel_ingestion_keeps_single_fulfilment(): void
    {
        $payload = $this->eligibleBoxPayload('RBP910006');
        for ($i = 0; $i < 3; $i++) {
            $response = $this->signedBoxPost($payload);
            $this->assertContains($response->getStatusCode(), [201, 200]);
        }

        $this->assertSame(1, HardwareFulfilment::query()->count());
    }

    public function test_rde_channel_ingest_still_opens_hardware_fulfilment(): void
    {
        $this->signedBoxPost($this->eligibleBoxPayload('RDE910101'))->assertCreated();
        $this->assertSame(1, HardwareFulfilment::query()->count());
    }

    public function test_unauthorized_rbp_recovery_allowlist_remains_fail_closed(): void
    {
        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-999999',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => 'RBP9999',
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:RBP9999',
            'payload_hash' => hash('sha256', 'RBP9999'),
            'status' => CommerceOrderStatus::Validated,
            'payment_status' => 'paid',
            'payment_reference' => 'pay_999',
            'currency' => 'INR',
            'received_at' => now(),
        ]);

        $result = app(HardwareRecoveredFulfilmentAuthorization::class)->authorizeOne(
            $order,
            HardwareRecoveredFulfilmentAuthorization::PURPOSE_OWNER_EXISTING_COMMERCE,
            'test',
        );
        $this->assertFalse($result['ok']);
        $this->assertFalse(HardwareOwnerAuthorizedExistingCommerceProfiles::has('RBP9999'));
    }

    public function test_gap_monitor_detects_stale_paid_rbp_without_fulfilment(): void
    {
        Carbon::setTestNow('2026-11-14 10:00:00');
        $this->signedBoxPost($this->eligibleBoxPayload('RBP910020'))->assertCreated();
        HardwareFulfilment::query()->delete();

        Carbon::setTestNow('2026-11-14 12:00:00');
        config(['hardware_fulfilment.rbp_gap_monitor.grace_minutes' => 30]);

        $gaps = app(RbpHardwareFulfilmentGapMonitor::class)->findGaps();
        $this->assertNotEmpty($gaps);
        $this->assertSame('RBP910020', $gaps[0]['source_id']);
    }

    public function test_gap_monitor_ignores_unpaid_rbp_commerce(): void
    {
        Carbon::setTestNow('2026-11-14 12:00:00');
        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-091401',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => 'RBP910021',
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:RBP910021',
            'payload_hash' => hash('sha256', 'unpaid'),
            'status' => CommerceOrderStatus::Validated,
            'payment_status' => 'pending',
            'payment_reference' => 'pay_pending',
            'currency' => 'INR',
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

    public function test_isolated_recovery_without_owner_auth_still_blocked_for_allowlisted_rbp2138(): void
    {
        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-022155',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => 'RBP2138',
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:RBP2138',
            'payload_hash' => hash('sha256', 'rbp2138'),
            'status' => CommerceOrderStatus::Validated,
            'payment_status' => 'paid',
            'payment_reference' => '6998469575',
            'currency' => 'INR',
            'ordered_at' => '2026-09-06 10:00:00',
            'received_at' => now(),
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

        try {
            app(HardwareFulfilmentIsolatedWorkflowService::class)
                ->run(identifier: 'RBP2138', step: 'ingest');
            $this->fail('Isolated ingest without Owner authorization must fail.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Owner authorization', collect($exception->errors())->flatten()->first() ?? '');
        }
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

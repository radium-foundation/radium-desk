<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\HardwareFulfilmentSerialStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\HardwareWorkspaceFilter;
use App\Enums\InventorySerialStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentEvent;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventorySerial;
use App\Models\InventoryUserBranch;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentOperationalClassifier;
use App\Services\HardwareFulfilment\HardwareFulfilmentInvoiceService;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use App\Services\HardwareFulfilment\HardwareHistoricalDuplicateFulfilmentCancellationService;
use App\Services\HardwareFulfilment\HardwareSerialAllocationService;
use App\Services\HardwareFulfilment\HardwareShipmentEligibility;
use App\Services\Inventory\InventoryStockService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Feature\HardwareFulfilment\Support\SelectsHardwareTestCourier;
use Tests\TestCase;

class HardwareHistoricalDuplicateFulfilmentCancellationTest extends TestCase
{
    use RefreshDatabase;
    use SelectsHardwareTestCourier;

    private const BOX_SECRET = 'test-radiumbox-secret';

    private User $admin;

    private InventoryProduct $product;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-18 12:00:00');
        $this->configureLocationSellerIdentity();
        config([
            'channel_ingest.secrets.radiumbox_com' => self::BOX_SECRET,
            'channel_ingest.auto_issue_invoice' => false,
            'hardware_fulfilment.correlate_cashfree' => false,
            'hardware_fulfilment.callback.enabled' => false,
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);

        $this->product = InventoryProduct::query()->create([
            'sku' => 'RBBIOC600C',
            'name' => 'BioEnable C600',
            'hsn_code' => '85258090',
            'gst_percentage' => 18,
            'unit_price' => 3399,
            'is_serialized' => true,
            'is_active' => true,
        ]);

        ChannelSkuMap::query()->create([
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'model_id' => 1749,
            'inventory_product_id' => $this->product->id,
            'catalog_sku' => 'BIOC600',
            'channel_sku' => 'RBBIOC600C',
        ]);

        $this->admin = $this->userWithRole(RolePermissionSeeder::ROLE_ADMIN, ['DELHI-RETAIL']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_historical_duplicate_eligibility_requires_completed_support_order_with_shipment_evidence(): void
    {
        $fulfilment = $this->duplicateScenario(sourceId: 'RDE-HIST-001', historicalCompletedAt: null);

        $this->expectException(ValidationException::class);
        app(HardwareHistoricalDuplicateFulfilmentCancellationService::class)
            ->assertEligible($fulfilment->fresh(['commerceOrder', 'shipment', 'statutoryInvoice', 'serials.inventorySerial.product']));
    }

    public function test_cancel_releases_serial_once_and_cancels_invoice_without_shiprocket_mutation(): void
    {
        $fulfilment = $this->duplicateScenario(
            sourceId: 'RDE318338',
            serialNumber: '152072025008070016',
            historicalCompletedAt: '2026-09-05 13:55:58',
            historicalTransactionId: 'Bluedart 77167070912',
            historicalSupportSerial: '152072025008070012',
            externalOrderId: '1594977227',
            externalShipmentId: '1591193505',
        );

        $service = app(HardwareHistoricalDuplicateFulfilmentCancellationService::class);
        $idempotencyKey = 'historical-duplicate-cancel:RDE318338';

        $first = $service->cancel(
            $fulfilment,
            $this->admin,
            'Owner-approved duplicate Desk fulfilment cancellation.',
            $idempotencyKey,
            'IND671904',
        );

        $this->assertFalse($first['idempotent']);
        $this->assertSame(['152072025008070016'], $first['released_serials']);

        $fresh = $fulfilment->fresh(['statutoryInvoice', 'shipment', 'serials', 'commerceOrder']);
        $this->assertSame(HardwareFulfilmentState::CancelledHistoricalDuplicate, $fresh->state);
        $this->assertSame(StatutoryInvoiceStatus::Cancelled, $fresh->statutoryInvoice?->status);
        $this->assertStringContainsString('IND671904', (string) $fresh->statutoryInvoice?->cancel_reason);
        $this->assertSame('152072025008070016', $fresh->serials->first()?->serial_number);
        $this->assertSame(HardwareFulfilmentSerialStatus::Released, $fresh->serials->first()?->status);

        $inventorySerial = InventorySerial::query()->where('serial_number', '152072025008070016')->firstOrFail();
        $this->assertSame(InventorySerialStatus::Available, $inventorySerial->status);
        $this->assertSame($this->product->id, $inventorySerial->product_id);
        $this->assertSame($this->branch('DELHI-RETAIL')->id, $inventorySerial->branch_id);

        $historicalSerial = InventorySerial::query()->where('serial_number', '152072025008070012')->firstOrFail();
        $this->assertSame(InventorySerialStatus::Available, $historicalSerial->status);

        $shipment = Shipment::query()->findOrFail($fresh->shipment_id);
        $this->assertNull($shipment->awb);
        $this->assertSame('1591193505', $shipment->external_shipment_id);
        $this->assertSame('HTTP 400 — Given courier not serviceable.', $shipment->last_error);

        $second = $service->cancel(
            $fresh,
            $this->admin,
            'Owner-approved duplicate Desk fulfilment cancellation.',
            $idempotencyKey,
            'IND671904',
        );
        $this->assertTrue($second['idempotent']);
        $this->assertSame(1, HardwareFulfilmentEvent::query()->where('payload->reason', HardwareHistoricalDuplicateFulfilmentCancellationService::REASON_EVENT)->count());
        $this->assertSame(1, StatutoryInvoice::query()->where('source_id', 'RDE318338')->count());
    }

    public function test_wrong_fulfilment_without_historical_evidence_is_rejected(): void
    {
        $fulfilment = $this->duplicateScenario(
            sourceId: 'RDE-HIST-002',
            historicalCompletedAt: '2026-09-05 13:55:58',
            historicalTransactionId: 'Bluedart 77167070912',
        );
        $fulfilment->supportOrder?->forceFill(['completed_at' => null, 'transaction_id' => null])->save();

        $this->expectException(ValidationException::class);
        app(HardwareHistoricalDuplicateFulfilmentCancellationService::class)
            ->assertEligible($fulfilment->fresh(['commerceOrder', 'shipment', 'statutoryInvoice', 'serials.inventorySerial.product']));
    }

    public function test_non_sold_inventory_serial_blocks_cancellation(): void
    {
        $fulfilment = $this->duplicateScenario(
            sourceId: 'RDE-HIST-003',
            serialNumber: '152072025008070099',
            historicalCompletedAt: '2026-09-05 13:55:58',
            historicalTransactionId: 'Bluedart 77167070912',
        );

        InventorySerial::query()
            ->where('serial_number', '152072025008070099')
            ->update(['status' => InventorySerialStatus::Available->value]);

        $this->expectException(ValidationException::class);
        app(HardwareHistoricalDuplicateFulfilmentCancellationService::class)->cancel(
            $fulfilment->fresh(['commerceOrder', 'shipment', 'statutoryInvoice', 'serials.inventorySerial.product']),
            $this->admin,
            'Attempt invalid serial state',
            'historical-duplicate-cancel:RDE-HIST-003',
            'IND671904',
        );
    }

    public function test_cancelled_fulfilment_is_excluded_from_active_shipment_workflows(): void
    {
        $fulfilment = $this->duplicateScenario(
            sourceId: 'RDE-HIST-005',
            historicalCompletedAt: '2026-09-05 13:55:58',
            historicalTransactionId: 'Bluedart 77167070912',
        );

        app(HardwareHistoricalDuplicateFulfilmentCancellationService::class)->cancel(
            $fulfilment,
            $this->admin,
            'Owner-approved duplicate Desk fulfilment cancellation.',
            'historical-duplicate-cancel:RDE-HIST-005',
            'IND671904',
        );

        $fresh = $fulfilment->fresh(['commerceOrder', 'shipment', 'statutoryInvoice', 'serials', 'packageEvidences']);
        $ready = app(HardwareShipmentEligibility::class)->inspect($fresh);
        $row = app(HardwareFulfilmentOperationalClassifier::class)->fromFulfilment($fresh, $ready);

        $this->assertFalse($ready->canCreate);
        $this->assertFalse($ready->canAssignAwb);
        $this->assertFalse($row->matchesWorkspaceFilter(HardwareWorkspaceFilter::NeedsAction));
        $this->assertFalse($row->matchesWorkspaceFilter(HardwareWorkspaceFilter::AwbPending));

        $this->expectException(ValidationException::class);
        app(HardwareShipmentEligibility::class)->require($fresh);
    }

    public function test_invoice_cancel_is_idempotent_via_statutory_service(): void
    {
        $fulfilment = $this->duplicateScenario(
            sourceId: 'RDE-HIST-006',
            historicalCompletedAt: '2026-09-05 13:55:58',
            historicalTransactionId: 'Bluedart 77167070912',
        );
        $invoice = $fulfilment->statutoryInvoice;

        app(StatutoryInvoiceService::class)->cancel($invoice, $this->admin, 'First cancel');
        app(StatutoryInvoiceService::class)->cancel($invoice->fresh(), $this->admin, 'Second cancel');

        $this->assertSame(StatutoryInvoiceStatus::Cancelled, $invoice->fresh()->status);
    }

    private function duplicateScenario(
        string $sourceId,
        string $serialNumber = '152072025008070016',
        ?string $historicalCompletedAt = '2026-09-05 13:55:58',
        ?string $historicalTransactionId = 'Bluedart 77167070912',
        ?string $historicalSupportSerial = '152072025008070012',
        ?string $externalOrderId = null,
        ?string $externalShipmentId = null,
    ): HardwareFulfilment {
        if ($historicalSupportSerial !== null) {
            app(InventoryStockService::class)->stockInSerialized(
                $this->product,
                $this->branch('DELHI-RETAIL'),
                [$historicalSupportSerial],
                $this->admin,
            );
        }

        $support = Order::query()->create([
            'order_id' => $sourceId,
            'customer_name' => 'Historical Customer',
            'customer_phone' => '7381012341',
            'customer_email' => 'historical@example.test',
            'cashfree_payment_id' => 'pay-'.$sourceId,
            'payment_amount' => '3399.00',
            'payment_method' => 'UPI',
            'payment_date' => '2026-09-05 11:09:37',
            'status' => 'active',
            'serial_number' => $historicalSupportSerial,
            'transaction_id' => $historicalTransactionId,
            'completed_at' => $historicalCompletedAt,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $fulfilment = $this->ingestHardware($sourceId, (int) $support->id);
        app(HardwareFulfilmentWorkflowService::class)->transition(
            $fulfilment,
            HardwareFulfilmentState::ReadyForFulfilment,
        );
        $this->stockAt('DELHI-RETAIL', [$serialNumber]);

        app(HardwareSerialAllocationService::class)->allocateSerials(
            $fulfilment,
            [$serialNumber],
            $this->admin,
        );

        app(HardwareFulfilmentInvoiceService::class)->issueInvoice($fulfilment->fresh(['commerceOrder.items']));
        $fulfilment = $fulfilment->fresh(['commerceOrder.items', 'statutoryInvoice']);

        $externalOrderId ??= '1594977227-'.$sourceId;
        $externalShipmentId ??= '1591193505-'.$sourceId;

        $shipment = Shipment::query()->create([
            'shipment_no' => 'HW-'.$sourceId,
            'commerce_order_id' => $fulfilment->commerce_order_id,
            'hardware_fulfilment_id' => $fulfilment->id,
            'provider' => 'shiprocket',
            'status' => 'created',
            'invoice_number' => $fulfilment->statutoryInvoice?->invoice_number,
            'serial_numbers' => json_encode([$serialNumber]),
            'pickup_location' => 'RADDELHI',
            'courier_id' => '15086',
            'courier_name' => 'Shadowfax_Surface',
            'external_order_id' => $externalOrderId,
            'external_shipment_id' => $externalShipmentId,
            'idempotency_key' => 'hardware:shiprocket:create:'.$fulfilment->id,
            'correlation_id' => 'test-correlation-'.$fulfilment->id,
            'failure_class' => 'provider_rejected',
            'last_error' => 'HTTP 400 — Given courier not serviceable.',
            'provider_accepted_at' => now(),
        ]);

        $fulfilment->forceFill([
            'state' => HardwareFulfilmentState::ShipmentCreated,
            'shipment_id' => $shipment->id,
            'shipment_no' => $shipment->shipment_no,
            'provider_shipment_id' => $externalShipmentId,
            'shipment_created_at' => now(),
            'selected_courier_id' => '15086',
            'selected_courier_name' => 'Shadowfax_Surface',
            'fulfilment_branch_id' => $this->branch('DELHI-RETAIL')->id,
        ])->save();

        CommerceOrder::query()->whereKey($fulfilment->commerce_order_id)->update([
            'support_order_id' => $support->id,
        ]);
        $fulfilment->forceFill(['support_order_id' => $support->id])->save();

        return $fulfilment->fresh(['commerceOrder.items', 'statutoryInvoice', 'shipment', 'serials.inventorySerial.product', 'supportOrder']);
    }

    private function ingestHardware(string $sourceId, int $supportOrderId): HardwareFulfilment
    {
        $payload = [
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom->value,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'source_order_id' => $sourceId,
            'support_order_id' => $supportOrderId,
            'payment_status' => 'paid',
            'payment_provider' => 'cashfree',
            'payment_reference' => 'pay_'.$sourceId,
            'currency' => 'INR',
            'customer' => [
                'name' => 'SANJIVANI HOSPITAL',
                'phone' => '7381012341',
                'email' => 'sanjivani.hospital.jsg@gmail.com',
                'gstin' => '21AAOPA5417F1Z8',
            ],
            'seller_gstin' => '07AAICP1128M1Z9',
            'place_of_supply_state' => 'Odisha',
            'shipping_address' => [
                'line1' => 'Ekatali,Siria Bagicha',
                'city' => 'Jharsuguda',
                'state' => 'Odisha',
                'pincode' => '768201',
                'country' => 'India',
            ],
            'lines' => [[
                'description' => 'BioEnable C600 Face Camera',
                'model_id' => 1749,
                'sku' => '1749',
                'qty' => 1,
                'unit_price' => 3399,
                'hsn_sac' => '85258090',
                'gst_percentage' => 18,
                'taxable_value' => 2880.51,
                'tax_total' => 518.49,
                'line_total' => 3399,
                'shipping_line_kind' => 'physical_merchandise',
                'requires_shipping' => true,
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

    /**
     * @param  list<string>  $serials
     */
    private function stockAt(string $branchCode, array $serials): void
    {
        app(InventoryStockService::class)->stockInSerialized(
            $this->product,
            $this->branch($branchCode),
            $serials,
            $this->admin,
        );
    }

    /**
     * @param  list<string>  $branchCodes
     */
    private function userWithRole(string $role, array $branchCodes): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        foreach ($branchCodes as $code) {
            InventoryUserBranch::query()->firstOrCreate([
                'user_id' => $user->id,
                'branch_id' => $this->branch($code)->id,
            ]);
        }

        return $user;
    }

    private function branch(string $code): InventoryBranch
    {
        return InventoryBranch::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $code, 'is_active' => true],
        );
    }
}

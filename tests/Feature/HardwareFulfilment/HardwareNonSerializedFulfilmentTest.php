<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\HardwareFulfilment;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventoryUserBranch;
use App\Models\User;
use App\Services\ChannelIngest\ChannelIngestAuthenticator;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkflowService;
use App\Services\HardwareFulfilment\HardwarePhysicalStockCommitment;
use App\Services\HardwareFulfilment\HardwareSerialAllocationService;
use App\Services\HardwareFulfilment\HardwareSkuMapService;
use App\Services\Inventory\InventoryStockService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HardwareNonSerializedFulfilmentTest extends TestCase
{
    use RefreshDatabase;

    private const BOX_SECRET = 'test-radiumbox-secret';

    private HardwareSerialAllocationService $allocation;

    private HardwareFulfilmentWorkflowService $workflow;

    private User $actor;

    private InventoryProduct $cableProduct;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'channel_ingest.secrets.radiumbox_com' => self::BOX_SECRET,
            'channel_ingest.auto_issue_invoice' => false,
            'hardware_fulfilment.correlate_cashfree' => false,
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
        ]);

        $this->allocation = app(HardwareSerialAllocationService::class);
        $this->workflow = app(HardwareFulfilmentWorkflowService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->cableProduct = InventoryProduct::query()->create([
            'sku' => 'RBMFSTYPEC',
            'name' => 'Mantra MFS110 Type-C cable',
            'hsn_code' => '85444299',
            'gst_percentage' => 18,
            'unit_price' => 299,
            'is_serialized' => false,
            'is_active' => true,
        ]);
        ChannelSkuMap::query()->create([
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'model_id' => 1409,
            'inventory_product_id' => $this->cableProduct->id,
            'catalog_sku' => 'PMTMFSCCBL',
            'channel_sku' => '1409',
        ]);
    }

    public function test_non_serialized_product_resolves_through_sku_map_service(): void
    {
        $product = app(HardwareSkuMapService::class)->requireProduct(
            StatutoryInvoiceChannel::RadiumBoxCom,
            1409,
        );

        $this->assertSame('RBMFSTYPEC', $product->sku);
        $this->assertFalse($product->is_serialized);
    }

    public function test_serial_allocation_rejects_non_serialized_mapped_product(): void
    {
        $fulfilment = $this->readyCableFulfilment('RBP103-TYPEC');

        $this->expectException(ValidationException::class);
        $this->allocation->allocateSerials($fulfilment, ['SN-SHOULD-NOT-ALLOCATE'], $this->actor);
    }

    public function test_quantity_stock_allocation_commits_without_serials(): void
    {
        $fulfilment = $this->readyCableFulfilment('RBP103-TYPEC');
        $branch = $this->stockQuantityAt('DELHI-RETAIL', 3);
        $fulfilment->forceFill(['fulfilment_branch_id' => $branch->id])->save();

        $allocated = $this->allocation->allocateQuantityStock($fulfilment, $this->actor);

        $this->assertSame(HardwareFulfilmentState::SerialsAllocated, $allocated->state);
        $this->assertSame([], $this->workflow->allocatedSerialNumbers($allocated));
        $commitment = app(HardwarePhysicalStockCommitment::class);
        $this->assertTrue($commitment->isStockCommitted($allocated, $allocated->commerceOrder));
        $this->assertSame(1, $commitment->quantityCommittedQty($allocated, (int) $allocated->commerceOrder->items->first()->id));
    }

    public function test_serialized_product_still_requires_serial_allocation(): void
    {
        $serialized = InventoryProduct::query()->create([
            'sku' => 'DESK-SERIAL-ONLY',
            'name' => 'Serialized device',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 3000,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        ChannelSkuMap::query()->create([
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'model_id' => 951,
            'inventory_product_id' => $serialized->id,
            'catalog_sku' => 'MSO1300',
            'channel_sku' => '951',
        ]);

        $fulfilment = $this->ingestHardware('RDE-SERIAL-ONLY', 951, 1);
        $branch = InventoryBranch::query()->firstOrCreate(['code' => 'DELHI-RETAIL'], ['name' => 'Delhi', 'is_active' => true]);
        app(InventoryStockService::class)->stockInSerialized($serialized, $branch, ['SN-ONLY-001'], $this->actor);
        InventoryUserBranch::query()->firstOrCreate(['user_id' => $this->actor->id, 'branch_id' => $branch->id]);
        $fulfilment->forceFill(['fulfilment_branch_id' => $branch->id])->save();
        $this->workflow->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);

        $this->expectException(ValidationException::class);
        $this->allocation->allocateQuantityStock($fulfilment->fresh(), $this->actor);
    }

    private function readyCableFulfilment(string $sourceId): HardwareFulfilment
    {
        $fulfilment = $this->ingestHardware($sourceId, 1409, 1);
        $this->workflow->transition($fulfilment, HardwareFulfilmentState::ReadyForFulfilment);

        return $fulfilment->fresh(['commerceOrder.items']) ?? $fulfilment;
    }

    private function stockQuantityAt(string $branchCode, int $qty): InventoryBranch
    {
        $branch = InventoryBranch::query()->firstOrCreate(
            ['code' => $branchCode],
            ['name' => $branchCode, 'is_active' => true],
        );
        InventoryUserBranch::query()->firstOrCreate([
            'user_id' => $this->actor->id,
            'branch_id' => $branch->id,
        ]);
        app(InventoryStockService::class)->stockInQuantity($this->cableProduct, $branch, $qty, $this->actor);

        return $branch;
    }

    private function ingestHardware(string $sourceId, int $modelId, int $qty): HardwareFulfilment
    {
        $taxable = round(253.39 * $qty, 2);
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
                'name' => 'Cable Buyer',
                'phone' => '9000000099',
            ],
            'seller_gstin' => '07AAICP1128M1Z9',
            'place_of_supply_state' => 'Delhi',
            'lines' => [[
                'description' => 'Biometric Replacement Cable',
                'sku' => (string) $modelId,
                'qty' => $qty,
                'unit_price' => 299,
                'hsn_sac' => '85444299',
                'gst_percentage' => 18,
                'taxable_value' => $taxable,
                'tax_total' => $tax,
                'line_total' => $lineTotal,
                'shipping_line_kind' => 'physical_merchandise',
                'requires_shipping' => true,
                'model_id' => $modelId,
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

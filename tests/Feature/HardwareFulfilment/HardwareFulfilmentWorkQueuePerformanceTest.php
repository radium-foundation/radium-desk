<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentSerialStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentSerial;
use App\Models\InventoryProduct;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\HardwareFulfilment\HardwareFulfilmentWorkQueue;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HardwareFulfilmentWorkQueuePerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config([
            'shipping.enabled' => false,
            'shipping.provider' => 'none',
            'shipping.http_enabled' => false,
        ]);

        $this->operator = User::factory()->create(['is_active' => true]);
        $this->operator->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);

        $product = InventoryProduct::query()->create([
            'sku' => 'RBMFS110L1',
            'name' => 'MFS110',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 2549,
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

    public function test_work_queue_index_avoids_per_row_sku_map_queries(): void
    {
        for ($index = 1; $index <= 12; $index++) {
            $this->ingestedFulfilment(sprintf('RDE9708%02d', $index));
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($this->operator)
            ->get(route('inventory.hardware-fulfilments.index', ['queue' => 'work']));

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertOk()
            ->assertSee('RDE970801')
            ->assertSee('RDE970812');

        $skuMapQueries = collect($queries)->filter(
            static fn (array $query): bool => str_contains($query['query'], 'channel_sku_maps')
        )->count();

        $this->assertSame(12, HardwareFulfilment::query()->count());
        $this->assertLessThanOrEqual(2, $skuMapQueries, 'Expected channel_sku_maps to be preloaded, not queried per row.');
        $this->assertLessThan(250, count($queries), 'Work queue index query count regressed.');
    }

    public function test_work_queue_paginate_preserves_stage_classification(): void
    {
        $awaitingSerial = $this->fulfilmentWithState('RDE970901', HardwareFulfilmentState::ReadyForFulfilment);
        $readyForShipment = $this->fulfilmentWithState('RDE970902', HardwareFulfilmentState::InvoiceIssued, invoice: true, serial: true);

        $from = HardwareFulfilmentEligibility::cutoffInstant();
        $to = Carbon::now(HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);
        $rows = app(HardwareFulfilmentWorkQueue::class)
            ->paginate($from, $to)
            ->getCollection()
            ->keyBy(static fn ($row) => $row->sourceId);

        $this->assertSame('Awaiting Serial', $rows['RDE970901']->statusLabel);
        $this->assertSame('Ready for Shipment', $rows['RDE970902']->statusLabel);
        $this->assertSame($awaitingSerial->id, $rows['RDE970901']->fulfilmentId);
        $this->assertSame($readyForShipment->id, $rows['RDE970902']->fulfilmentId);
    }

    private function ingestedFulfilment(string $sourceId): HardwareFulfilment
    {
        return $this->fulfilmentWithState($sourceId, HardwareFulfilmentState::Ingested);
    }

    private function fulfilmentWithState(
        string $sourceId,
        HardwareFulfilmentState $state,
        bool $invoice = false,
        bool $serial = false,
    ): HardwareFulfilment {
        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Perf Buyer',
            'received_at' => now(),
            'ordered_at' => now(),
        ]);
        CommerceOrderItem::query()->create([
            'commerce_order_id' => $commerce->id,
            'line_no' => 1,
            'sku' => 'RBMFS110L1',
            'catalog_sku' => 'MFS110',
            'model_id' => 946,
            'shipping_line_kind' => HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND,
            'requires_shipping' => true,
            'description' => 'MFS110',
            'qty' => 1,
            'unit_price' => 2549,
            'gst_percentage' => 18,
            'taxable_value' => 2160.17,
            'tax_total' => 388.83,
            'line_total' => 2549,
        ]);

        $fulfilment = HardwareFulfilment::query()->create([
            'commerce_order_id' => $commerce->id,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'idempotency_key' => $commerce->idempotency_key,
            'state' => $state,
            'ingested_at' => now(),
        ]);

        if ($invoice) {
            $invoiceModel = StatutoryInvoice::query()->create([
                'invoice_number' => 'INV-'.$sourceId,
                'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
                'status' => StatutoryInvoiceStatus::Issued,
                'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
                'source_type' => 'commerce_order',
                'source_id' => $sourceId,
                'idempotency_key' => 'invoice:'.$sourceId,
                'invoice_value' => 2549,
                'issued_at' => now(),
            ]);
            $fulfilment->forceFill(['statutory_invoice_id' => $invoiceModel->id])->save();
            $commerce->forceFill(['statutory_invoice_id' => $invoiceModel->id])->save();
        }

        if ($serial) {
            HardwareFulfilmentSerial::query()->create([
                'hardware_fulfilment_id' => $fulfilment->id,
                'line_no' => 1,
                'position' => 1,
                'serial_number' => 'SN-'.$sourceId,
                'status' => HardwareFulfilmentSerialStatus::Allocated,
                'allocated_at' => now(),
            ]);
        }

        return $fulfilment->fresh(['commerceOrder.items', 'statutoryInvoice', 'serials']);
    }
}

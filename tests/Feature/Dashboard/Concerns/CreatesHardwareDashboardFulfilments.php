<?php

namespace Tests\Feature\Dashboard\Concerns;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentPackageEvidenceKind;
use App\Enums\HardwareFulfilmentSerialStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\ShipmentStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentPackageEvidence;
use App\Models\HardwareFulfilmentSerial;
use App\Models\InventoryProduct;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

trait CreatesHardwareDashboardFulfilments
{
    protected function seedCatalog(): void
    {
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

    protected function fulfilment(
        string $sourceId,
        HardwareFulfilmentState $state,
        bool $serial = false,
        bool $invoice = false,
        bool $mapped = true,
        bool $bound = false,
        ?string $awb = null,
        bool $label = false,
        bool $pickup = false,
        bool $manifest = false,
        bool $evidence = false,
        ?string $track = null,
        bool $ready = false,
        bool $providerRejected = false,
        ?string $courierOptionsSnapshot = null,
        ?Carbon $courierOptionsExpiresAt = null,
    ): HardwareFulfilment {
        $creator = User::factory()->create(['is_active' => true]);
        $order = Order::query()->create([
            'order_id' => $sourceId,
            'product_name' => 'MFS 110',
            'status' => 'active',
            'customer_name' => 'Buyer '.$sourceId,
            'cashfree_payment_id' => 'cf_'.$sourceId,
            'created_by' => $creator->id,
        ]);
        $order->forceFill(['created_at' => '2026-09-07 10:00:00'])->save();

        $invoiceModel = null;
        if ($invoice) {
            $invoiceModel = StatutoryInvoice::query()->create([
                'invoice_number' => 'INV-'.$sourceId,
                'document_type' => 'tax_invoice',
                'status' => 'issued',
                'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
                'source_type' => 'commerce_order',
                'source_id' => $sourceId,
                'idempotency_key' => 'invoice:'.$sourceId,
                'invoice_value' => 2549,
                'issued_at' => now(),
            ]);
        }

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
            'customer_name' => 'Buyer '.$sourceId,
            'received_at' => now(),
            'ordered_at' => '2026-09-07 04:30:00',
            'support_order_id' => $order->id,
            'statutory_invoice_id' => $invoiceModel?->id,
        ]);
        CommerceOrderItem::query()->create([
            'commerce_order_id' => $commerce->id,
            'line_no' => 1,
            'sku' => 'RBMFS110L1',
            'catalog_sku' => 'MFS110',
            'model_id' => $mapped ? 946 : 99999,
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
            'support_order_id' => $order->id,
            'statutory_invoice_id' => $invoiceModel?->id,
            'ingested_at' => now(),
        ]);

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

        if ($bound || $awb !== null || $label || $pickup || $manifest || $providerRejected) {
            $shipment = Shipment::query()->create([
                'shipment_no' => 'HW-'.$sourceId,
                'commerce_order_id' => $commerce->id,
                'hardware_fulfilment_id' => $fulfilment->id,
                'provider' => 'shiprocket',
                'status' => $awb !== null ? ShipmentStatus::AwbAssigned : ShipmentStatus::Created,
                'external_order_id' => $bound ? 'ext-'.$sourceId : null,
                'external_shipment_id' => $bound ? 'shp-'.$sourceId : null,
                'awb' => $awb,
                'label_url' => $label ? '/labels/'.$sourceId.'.pdf' : null,
                'pickup_requested_at' => $pickup ? now() : null,
                'manifest_id' => $manifest ? 'man-'.$sourceId : null,
                'provider_track_normalized' => $track,
                'provider_track_status' => $track,
                'failure_class' => $providerRejected ? 'provider_rejected' : null,
                'last_error' => $providerRejected ? 'Shiprocket rejected shipment creation: authentication failed.' : null,
                'idempotency_key' => 'ship:'.$sourceId,
                'correlation_id' => (string) Str::uuid(),
            ]);
            $fulfilment->forceFill([
                'shipment_id' => $shipment->id,
                'awb' => $awb,
            ])->save();
        }

        if ($evidence) {
            HardwareFulfilmentPackageEvidence::query()->create([
                'hardware_fulfilment_id' => $fulfilment->id,
                'kind' => HardwareFulfilmentPackageEvidenceKind::PackageLabelApplied,
                'disk' => 'local',
                'path' => 'evidence/'.$sourceId.'.jpg',
                'original_filename' => $sourceId.'.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 12,
                'uploaded_by_user_id' => $creator->id,
                'uploaded_at' => now(),
            ]);
        }

        if ($ready) {
            $readyUpdates = ['ready_for_pickup_at' => now()];
            if ($state !== HardwareFulfilmentState::CancelledHistoricalDuplicate) {
                $readyUpdates['state'] = HardwareFulfilmentState::AwbAssigned;
            }
            $fulfilment->forceFill($readyUpdates)->save();
        }

        if ($courierOptionsSnapshot !== null || $courierOptionsExpiresAt !== null) {
            $fulfilment->forceFill([
                'courier_options_snapshot' => $courierOptionsSnapshot ?? ['courier_id' => '15137'],
                'courier_options_expires_at' => $courierOptionsExpiresAt ?? now()->addHour(),
            ])->save();
        }

        return $fulfilment->fresh() ?? $fulfilment;
    }
}

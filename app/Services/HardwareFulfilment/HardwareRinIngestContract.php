<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Services\ChannelIngest\Data\ChannelOrderIngestRequest;
use App\Services\ChannelIngest\Data\ChannelOrderLineDraft;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Fail-closed rdservice.in RIN hardware ingest contract.
 * Does not change Box/RDE ingest.
 */
final class HardwareRinIngestContract
{
    public function assert(ChannelOrderIngestRequest $request): void
    {
        if (HardwareFulfilmentEligibility::looksLikeRinSourceId($request->sourceId)
            && $request->channel !== StatutoryInvoiceChannel::RdServiceIn) {
            throw ValidationException::withMessages([
                'channel' => 'RIN hardware must ingest as channel rdservice_in. It cannot use radiumbox_com.',
            ]);
        }

        if (str_starts_with(strtoupper($request->sourceId), HardwareFulfilmentEligibility::SOURCE_PREFIX)
            && $request->channel === StatutoryInvoiceChannel::RdServiceIn) {
            throw ValidationException::withMessages([
                'channel' => 'RDE hardware must ingest as channel radiumbox_com. It cannot use rdservice_in.',
            ]);
        }

        if (! HardwareFulfilmentEligibility::looksLikeRinSourceId($request->sourceId)) {
            return;
        }

        if ($request->channel !== StatutoryInvoiceChannel::RdServiceIn) {
            return;
        }

        $type = strtolower(trim((string) ($request->metadata['source_order_type'] ?? '')));
        if ($type !== 'hardware_direct_buy') {
            throw ValidationException::withMessages([
                'metadata.source_order_type' => 'RIN ingest requires source_order_type=hardware_direct_buy.',
            ]);
        }

        $source = trim((string) ($request->metadata['source'] ?? ''));
        if ($source !== 'rdservice.in') {
            throw ValidationException::withMessages([
                'metadata.source' => 'RIN ingest requires source=rdservice.in.',
            ]);
        }

        if ($request->paymentStatus !== 'paid') {
            throw ValidationException::withMessages([
                'payment_status' => 'RIN hardware ingest requires payment_status=paid.',
            ]);
        }

        if ($request->paymentProvider === null || trim((string) $request->paymentProvider) === '') {
            throw ValidationException::withMessages([
                'payment_provider' => 'RIN hardware ingest requires payment_provider. The value is not invented.',
            ]);
        }

        if ($request->paymentReference === null || trim((string) $request->paymentReference) === '') {
            throw ValidationException::withMessages([
                'payment_reference' => 'RIN hardware ingest requires payment_reference. The value is not invented.',
            ]);
        }

        if ($request->orderedAt === null || trim($request->orderedAt) === '') {
            throw ValidationException::withMessages([
                'ordered_at' => 'RIN hardware ingest requires ordered_at on or after 2026-09-05 00:00:00 IST.',
            ]);
        }

        $orderedAt = Carbon::parse($request->orderedAt);
        if (! HardwareFulfilmentEligibility::isOnOrAfterCutoff($orderedAt)) {
            throw ValidationException::withMessages([
                'ordered_at' => 'RIN hardware ingest accepts business ordered_at on or after 2026-09-05 00:00:00 IST only.',
            ]);
        }

        $shipping = $request->shippingAddressStructured ?? [];
        foreach (['line1', 'city', 'state', 'pincode'] as $field) {
            if (trim((string) ($shipping[$field] ?? '')) === '') {
                throw ValidationException::withMessages([
                    'shipping_address.'.$field => 'Required shipping field '.$field.' is missing and will not be invented.',
                ]);
            }
        }

        $physical = [];
        foreach ($request->lines as $index => $line) {
            if (! $line instanceof ChannelOrderLineDraft) {
                continue;
            }
            if (! HardwareFulfilmentEligibility::isPhysicalLine($line)) {
                continue;
            }
            $physical[] = [$index, $line];
            $this->assertPhysicalLine($index, $line, $request);
        }

        if ($physical === []) {
            throw ValidationException::withMessages([
                'lines' => 'RIN hardware ingest requires a physical_merchandise line.',
            ]);
        }
    }

    private function assertPhysicalLine(int $index, ChannelOrderLineDraft $line, ChannelOrderIngestRequest $request): void
    {
        if ($line->shippingLineKind !== HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND) {
            throw ValidationException::withMessages([
                'lines.'.$index.'.shipping_line_kind' => 'RIN hardware line requires shipping_line_kind=physical_merchandise.',
            ]);
        }

        if ($line->modelId === null) {
            throw ValidationException::withMessages([
                'lines.'.$index.'.model_id' => 'RIN hardware line is missing model_id.',
            ]);
        }

        $forbidden = array_map(
            'intval',
            config('hardware_fulfilment.rdservice_in.forbidden_model_ids', [1027]),
        );
        if (in_array($line->modelId, $forbidden, true)) {
            throw ValidationException::withMessages([
                'lines.'.$index.'.model_id' => 'Stub storefront product id cannot be used as the RIN hardware model identity.',
            ]);
        }

        if ($line->catalogSku === null || trim($line->catalogSku) === '') {
            throw ValidationException::withMessages([
                'lines.'.$index.'.catalog_sku' => 'RIN hardware line is missing catalog_sku.',
            ]);
        }

        if ($line->sku === null || trim($line->sku) === '') {
            throw ValidationException::withMessages([
                'lines.'.$index.'.sku' => 'RIN hardware line is missing Desk SKU.',
            ]);
        }

        if ($line->hsnSac === null || trim($line->hsnSac) === '') {
            throw ValidationException::withMessages([
                'lines.'.$index.'.hsn_sac' => 'RIN hardware line is missing HSN.',
            ]);
        }

        if ($line->qty < 1) {
            throw ValidationException::withMessages([
                'lines.'.$index.'.qty' => 'RIN hardware quantity is missing.',
            ]);
        }

        $map = ChannelSkuMap::query()
            ->with('product')
            ->where('channel', StatutoryInvoiceChannel::RdServiceIn)
            ->where('model_id', $line->modelId)
            ->first();

        if ($map === null || $map->product === null) {
            throw ValidationException::withMessages([
                'sku_map' => sprintf(
                    'No Owner-approved channel_sku_maps row for rdservice_in model_id %d.',
                    $line->modelId,
                ),
            ]);
        }

        if (! hash_equals(strtoupper((string) $map->product->sku), strtoupper((string) $line->sku))) {
            throw ValidationException::withMessages([
                'lines.'.$index.'.sku' => 'RIN hardware SKU does not match the Owner-approved Desk inventory product.',
            ]);
        }

        if ($map->channel_sku !== null && $map->channel_sku !== ''
            && ! hash_equals((string) $map->channel_sku, (string) $line->catalogSku)) {
            throw ValidationException::withMessages([
                'lines.'.$index.'.catalog_sku' => 'RIN catalog_sku does not match the Owner-approved channel_sku_maps row.',
            ]);
        }
    }
}

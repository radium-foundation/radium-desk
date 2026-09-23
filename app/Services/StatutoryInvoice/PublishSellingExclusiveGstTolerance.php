<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceMintRequest;
use App\Support\BusinessOrderId;
use Illuminate\Support\Carbon;

/**
 * One-paisa exclusive GST tolerance for publish-minus-selling inclusive catalog splits.
 *
 * RB* service (radiumbox.com), rdservice.in RD* service, and rdservice.net all send
 * authoritative tax_total that may differ from round(taxable × rate, 2) by one paisa.
 */
final class PublishSellingExclusiveGstTolerance
{
    public static function forCommerceOrder(CommerceOrder $order): int
    {
        return self::allowsOnePaisa(
            $order->channel,
            $order->source_id,
            $order->ordered_at ?? $order->paid_at ?? $order->received_at,
        ) ? 1 : 0;
    }

    public static function forMintRequest(
        StatutoryInvoiceMintRequest $request,
        ?Carbon $commercialAt = null,
    ): int {
        if ($request->inclusiveHardwareGst || $request->radiumboxServicePublishSellingGst) {
            return 1;
        }

        return self::allowsOnePaisa($request->channel, $request->sourceId, $commercialAt) ? 1 : 0;
    }

    public static function allowsOnePaisa(
        StatutoryInvoiceChannel $channel,
        ?string $sourceId,
        ?Carbon $commercialAt = null,
    ): bool {
        if ($channel === StatutoryInvoiceChannel::RdServiceNet) {
            return true;
        }

        if ($channel === StatutoryInvoiceChannel::RadiumBoxCom && BusinessOrderId::isRadiumBoxService($sourceId)) {
            return true;
        }

        if ($channel === StatutoryInvoiceChannel::RdServiceIn && BusinessOrderId::isRdServiceInService($sourceId)) {
            return $commercialAt !== null && StatutoryInvoiceScope::contains($commercialAt);
        }

        return false;
    }
}

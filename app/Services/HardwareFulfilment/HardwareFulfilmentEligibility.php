<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Services\ChannelIngest\Data\ChannelOrderIngestRequest;
use App\Services\ChannelIngest\Data\ChannelOrderLineDraft;

final class HardwareFulfilmentEligibility
{
    public const PHYSICAL_LINE_KIND = 'physical_merchandise';

    public const SOURCE_PREFIX = 'RDE';

    public static function shouldOpenRecord(ChannelOrderIngestRequest $request): bool
    {
        if ($request->channel !== StatutoryInvoiceChannel::RadiumBoxCom) {
            return false;
        }

        if ($request->sourceType !== StatutoryInvoiceSourceType::CommerceOrder) {
            return false;
        }

        if (! str_starts_with(strtoupper($request->sourceId), self::SOURCE_PREFIX)) {
            return false;
        }

        return self::hasPhysicalLine($request);
    }

    public static function hasPhysicalLine(ChannelOrderIngestRequest $request): bool
    {
        foreach ($request->lines as $line) {
            if (self::isPhysicalLine($line)) {
                return true;
            }
        }

        return false;
    }

    public static function isPhysicalLine(ChannelOrderLineDraft $line): bool
    {
        if ($line->shippingLineKind === self::PHYSICAL_LINE_KIND) {
            return true;
        }

        return $line->modelId !== null;
    }

    public static function looksLikeHardwareSourceId(string $sourceId): bool
    {
        return str_starts_with(strtoupper($sourceId), self::SOURCE_PREFIX);
    }
}

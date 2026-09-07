<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrderItem;
use App\Services\ChannelIngest\Data\ChannelOrderIngestRequest;
use App\Services\ChannelIngest\Data\ChannelOrderLineDraft;

final class HardwareFulfilmentEligibility
{
    public const PHYSICAL_LINE_KIND = 'physical_merchandise';

    public const SOURCE_PREFIX = 'RDE';

    /**
     * Owner-frozen pending hardware orders. P2 must not open or advance them.
     *
     * @var list<string>
     */
    public const FROZEN_SOURCE_IDS = [
        'RDE318360',
        'RDE318367',
        'RDE318378',
        'RDE318379',
        'RDE318382',
        'RDE318388',
        'RDE318391',
    ];

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

        if (self::isFrozenSourceId($request->sourceId)) {
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

    public static function isPhysicalCommerceItem(CommerceOrderItem $item): bool
    {
        if ($item->shipping_line_kind === self::PHYSICAL_LINE_KIND) {
            return true;
        }

        return $item->model_id !== null;
    }

    public static function looksLikeHardwareSourceId(string $sourceId): bool
    {
        return str_starts_with(strtoupper($sourceId), self::SOURCE_PREFIX);
    }

    public static function isFrozenSourceId(string $sourceId): bool
    {
        return in_array(strtoupper(trim($sourceId)), self::FROZEN_SOURCE_IDS, true);
    }
}

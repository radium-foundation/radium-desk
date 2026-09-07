<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\HardwareFulfilment;
use App\Services\ChannelIngest\Data\ChannelOrderIngestRequest;
use App\Services\ChannelIngest\Data\ChannelOrderLineDraft;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class HardwareFulfilmentEligibility
{
    public const PHYSICAL_LINE_KIND = 'physical_merchandise';

    public const SOURCE_PREFIX = 'RDE';

    public const CUTOFF_IST = '2026-09-05 00:00:00';

    public const CUTOFF_TIMEZONE = 'Asia/Kolkata';

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

    /**
     * Owner-HOLD source ids. Isolated fulfilment must refuse these.
     *
     * @var list<string>
     */
    public const HOLD_SOURCE_IDS = [
        'RDE318438',
    ];

    /**
     * First real candidate later. Isolated fulfilment must not process it yet.
     *
     * @var list<string>
     */
    public const BLOCKED_UNTIL_AUTHORIZED_SOURCE_IDS = [
        'RDE318400',
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

    public static function isHoldSourceId(string $sourceId): bool
    {
        $normalized = strtoupper(trim($sourceId));
        if (in_array($normalized, self::HOLD_SOURCE_IDS, true)) {
            return true;
        }

        $configured = config('hardware_fulfilment.hold_source_ids', []);
        if (! is_array($configured)) {
            return false;
        }

        $extra = array_map(
            static fn (mixed $value): string => strtoupper(trim((string) $value)),
            $configured,
        );

        return in_array($normalized, $extra, true);
    }

    public static function isBlockedUntilAuthorized(string $sourceId): bool
    {
        return in_array(strtoupper(trim($sourceId)), self::BLOCKED_UNTIL_AUTHORIZED_SOURCE_IDS, true);
    }

    public static function cutoffInstant(): Carbon
    {
        return Carbon::parse(self::CUTOFF_IST, self::CUTOFF_TIMEZONE);
    }

    public static function isOnOrAfterCutoff(?Carbon $orderedAt): bool
    {
        if ($orderedAt === null) {
            return false;
        }

        return $orderedAt->copy()->timezone(self::CUTOFF_TIMEZONE)->gte(self::cutoffInstant());
    }

    public static function assertSingularIdentifier(string $raw): string
    {
        $id = trim($raw);
        if ($id === '') {
            throw ValidationException::withMessages([
                'id' => 'desk:fulfil-hardware requires exactly one explicit order or fulfilment id.',
            ]);
        }

        $lower = strtolower($id);
        if (in_array($lower, ['all', 'batch', '*', 'any'], true)) {
            throw ValidationException::withMessages([
                'id' => 'Batch or implicit fulfilment is refused. Supply exactly one source id.',
            ]);
        }

        if (str_contains($id, ',') || preg_match('/\s/', $id) === 1) {
            throw ValidationException::withMessages([
                'id' => 'Multiple identifiers are refused. Supply exactly one source id.',
            ]);
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public static function metadataShowsHold(?array $metadata): bool
    {
        if ($metadata === null) {
            return false;
        }

        foreach (['owner_hold', 'hold', 'fulfilment_hold'] as $key) {
            if (array_key_exists($key, $metadata) && filter_var($metadata[$key], FILTER_VALIDATE_BOOLEAN)) {
                return true;
            }
        }

        $flag = strtoupper(trim((string) ($metadata['fulfilment_status'] ?? '')));

        return $flag === 'HOLD';
    }

    public static function isPaidCommerceOrder(CommerceOrder $order): bool
    {
        return strtolower(trim((string) $order->payment_status)) === 'paid';
    }

    public static function hasHardwareLines(CommerceOrder $order): bool
    {
        foreach ($order->items as $item) {
            if (self::isPhysicalCommerceItem($item)) {
                return true;
            }
        }

        return false;
    }

    public static function assertIsolatedTarget(HardwareFulfilment $fulfilment, CommerceOrder $order): void
    {
        $sourceId = (string) $fulfilment->source_id;

        if ((int) $fulfilment->commerce_order_id !== (int) $order->id) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Hardware fulfilment does not belong to the selected commerce order.',
            ]);
        }

        if (self::isFrozenSourceId($sourceId)) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Frozen pending hardware orders cannot use the isolated fulfilment path.',
            ]);
        }

        if (self::isHoldSourceId($sourceId) || self::metadataShowsHold($order->metadata) || self::metadataShowsHold($fulfilment->metadata)) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Owner-HOLD hardware orders cannot use the isolated fulfilment path.',
            ]);
        }

        if (self::isBlockedUntilAuthorized($sourceId)) {
            throw ValidationException::withMessages([
                'fulfilment' => 'This source id is not authorized for isolated fulfilment yet.',
            ]);
        }

        if (! self::isPaidCommerceOrder($order)) {
            throw ValidationException::withMessages([
                'payment' => 'Isolated fulfilment requires a paid commerce order.',
            ]);
        }

        if (! self::hasHardwareLines($order)) {
            throw ValidationException::withMessages([
                'hardware' => 'Isolated fulfilment requires a physical hardware line.',
            ]);
        }

        if ($order->ordered_at === null) {
            throw ValidationException::withMessages([
                'orderdate' => 'Isolated fulfilment requires a persisted business order date. created_at is not substituted.',
            ]);
        }

        if (! self::isOnOrAfterCutoff($order->ordered_at)) {
            throw ValidationException::withMessages([
                'orderdate' => 'Isolated fulfilment accepts business orderdate on or after 2026-09-05 00:00:00 IST only.',
            ]);
        }
    }
}

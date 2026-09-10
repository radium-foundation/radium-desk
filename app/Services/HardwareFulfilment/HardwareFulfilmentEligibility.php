<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\HardwareFulfilment;
use App\Services\ChannelIngest\Data\ChannelOrderIngestRequest;
use App\Services\ChannelIngest\Data\ChannelOrderLineDraft;
use App\Support\BusinessOrderId;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class HardwareFulfilmentEligibility
{
    public const PHYSICAL_LINE_KIND = 'physical_merchandise';

    public const SOURCE_PREFIX = 'RDE';

    public const RBP_SOURCE_PREFIX = 'RBP';

    public const RIN_SOURCE_PREFIX = 'RIN';

    public const RDP_SOURCE_PREFIX = 'RDP';

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
        'RDE255714',
        'RDE313554',
    ];

    /**
     * Isolated fulfilment must not process these until an owner prompt
     * moves the id into AUTHORIZED_ISOLATED_SOURCE_IDS.
     *
     * @var list<string>
     */
    public const BLOCKED_UNTIL_AUTHORIZED_SOURCE_IDS = [
    ];

    /**
     * Owner-authorized isolated fulfilment. RDE318400 / CO-000740 / HF14
     * was the sole prior member of BLOCKED_UNTIL_AUTHORIZED_SOURCE_IDS.
     *
     * @var list<string>
     */
    public const AUTHORIZED_ISOLATED_SOURCE_IDS = [
        'RDE318400',
    ];

    public static function shouldOpenRecord(ChannelOrderIngestRequest $request): bool
    {
        if ($request->sourceType !== StatutoryInvoiceSourceType::CommerceOrder) {
            return false;
        }

        if (self::isFrozenSourceId($request->sourceId)) {
            return false;
        }

        if (self::isRdServiceInHardwareRequest($request)) {
            return self::hasPhysicalLine($request);
        }

        $parsed = BusinessOrderId::parse($request->sourceId);
        if ($parsed !== null && $parsed['hardware'] === false) {
            return false;
        }

        if ($request->channel !== StatutoryInvoiceChannel::RadiumBoxCom) {
            return false;
        }

        if (! self::looksLikeBoxHardwareSourceId($request->sourceId)) {
            return false;
        }

        return self::hasPhysicalLine($request);
    }

    public static function isRinHardwareRequest(ChannelOrderIngestRequest $request): bool
    {
        return self::isRdServiceInHardwareRequest($request);
    }

    public static function isRdServiceInHardwareRequest(ChannelOrderIngestRequest $request): bool
    {
        if ($request->channel !== StatutoryInvoiceChannel::RdServiceIn) {
            return false;
        }

        if (! self::looksLikeRdServiceInHardwareSourceId($request->sourceId)) {
            return false;
        }

        $type = strtolower(trim((string) ($request->metadata['source_order_type'] ?? '')));

        return $type === 'hardware_direct_buy';
    }

    public static function looksLikeRinSourceId(string $sourceId): bool
    {
        return (bool) preg_match('/^RIN\d+$/i', trim($sourceId));
    }

    public static function looksLikeRdServiceInHardwareSourceId(string $sourceId): bool
    {
        $parsed = BusinessOrderId::parse($sourceId);
        if ($parsed !== null) {
            return $parsed['hardware'] === true && $parsed['owner'] === 'rdservice.in';
        }

        $normalized = strtoupper(trim($sourceId));

        return self::looksLikeRinSourceId($normalized)
            || (bool) preg_match('/^RDP\d+$/i', $normalized);
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

    public static function looksLikeBoxHardwareSourceId(string $sourceId): bool
    {
        $parsed = BusinessOrderId::parse($sourceId);
        if ($parsed !== null) {
            return $parsed['hardware'] === true && $parsed['owner'] === 'radiumbox.com';
        }

        $normalized = strtoupper(trim($sourceId));

        return str_starts_with($normalized, self::SOURCE_PREFIX)
            || str_starts_with($normalized, self::RBP_SOURCE_PREFIX);
    }

    public static function looksLikeHardwareSourceId(string $sourceId): bool
    {
        $parsed = BusinessOrderId::parse($sourceId);
        if ($parsed !== null) {
            return $parsed['hardware'] === true;
        }

        $normalized = strtoupper(trim($sourceId));

        return str_starts_with($normalized, self::SOURCE_PREFIX)
            || str_starts_with($normalized, self::RBP_SOURCE_PREFIX)
            || self::looksLikeRdServiceInHardwareSourceId($normalized);
    }

    public static function isFrozenSourceId(string $sourceId): bool
    {
        return in_array(strtoupper(trim($sourceId)), self::FROZEN_SOURCE_IDS, true);
    }

    /**
     * Frozen for fulfilment unless a Desk-local recovered-Commerce authorization matches.
     * Channel ingest still uses isFrozenSourceId() so Box handoff replay cannot open HF.
     */
    public static function isFrozenForFulfilment(string $sourceId, ?CommerceOrder $order = null): bool
    {
        if (! self::isFrozenSourceId($sourceId)) {
            return false;
        }

        $resolved = $order;
        if ($resolved === null) {
            $matches = CommerceOrder::query()
                ->whereRaw('UPPER(source_id) = ?', [strtoupper(trim($sourceId))])
                ->get();
            if ($matches->count() !== 1) {
                return true;
            }
            $resolved = $matches->first();
        }

        if (strcasecmp((string) $resolved->source_id, trim($sourceId)) !== 0) {
            return true;
        }

        return ! app(HardwareRecoveredFulfilmentAuthorization::class)->isAuthorized($resolved);
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
        $normalized = strtoupper(trim($sourceId));
        if (in_array($normalized, self::AUTHORIZED_ISOLATED_SOURCE_IDS, true)) {
            return false;
        }

        return in_array($normalized, self::BLOCKED_UNTIL_AUTHORIZED_SOURCE_IDS, true);
    }

    public static function cutoffInstant(): Carbon
    {
        return Carbon::parse(self::CUTOFF_IST, self::CUTOFF_TIMEZONE);
    }

    /**
     * Format an instant for comparison against Desk `orders.created_at`.
     * That column is a naive DATETIME persisted in the application timezone
     * (Asia/Kolkata). Do not bind a UTC-converted clock against it.
     */
    public static function createdAtSqlBound(Carbon $instant): string
    {
        return $instant->copy()->timezone(self::CUTOFF_TIMEZONE)->format('Y-m-d H:i:s');
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

    /**
     * Commerce hardware orders cannot take the Finance Hub service mint path.
     * A fulfilment row, or a hardware source id with physical lines, requires
     * completed serial allocation before the statutory invoice.
     */
    public static function requiresSerialAllocatedInvoice(CommerceOrder $order): bool
    {
        $order->loadMissing(['items', 'hardwareFulfilment']);

        if ($order->hardwareFulfilment !== null) {
            return true;
        }

        return self::looksLikeHardwareSourceId((string) $order->source_id)
            && self::hasHardwareLines($order);
    }

    /**
     * Isolated recovered-Commerce and isolated-step gates for a Commerce order.
     * Frozen sources stay blocked unless a matching recovered-Commerce authorization exists.
     * Call this before opening a hardware fulfilment so a later isolated-target
     * check cannot create a row and then report failure.
     */
    public static function assertIsolatedCommerceOrder(CommerceOrder $order): void
    {
        $sourceId = (string) $order->source_id;

        if (self::isFrozenForFulfilment($sourceId, $order)) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Frozen pending hardware orders cannot use the isolated fulfilment path.',
            ]);
        }

        if (self::isHoldSourceId($sourceId) || self::metadataShowsHold($order->metadata)) {
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

    public static function assertIsolatedTarget(HardwareFulfilment $fulfilment, CommerceOrder $order): void
    {
        if ((int) $fulfilment->commerce_order_id !== (int) $order->id
            || strcasecmp((string) $fulfilment->source_id, (string) $order->source_id) !== 0) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Hardware fulfilment does not belong to the selected commerce order.',
            ]);
        }

        self::assertIsolatedCommerceOrder($order);

        if (self::metadataShowsHold($fulfilment->metadata)) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Owner-HOLD hardware orders cannot use the isolated fulfilment path.',
            ]);
        }
    }

    /**
     * Non-throwing view of assertIsolatedTarget() for operator classification.
     * Does not change fulfilment state.
     */
    public static function isolatedTargetBlocker(HardwareFulfilment $fulfilment, ?CommerceOrder $order): ?string
    {
        if ($order === null) {
            return 'Hardware fulfilment is missing its commerce order.';
        }

        $order->loadMissing('items');

        try {
            self::assertIsolatedTarget($fulfilment, $order);

            return null;
        } catch (ValidationException $exception) {
            return collect($exception->errors())->flatten()->first();
        }
    }
}

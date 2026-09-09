<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\HardwareRecoveredFulfilmentAuthorization as AuthorizationRow;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Desk-local recovered-Commerce fulfilment authorization.
 * Fail closed. Does not query RadiumBox. Does not open HF or change fulfilment state.
 */
class HardwareRecoveredFulfilmentAuthorization
{
    public const STATUS_AUTHORIZED = AuthorizationRow::STATUS_AUTHORIZED;

    public const PURPOSE_OWNER_RECOVERED = 'owner-authorized-recovered-commerce-fulfilment';

    public function isAuthorized(CommerceOrder $order): bool
    {
        $row = $this->activeMatchingRow($order);

        return $row !== null;
    }

    public function isAuthorizedSource(string $sourceId): bool
    {
        $order = $this->uniqueCommerce($sourceId);

        return $order !== null && $this->isAuthorized($order);
    }

    /**
     * @return array{ok: bool, code: string, message: string, authorization: ?AuthorizationRow}
     */
    public function authorizeOne(CommerceOrder $order, string $purpose, string $authorizedBy): array
    {
        $purpose = trim($purpose);
        if ($purpose === '') {
            return $this->fail('purpose_required', 'A non-empty purpose is required to authorize recovered fulfilment.');
        }

        if (! $this->tableExists()) {
            return $this->fail('authorization_table_missing', 'Recovered fulfilment authorization table is missing. Fail closed.');
        }

        try {
            $this->assertAuthorizableOrder($order);
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first() ?? 'Order is not authorizable.';

            return $this->fail('not_authorizable', (string) $message);
        }

        $channel = $this->channelValue($order);
        $sourceType = (string) $order->source_type;
        $sourceId = strtoupper(trim((string) $order->source_id));
        $commerceId = (int) $order->id;
        $orderNo = (string) $order->order_no;
        $actor = trim($authorizedBy) !== '' ? trim($authorizedBy) : 'artisan';

        $existing = AuthorizationRow::query()
            ->where('channel', $channel)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();

        if ($existing !== null) {
            if ($existing->isActive()
                && (int) $existing->commerce_order_id === $commerceId
                && (string) $existing->commerce_order_no === $orderNo) {
                return [
                    'ok' => true,
                    'code' => 'already_authorized',
                    'message' => $sourceId.' is already authorized for recovered-commerce fulfilment.',
                    'authorization' => $existing,
                ];
            }

            return $this->fail(
                'authorization_conflict',
                $sourceId.' already has a recovered-fulfilment authorization that does not match this Commerce identity.',
            );
        }

        $commerceTaken = AuthorizationRow::query()->where('commerce_order_id', $commerceId)->exists();
        if ($commerceTaken) {
            return $this->fail('authorization_conflict', 'Commerce '.$orderNo.' already has a recovered-fulfilment authorization.');
        }

        $row = AuthorizationRow::query()->create([
            'channel' => $channel,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'commerce_order_id' => $commerceId,
            'commerce_order_no' => $orderNo,
            'purpose' => $purpose,
            'status' => self::STATUS_AUTHORIZED,
            'authorized_at' => now(),
            'authorized_by' => $actor,
        ]);

        return [
            'ok' => true,
            'code' => 'authorized',
            'message' => $sourceId.' / '.$orderNo.' is authorized for recovered-commerce fulfilment. No hardware fulfilment was opened.',
            'authorization' => $row,
        ];
    }

    public function assertAuthorizableOrder(CommerceOrder $order): void
    {
        $sourceId = strtoupper(trim((string) $order->source_id));

        if (! HardwareFulfilmentEligibility::isFrozenSourceId($sourceId)) {
            throw ValidationException::withMessages([
                'source' => 'Recovered-fulfilment authorization is only for owner-frozen hardware sources.',
            ]);
        }

        if (HardwareFulfilmentEligibility::isHoldSourceId($sourceId)
            || HardwareFulfilmentEligibility::metadataShowsHold($order->metadata)) {
            throw ValidationException::withMessages([
                'source' => 'Owner-HOLD sources cannot receive recovered-fulfilment authorization.',
            ]);
        }

        if (HardwareFulfilmentEligibility::isBlockedUntilAuthorized($sourceId)) {
            throw ValidationException::withMessages([
                'source' => 'Blocked-until-authorized sources cannot use recovered-fulfilment authorization.',
            ]);
        }

        if ($this->channelValue($order) !== StatutoryInvoiceChannel::RadiumBoxCom->value) {
            throw ValidationException::withMessages([
                'channel' => 'Recovered-fulfilment authorization requires channel radiumbox_com.',
            ]);
        }

        if ((string) $order->source_type !== StatutoryInvoiceSourceType::CommerceOrder->value) {
            throw ValidationException::withMessages([
                'source_type' => 'Recovered-fulfilment authorization requires source_type commerce_order.',
            ]);
        }

        if (! HardwareFulfilmentEligibility::isPaidCommerceOrder($order)) {
            throw ValidationException::withMessages([
                'payment' => 'Recovered-fulfilment authorization requires a paid commerce order.',
            ]);
        }

        $order->loadMissing('items');
        if (! HardwareFulfilmentEligibility::hasHardwareLines($order)) {
            throw ValidationException::withMessages([
                'hardware' => 'Recovered-fulfilment authorization requires a physical hardware line.',
            ]);
        }
    }

    public function requireAuthorized(CommerceOrder $order): void
    {
        if ($this->isAuthorized($order)) {
            return;
        }

        throw ValidationException::withMessages([
            'fulfilment' => 'Frozen pending hardware orders cannot use the isolated fulfilment path.',
        ]);
    }

    public function tableExists(): bool
    {
        return Schema::hasTable('hardware_recovered_fulfilment_authorizations');
    }

    private function activeMatchingRow(CommerceOrder $order): ?AuthorizationRow
    {
        if (! $this->tableExists()) {
            return null;
        }

        $channel = $this->channelValue($order);
        $sourceType = (string) $order->source_type;
        $sourceId = strtoupper(trim((string) $order->source_id));
        $commerceId = (int) $order->id;
        $orderNo = (string) $order->order_no;

        $row = AuthorizationRow::query()
            ->where('channel', $channel)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('commerce_order_id', $commerceId)
            ->where('commerce_order_no', $orderNo)
            ->where('status', self::STATUS_AUTHORIZED)
            ->whereNull('revoked_at')
            ->first();

        if ($row === null) {
            return null;
        }

        if ((string) $row->channel !== $channel
            || (string) $row->source_type !== $sourceType
            || strtoupper((string) $row->source_id) !== $sourceId
            || (int) $row->commerce_order_id !== $commerceId
            || (string) $row->commerce_order_no !== $orderNo) {
            return null;
        }

        return $row;
    }

    private function uniqueCommerce(string $sourceId): ?CommerceOrder
    {
        $normalized = strtoupper(trim($sourceId));
        $matches = CommerceOrder::query()
            ->whereRaw('UPPER(source_id) = ?', [$normalized])
            ->get();

        if ($matches->count() !== 1) {
            return null;
        }

        return $matches->first();
    }

    private function channelValue(CommerceOrder $order): string
    {
        $channel = $order->channel;

        return $channel instanceof StatutoryInvoiceChannel ? $channel->value : (string) $channel;
    }

    /**
     * @return array{ok: bool, code: string, message: string, authorization: null}
     */
    private function fail(string $code, string $message): array
    {
        return [
            'ok' => false,
            'code' => $code,
            'message' => $message,
            'authorization' => null,
        ];
    }
}

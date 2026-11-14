<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\Order;
use App\Services\HardwareFulfilment\HardwareOwnerAuthorizedExistingCommerceProfiles as Profiles;
use Illuminate\Validation\ValidationException;

/**
 * Validates Owner-authorized existing-Commerce recovery identities. Fail closed.
 */
final class HardwareOwnerAuthorizedExistingCommerceGate
{
    public static function assertOrderMatchesProfile(CommerceOrder $order): void
    {
        $sourceId = strtoupper(trim((string) $order->source_id));
        $profile = Profiles::profile($sourceId);
        if ($profile === null) {
            throw ValidationException::withMessages([
                'source' => 'No Owner-authorized existing-commerce profile exists for '.$sourceId.'.',
            ]);
        }

        if ((string) $order->order_no !== $profile['commerce_order_no']) {
            throw ValidationException::withMessages([
                'commerce' => 'Commerce order_no must be '.$profile['commerce_order_no'].' for '.$sourceId.'.',
            ]);
        }

        if (trim((string) $order->payment_reference) !== $profile['payment_reference']) {
            throw ValidationException::withMessages([
                'payment' => 'Payment reference must match the authorized Cashfree correlation for '.$sourceId.'.',
            ]);
        }

        if (app()->environment('production')) {
            if ((int) $order->id !== (int) $profile['production_commerce_order_id']) {
                throw ValidationException::withMessages([
                    'commerce' => 'Production commerce_order_id must be '.$profile['production_commerce_order_id'].' for '.$sourceId.'.',
                ]);
            }
        }

        $desk = Order::query()->where('order_id', $sourceId)->first();
        if ($desk === null) {
            throw ValidationException::withMessages([
                'support' => 'Desk support order '.$sourceId.' is required before authorizing existing-commerce fulfilment.',
            ]);
        }

        if (app()->environment('production')
            && (int) $desk->id !== (int) $profile['support_order_id']) {
            throw ValidationException::withMessages([
                'support' => 'Desk support order id must be '.$profile['support_order_id'].' for '.$sourceId.'.',
            ]);
        }

        if ($order->support_order_id !== null && (int) $order->support_order_id !== (int) $desk->id) {
            throw ValidationException::withMessages([
                'support' => 'Commerce support_order_id does not match the Desk support order for '.$sourceId.'.',
            ]);
        }

        $order->loadMissing('items');
        if (! HardwareFulfilmentEligibility::isPaidCommerceOrder($order)) {
            throw ValidationException::withMessages([
                'payment' => 'Owner-authorized existing-commerce fulfilment requires a paid commerce order.',
            ]);
        }

        if (! HardwareFulfilmentEligibility::hasHardwareLines($order)) {
            throw ValidationException::withMessages([
                'hardware' => 'Owner-authorized existing-commerce fulfilment requires a physical hardware line.',
            ]);
        }

        $channel = $order->channel instanceof StatutoryInvoiceChannel
            ? $order->channel->value
            : (string) $order->channel;
        if ($channel !== StatutoryInvoiceChannel::RadiumBoxCom->value) {
            throw ValidationException::withMessages([
                'channel' => 'Owner-authorized existing-commerce fulfilment requires channel radiumbox_com.',
            ]);
        }

        $physical = $order->items->filter(
            fn ($item) => HardwareFulfilmentEligibility::isPhysicalCommerceItem($item),
        )->values();

        if ($physical->count() !== count($profile['lines'])) {
            throw ValidationException::withMessages([
                'lines' => 'Physical line count does not match the authorized profile for '.$sourceId.'.',
            ]);
        }

        foreach ($profile['lines'] as $index => $expected) {
            $line = $physical->get($index);
            if ($line === null) {
                throw ValidationException::withMessages([
                    'lines' => 'Missing authorized physical line '.($index + 1).' for '.$sourceId.'.',
                ]);
            }
            if ((int) $line->model_id !== (int) $expected['model_id']) {
                throw ValidationException::withMessages([
                    'lines' => 'model_id must be '.$expected['model_id'].' for '.$sourceId.'.',
                ]);
            }
            if ((int) $line->qty !== (int) $expected['qty']) {
                throw ValidationException::withMessages([
                    'lines' => 'Quantity must be '.$expected['qty'].' for '.$sourceId.'.',
                ]);
            }
        }
    }

    public static function isOwnerAuthorizedExistingCommerce(CommerceOrder $order): bool
    {
        if (! Profiles::has((string) $order->source_id)) {
            return false;
        }

        try {
            self::assertOrderMatchesProfile($order);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }
}

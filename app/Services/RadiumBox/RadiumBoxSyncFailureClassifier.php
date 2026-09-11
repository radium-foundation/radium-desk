<?php

namespace App\Services\RadiumBox;

use App\Models\Order;

final class RadiumBoxSyncFailureClassifier
{
    /**
     * @param  list<Order>  $failedOrders
     * @return array{current: list<string>, historical: list<string>}
     */
    public static function categorize(array $failedOrders, int $maxAttempts): array
    {
        $current = [];
        $historical = [];

        foreach ($failedOrders as $order) {
            $orderId = (string) $order->order_id;

            if ($orderId === '') {
                continue;
            }

            if (self::isHistoricalFailure($order, $maxAttempts)) {
                $historical[] = $orderId;

                continue;
            }

            $current[] = $orderId;
        }

        return [
            'current' => $current,
            'historical' => $historical,
        ];
    }

    /**
     * @param  array{current: list<string>, historical: list<string>}  $categories
     */
    public static function telegramMessage(array $categories): string
    {
        $currentCount = count($categories['current']);
        $historicalCount = count($categories['historical']);

        if ($currentCount === 0) {
            return sprintf(
                '%d historical retired-infrastructure RadiumBox sync record(s) remain stored (not deleted).',
                $historicalCount,
            );
        }

        $message = sprintf(
            '%d current KVM8 failure(s) require attention.',
            $currentCount,
        );

        if ($historicalCount > 0) {
            $message .= sprintf(
                ' %d historical retired-infrastructure record(s) remain stored.',
                $historicalCount,
            );
        }

        return $message;
    }

    public static function isKvm8SpokeError(?string $error): bool
    {
        if (! is_string($error) || $error === '') {
            return false;
        }

        return str_contains($error, '127.0.0.1');
    }

    public static function isTransientInfrastructureError(?string $error): bool
    {
        if (! is_string($error) || $error === '') {
            return false;
        }

        return str_contains($error, 'cURL error 28');
    }

    private static function isHistoricalFailure(Order $order, int $maxAttempts): bool
    {
        $error = is_string($order->radiumbox_last_sync_error ?? null)
            ? $order->radiumbox_last_sync_error
            : null;

        if (self::isRetiredInfrastructureError($error)) {
            return true;
        }

        $attempts = (int) ($order->radiumbox_sync_attempts ?? 0);

        if ($attempts >= $maxAttempts && ! self::isKvm8SpokeError($error)) {
            return true;
        }

        return false;
    }

    private static function isRetiredInfrastructureError(?string $error): bool
    {
        if (! is_string($error) || $error === '') {
            return false;
        }

        if (str_contains($error, 'admin.radiumbox.com')) {
            return true;
        }

        if (str_contains($error, 'HTTP 526')) {
            return true;
        }

        return false;
    }
}

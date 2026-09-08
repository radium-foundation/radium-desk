<?php

namespace App\Services\Shipping;

use App\Services\Shipping\Data\ShiprocketPickupResult;

/**
 * Shiprocket generate/pickup already-queued signal.
 *
 * Production RDE318421 displayed `HTTP 400 — Already in Pickup Queue`.
 * P-07-09-88 required an exact message equality and missed punctuation
 * or the rejected-result wrapper, which left the 400 as a fatal flash.
 */
final class ShiprocketAlreadyQueuedPickup
{
    public const PHRASE = 'Already in Pickup Queue';

    /**
     * @param  array<string, mixed>  $json
     */
    public static function matchesHttp(int $httpStatus, array $json): bool
    {
        if ($httpStatus !== 400) {
            return false;
        }

        foreach (self::candidateTexts($json) as $text) {
            if (self::containsPhrase($text)) {
                return true;
            }
        }

        return false;
    }

    public static function matchesRejectedResult(ShiprocketPickupResult $result): bool
    {
        if ($result->retryable) {
            return false;
        }

        if ($result->alreadyQueued || $result->status === 'already_requested') {
            return true;
        }

        $error = (string) $result->error;

        return self::containsPhrase($error) && str_contains($error, '400');
    }

    public static function containsPhrase(?string $text): bool
    {
        if ($text === null) {
            return false;
        }

        return str_contains(strtolower($text), strtolower(self::PHRASE));
    }

    /**
     * @param  array<string, mixed>  $json
     * @return list<string>
     */
    private static function candidateTexts(array $json): array
    {
        $out = [];
        foreach (['message', 'error', 'msg'] as $key) {
            $value = $json[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $out[] = (string) $value;
            }
        }

        $nested = $json['response'] ?? null;
        if (is_array($nested)) {
            foreach (['data', 'others', 'message', 'error', 'msg'] as $key) {
                $value = $nested[$key] ?? null;
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $out[] = (string) $value;
                }
            }
        }

        return $out;
    }
}

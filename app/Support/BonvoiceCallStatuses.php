<?php

namespace App\Support;

class BonvoiceCallStatuses
{
    /** @var list<string> */
    public const MISSED = ['NOANSWER', 'NOINPUT', 'FAILED'];

    /** @var list<string> */
    public const ANSWERED = ['ANSWERED', 'COMPLETED'];

    /** @var list<string> */
    public const RINGING = ['RINGING', 'RING'];

    public static function normalize(?string $status): ?string
    {
        if ($status === null || trim($status) === '') {
            return null;
        }

        return strtoupper(trim($status));
    }

    public static function isMissedStatus(?string $status): bool
    {
        $normalized = self::normalize($status);

        return $normalized !== null && in_array($normalized, self::MISSED, true);
    }

    public static function isAnsweredStatus(?string $status): bool
    {
        $normalized = self::normalize($status);

        return $normalized !== null && in_array($normalized, self::ANSWERED, true);
    }

    public static function isRingingStatus(?string $status): bool
    {
        $normalized = self::normalize($status);

        return $normalized !== null && in_array($normalized, self::RINGING, true);
    }

    public static function transitionedToMissed(?string $previous, ?string $current): bool
    {
        if (! self::isMissedStatus($current)) {
            return false;
        }

        return ! self::isMissedStatus($previous);
    }

    public static function isInbound(?string $direction): bool
    {
        return in_array(strtolower((string) $direction), ['inbound', 'in', 'incoming'], true);
    }

    public static function transitionedToAnswered(?string $previous, ?string $current): bool
    {
        if (! self::isAnsweredStatus($current)) {
            return false;
        }

        return ! self::isAnsweredStatus($previous);
    }
}

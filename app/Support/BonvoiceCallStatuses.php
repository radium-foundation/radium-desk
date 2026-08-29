<?php

namespace App\Support;

class BonvoiceCallStatuses
{
    /** Bonvoice callType: initiated. */
    public const CALL_TYPE_INITIATED = '0';

    /** Bonvoice callType: ringing. */
    public const CALL_TYPE_RINGING = '0.5';

    /** Bonvoice callType: answered (in progress). */
    public const CALL_TYPE_ANSWERED = '1';

    /** Bonvoice callType: hangup / terminal. */
    public const CALL_TYPE_HANGUP = '2';

    /**
     * Non-answered hangup / missed outcomes.
     *
     * Includes Bonvoice hangup STATUS values that are not ANSWERED, plus
     * existing Desk missed statuses (NOINPUT / FAILED) and CANCEL aliases.
     *
     * @var list<string>
     */
    public const MISSED = [
        'NOANSWER',
        'NOINPUT',
        'FAILED',
        'BUSY',
        'CANCEL',
        'CANCELLED',
        'CANCELED',
        'CHANUNAVAIL',
        'CONGESTION',
    ];

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

    public static function normalizeCallType(?string $callType): ?string
    {
        if ($callType === null || trim($callType) === '') {
            return null;
        }

        $normalized = trim($callType);

        return match ($normalized) {
            '0', '0.0' => self::CALL_TYPE_INITIATED,
            '0.5', '.5' => self::CALL_TYPE_RINGING,
            '1', '1.0' => self::CALL_TYPE_ANSWERED,
            '2', '2.0' => self::CALL_TYPE_HANGUP,
            default => $normalized,
        };
    }

    /**
     * Numeric lifecycle rank for Bonvoice callType 0 / 0.5 / 1 / 2.
     * Unknown types (legacy test payloads) return null.
     */
    public static function lifecycleRank(?string $callType): ?int
    {
        return match (self::normalizeCallType($callType)) {
            self::CALL_TYPE_INITIATED => 0,
            self::CALL_TYPE_RINGING => 1,
            self::CALL_TYPE_ANSWERED => 2,
            self::CALL_TYPE_HANGUP => 3,
            default => null,
        };
    }

    public static function isHangupCallType(?string $callType): bool
    {
        return self::normalizeCallType($callType) === self::CALL_TYPE_HANGUP;
    }

    public static function isRingingCallType(?string $callType): bool
    {
        return self::normalizeCallType($callType) === self::CALL_TYPE_RINGING;
    }

    public static function isAnsweredCallType(?string $callType): bool
    {
        return self::normalizeCallType($callType) === self::CALL_TYPE_ANSWERED;
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

    /**
     * Ringing for live assist: Bonvoice callType 0.5, or legacy STATUS=RINGING.
     * Hangup callType 2 is never ringing. Terminal/missed/answered STATUS wins.
     */
    public static function isRingingCall(?string $status, ?string $callType): bool
    {
        if (self::isHangupCallType($callType)) {
            return false;
        }

        if (self::isMissedStatus($status) || self::isAnsweredStatus($status)) {
            return false;
        }

        return self::isRingingStatus($status) || self::isRingingCallType($callType);
    }

    /**
     * Answered for live assist / auto-open: callType 1, hangup STATUS=ANSWERED,
     * or legacy STATUS=COMPLETED.
     */
    public static function isAnsweredCall(?string $status, ?string $callType): bool
    {
        if (self::isHangupCallType($callType)) {
            return self::isAnsweredStatus($status);
        }

        return self::isAnsweredStatus($status) || self::isAnsweredCallType($callType);
    }

    public static function isLiveAssistEligibleStatus(?string $status): bool
    {
        return self::isRingingStatus($status) || self::isAnsweredStatus($status);
    }

    public static function isLiveAssistEligibleCall(?string $status, ?string $callType): bool
    {
        return self::isRingingCall($status, $callType) || self::isAnsweredCall($status, $callType);
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

    public static function isOutbound(?string $direction): bool
    {
        return in_array(strtolower((string) $direction), ['outbound', 'out', 'outgoing'], true);
    }

    public static function transitionedToAnswered(
        ?string $previousStatus,
        ?string $currentStatus,
        ?string $previousCallType = null,
        ?string $currentCallType = null,
    ): bool {
        if (! self::isAnsweredCall($currentStatus, $currentCallType)) {
            return false;
        }

        return ! self::isAnsweredCall($previousStatus, $previousCallType);
    }
}

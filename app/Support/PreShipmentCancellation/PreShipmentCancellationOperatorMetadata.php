<?php

namespace App\Support\PreShipmentCancellation;

final class PreShipmentCancellationOperatorMetadata
{
    private const PREFIX = 'psc_meta:';

    /**
     * Legacy production column was varchar(64); wallet preference JSON exceeds that for typical operator notes.
     */
    public const LEGACY_VARCHAR_LIMIT = 64;

    public const MAX_ENCODED_LENGTH = 16_384;

    /**
     * @return array{refund_preference: ?string, operator_notes: ?string}
     */
    public static function decode(?string $blockerReason): array
    {
        if ($blockerReason === null || $blockerReason === '') {
            return [
                'refund_preference' => null,
                'operator_notes' => null,
            ];
        }

        if (str_starts_with($blockerReason, self::PREFIX)) {
            $json = json_decode(substr($blockerReason, strlen(self::PREFIX)), true);

            if (is_array($json)) {
                return [
                    'refund_preference' => isset($json['refund_preference']) ? (string) $json['refund_preference'] : null,
                    'operator_notes' => isset($json['operator_notes']) ? (string) $json['operator_notes'] : null,
                ];
            }
        }

        return [
            'refund_preference' => null,
            'operator_notes' => null,
        ];
    }

    public static function encode(?string $refundPreference, ?string $operatorNotes): ?string
    {
        $refundPreference = $refundPreference !== null ? trim($refundPreference) : null;
        $operatorNotes = $operatorNotes !== null ? trim($operatorNotes) : null;

        if (($refundPreference === null || $refundPreference === '')
            && ($operatorNotes === null || $operatorNotes === '')) {
            return null;
        }

        return self::PREFIX.json_encode([
            'refund_preference' => $refundPreference !== '' ? $refundPreference : null,
            'operator_notes' => $operatorNotes !== '' ? $operatorNotes : null,
        ], JSON_UNESCAPED_UNICODE);
    }

    public static function encodedLength(?string $refundPreference, ?string $operatorNotes): int
    {
        $encoded = self::encode($refundPreference, $operatorNotes);

        return $encoded === null ? 0 : strlen($encoded);
    }
}

<?php

namespace App\Support\PreShipmentCancellation;

final class PreShipmentCancellationOperatorMetadataPresentation
{
    /**
     * @return array{refund_preference: ?string, refund_preference_label: ?string, operator_notes: ?string}
     */
    public static function fromBlockerReason(?string $blockerReason): array
    {
        $decoded = PreShipmentCancellationOperatorMetadata::decode($blockerReason);
        $preference = $decoded['refund_preference'];
        $notes = $decoded['operator_notes'];

        if ($preference !== null && ! in_array($preference, ['wallet', 'opm'], true)) {
            $preference = null;
        }

        return [
            'refund_preference' => $preference,
            'refund_preference_label' => self::refundPreferenceLabel($preference),
            'operator_notes' => ($notes !== null && trim($notes) !== '') ? trim($notes) : null,
        ];
    }

    public static function refundPreferenceLabel(?string $preference): ?string
    {
        return match ($preference) {
            'wallet' => 'Wallet credit',
            'opm' => 'Original payment method (OPM)',
            default => null,
        };
    }
}

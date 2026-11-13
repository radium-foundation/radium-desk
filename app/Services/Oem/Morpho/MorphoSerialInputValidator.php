<?php

namespace App\Services\Oem\Morpho;

use App\Services\SerialValidation\SerialValidationService;

/**
 * Strict Morpho 11-character serial input gate for adapter calls only.
 * Does not modify {@see SerialValidationService}.
 */
final class MorphoSerialInputValidator
{
    private const MORPHO_ELEVEN_CHAR_PATTERN = '/^[0-9]{4}I[0-9]{6}$/';

    /**
     * @return array{ok: true, serial: string}|array{ok: false, serial: string, message: string}
     */
    public function normalize(string $serial): array
    {
        $normalized = strtoupper(trim($serial));

        if ($normalized === '') {
            return [
                'ok' => false,
                'serial' => $normalized,
                'message' => 'Serial number is required.',
            ];
        }

        if (strlen($normalized) !== 11) {
            return [
                'ok' => false,
                'serial' => $normalized,
                'message' => 'Morpho serial numbers must contain exactly 11 characters.',
            ];
        }

        if (! preg_match(self::MORPHO_ELEVEN_CHAR_PATTERN, $normalized)) {
            return [
                'ok' => false,
                'serial' => $normalized,
                'message' => 'Morpho serial numbers must match pattern NNNNI###### (I in position 5).',
            ];
        }

        return ['ok' => true, 'serial' => $normalized];
    }
}

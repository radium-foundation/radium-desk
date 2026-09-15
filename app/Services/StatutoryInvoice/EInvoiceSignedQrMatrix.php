<?php

namespace App\Services\StatutoryInvoice;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Throwable;

/**
 * Builds a QR module matrix from the stored WhiteBooks/NIC SignedQRCode JWT.
 * Encodes the exact stored string. Does not decode, rewrite, or invent a payload.
 */
final class EInvoiceSignedQrMatrix
{
    public const MIN_BYTES = 64;

    public const MAX_BYTES = 4096;

    public const QUIET_ZONE = 4;

    /**
     * @return list<list<bool>>|null
     */
    public function matrix(?string $signedQr): ?array
    {
        $payload = $this->encodablePayload($signedQr);
        if ($payload === null) {
            return null;
        }

        try {
            $qrCode = Encoder::encode($payload, ErrorCorrectionLevel::M());
        } catch (Throwable) {
            return null;
        }

        $bytes = $qrCode->getMatrix();
        $width = $bytes->getWidth();
        $height = $bytes->getHeight();
        if ($width < 21 || $height < 21 || $width !== $height) {
            return null;
        }

        $quiet = self::QUIET_ZONE;
        $size = $width + ($quiet * 2);
        $dark = 0;
        $matrix = [];
        for ($y = 0; $y < $size; $y++) {
            $row = [];
            for ($x = 0; $x < $size; $x++) {
                $innerX = $x - $quiet;
                $innerY = $y - $quiet;
                $on = $innerX >= 0 && $innerX < $width && $innerY >= 0 && $innerY < $height
                    && $bytes->get($innerX, $innerY) !== 0;
                $row[] = $on;
                if ($on) {
                    $dark++;
                }
            }
            $matrix[] = $row;
        }

        if ($dark < 100) {
            return null;
        }

        return $matrix;
    }

    public function encodablePayload(?string $signedQr): ?string
    {
        if (! is_string($signedQr)) {
            return null;
        }

        if ($signedQr !== trim($signedQr)) {
            return null;
        }

        $length = strlen($signedQr);
        if ($length < self::MIN_BYTES || $length > self::MAX_BYTES) {
            return null;
        }

        if (preg_match('/^[\x20-\x7E]+$/', $signedQr) !== 1) {
            return null;
        }

        if (substr_count($signedQr, '.') !== 2 || ! str_starts_with($signedQr, 'eyJ')) {
            return null;
        }

        return $signedQr;
    }
}

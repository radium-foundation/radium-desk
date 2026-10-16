<?php

namespace App\Services\HistoricalInvoice;

final class IndReducedCopyDisclosure
{
    public const PRIMARY_DISCLAIMER = 'Reprint copy generated from stored order data — not the original tax invoice and not a new tax invoice.';

    /**
     * @return list<string>
     */
    public static function baselineOmissions(): array
    {
        return [
            'E-invoice IRN, Acknowledgement number, and QR code (when not returned by the source API)',
            'Hardware serial numbers',
            'Payment transaction references and payment-mode detail rows',
            'CGST / SGST / IGST split (only aggregate tax may be shown)',
            'Ship-to / supply address when not returned by the source API',
            'Statutory footnotes, authorised signatory, and stamp images from the legacy Admin layout',
            'Amount in words, TCS/TDS rows, and other legacy-only presentation fields',
        ];
    }

    /**
     * @param  array<string, mixed>  $reprint
     * @return list<string>
     */
    public static function omissionsForReprint(array $reprint): array
    {
        $omissions = self::baselineOmissions();

        $einvoice = is_array($reprint['einvoice'] ?? null) ? $reprint['einvoice'] : [];
        if (is_string($einvoice['irn'] ?? null) && trim($einvoice['irn']) !== '') {
            $omissions = array_values(array_filter(
                $omissions,
                static fn (string $line): bool => ! str_starts_with($line, 'E-invoice IRN'),
            ));
        }

        $buyer = is_array($reprint['buyer'] ?? null) ? $reprint['buyer'] : [];
        if (is_string($buyer['address'] ?? null) && trim($buyer['address']) !== '') {
            $omissions = array_values(array_filter(
                $omissions,
                static fn (string $line): bool => ! str_starts_with($line, 'Ship-to'),
            ));
        }

        $lines = is_array($reprint['lines'] ?? null) ? $reprint['lines'] : [];
        if ($lines === []) {
            $omissions[] = 'Line-item detail was not returned by the source API';
        }

        return $omissions;
    }
}

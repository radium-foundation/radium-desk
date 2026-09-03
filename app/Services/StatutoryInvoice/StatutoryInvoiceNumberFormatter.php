<?php

namespace App\Services\StatutoryInvoice;

use Illuminate\Validation\ValidationException;

final class StatutoryInvoiceNumberFormatter
{
    public function format(string $template, string $series, int $seq, ?string $gstin, ?string $financialYear): string
    {
        $template = trim($template);
        if ($template === '') {
            throw ValidationException::withMessages([
                'number_format' => 'Statutory invoice number format is not configured. CA approval is required.',
            ]);
        }

        if (str_contains($template, '{gstin}') && ($gstin === null || $gstin === '')) {
            throw ValidationException::withMessages([
                'gstin_scope' => 'Number format includes {gstin} but GSTIN scope is unset. CA decision required.',
            ]);
        }

        if (str_contains($template, '{fy}') && ($financialYear === null || $financialYear === '')) {
            throw ValidationException::withMessages([
                'financial_year' => 'Number format includes {fy} but financial year is unset. CA decision required.',
            ]);
        }

        $number = preg_replace_callback(
            '/\{seq(?::(\d+))?\}/',
            function (array $matches) use ($seq): string {
                $width = isset($matches[1]) ? (int) $matches[1] : 0;

                return $width > 0
                    ? str_pad((string) $seq, $width, '0', STR_PAD_LEFT)
                    : (string) $seq;
            },
            $template,
        );

        $number = str_replace(
            ['{series}', '{gstin}', '{fy}'],
            [$series, (string) $gstin, (string) $financialYear],
            (string) $number,
        );

        $number = trim($number);
        if ($number === '' || str_contains($number, '{')) {
            throw ValidationException::withMessages([
                'number_format' => 'Statutory invoice number format produced an invalid number.',
            ]);
        }

        return $number;
    }
}

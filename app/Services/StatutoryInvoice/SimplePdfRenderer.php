<?php

namespace App\Services\StatutoryInvoice;

use App\Services\StatutoryInvoice\Data\StatutoryInvoicePdfPayload;

class SimplePdfRenderer
{
    public function render(StatutoryInvoicePdfPayload $payload): string
    {
        $lines = $this->textLines($payload);
        $content = "BT\n/F1 10 Tf\n";
        $y = 800;
        foreach ($lines as $line) {
            $content .= sprintf("1 0 0 1 40 %d Tm (%s) Tj\n", $y, $this->escape($line));
            $y -= 14;
            if ($y < 40) {
                break;
            }
        }
        $content .= "ET\n";

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($content)." >>\nstream\n".$content.'endstream',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n".'0 '.(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\n";
        $pdf .= "startxref\n".$xref."\n%%EOF\n";

        return $pdf;
    }

    /**
     * @return list<string>
     */
    private function textLines(StatutoryInvoicePdfPayload $payload): array
    {
        $lines = [
            'TAX INVOICE',
            'Invoice '.$payload->invoiceNumber,
            'Issued '.$payload->issuedAt,
            'Seller '.$payload->sellerLegalName,
            'Seller GSTIN '.$payload->sellerGstin,
            'Seller address '.$payload->sellerAddress,
            'Seller state '.$payload->sellerState,
            'Buyer '.$payload->buyerName,
            'Buyer GSTIN '.($payload->buyerGstin ?? 'B2C'),
            'Billing '.($payload->billingAddress ?? 'unset'),
            'Place of supply '.$payload->placeOfSupply,
            'Lines',
        ];

        foreach ($payload->lines as $line) {
            $lines[] = sprintf(
                '%s HSN %s Qty %d Taxable %s CGST %s SGST %s IGST %s Total %s',
                $line['description'],
                $line['hsnSac'],
                $line['qty'],
                $line['taxableValue'],
                $line['cgst'],
                $line['sgst'],
                $line['igst'],
                $line['lineTotal'],
            );
        }

        $lines[] = 'Taxable '.$payload->taxableValue;
        $lines[] = 'CGST '.$payload->cgst;
        $lines[] = 'SGST '.$payload->sgst;
        $lines[] = 'IGST '.$payload->igst;
        $lines[] = 'Invoice value '.$payload->invoiceValue;
        $lines[] = 'IRN not submitted';

        return $lines;
    }

    private function escape(string $text): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? $text;

        return substr(str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ascii), 0, 110);
    }
}

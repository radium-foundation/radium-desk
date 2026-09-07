<?php

namespace App\Services\StatutoryInvoice;

use App\Services\StatutoryInvoice\Data\StatutoryInvoicePdfPayload;

class SimplePdfRenderer
{
    public const ANNEXURE_THRESHOLD = 5;

    private const LINES_PER_PAGE = 52;

    public function render(StatutoryInvoicePdfPayload $payload): string
    {
        $pages = $this->paginate($this->invoiceLines($payload), $this->annexureLines($payload));
        $contents = [];
        foreach ($pages as $pageLines) {
            $contents[] = $this->pageStream($pageLines);
        }

        $pageCount = count($contents);
        $fontObject = 3 + ($pageCount * 2);
        $kids = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $kids[] = (3 + ($i * 2)).' 0 R';
        }

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$pageCount.' >>',
        ];

        for ($i = 0; $i < $pageCount; $i++) {
            $contentObject = 4 + ($i * 2);
            $objects[] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents %d 0 R /Resources << /Font << /F1 %d 0 R >> >> >>',
                $contentObject,
                $fontObject,
            );
            $objects[] = '<< /Length '.strlen($contents[$i])." >>\nstream\n".$contents[$i].'endstream';
        }

        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>';

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
     * @param  list<string>  $invoiceLines
     * @param  list<string>  $annexureLines
     * @return list<list<string>>
     */
    private function paginate(array $invoiceLines, array $annexureLines): array
    {
        $pages = array_chunk($invoiceLines, self::LINES_PER_PAGE);
        if ($pages === []) {
            $pages = [[]];
        }
        if ($annexureLines !== []) {
            $pages = array_merge($pages, array_chunk($annexureLines, self::LINES_PER_PAGE));
        }

        return $pages;
    }

    /**
     * @param  list<string>  $lines
     */
    private function pageStream(array $lines): string
    {
        $content = "BT\n/F1 10 Tf\n";
        $y = 800;
        foreach ($lines as $line) {
            $content .= sprintf("1 0 0 1 40 %d Tm (%s) Tj\n", $y, $this->escape($line));
            $y -= 14;
        }
        $content .= "ET\n";

        return $content;
    }

    /**
     * @return list<string>
     */
    private function invoiceLines(StatutoryInvoicePdfPayload $payload): array
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
            $lines[] = $line['description'];
            $lines[] = sprintf(
                'HSN/SAC %s Qty %d Taxable %s GST %s CGST %s SGST %s IGST %s Tax %s Total %s',
                $line['hsnSac'],
                $line['qty'],
                $line['taxableValue'],
                $line['gstPercentage'],
                $line['cgst'],
                $line['sgst'],
                $line['igst'],
                $line['taxTotal'],
                $line['lineTotal'],
            );
        }

        $lines[] = 'Taxable '.$payload->taxableValue;
        $lines[] = 'GST rate '.$payload->gstRate;
        $lines[] = 'Total GST '.$payload->taxTotal;
        $lines[] = 'CGST '.$payload->cgst;
        $lines[] = 'SGST '.$payload->sgst;
        $lines[] = 'IGST '.$payload->igst;
        $lines[] = 'Invoice value '.$payload->invoiceValue;
        $lines[] = 'IRN not submitted';
        $lines = array_merge($lines, $this->serialSummaryLines($payload));

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function serialSummaryLines(StatutoryInvoicePdfPayload $payload): array
    {
        $serials = $this->normalizedSerials($payload);
        if ($serials === []) {
            return [];
        }

        if (count($serials) <= self::ANNEXURE_THRESHOLD) {
            return ['Serial numbers: '.implode(', ', $serials)];
        }

        return ['Serial Numbers: See Annexure A'];
    }

    /**
     * @return list<string>
     */
    private function annexureLines(StatutoryInvoicePdfPayload $payload): array
    {
        $serials = $this->normalizedSerials($payload);
        if (count($serials) <= self::ANNEXURE_THRESHOLD) {
            return [];
        }

        $lines = [
            'ANNEXURE A — Serial Numbers',
            'Invoice '.$payload->invoiceNumber,
            'Order '.($payload->sourceId ?? 'unset'),
            'Statutory identity '.($payload->sourceId !== null
                ? 'statutory:radiumbox_com:commerce_order:'.$payload->sourceId
                : 'unset'),
            'Fulfilment '.($payload->fulfilmentId !== null ? (string) $payload->fulfilmentId : 'unset'),
            'Total serials '.count($serials),
            'This annexure is part of the same tax invoice. It is not a second invoice.',
            '',
        ];

        foreach ($serials as $index => $serial) {
            $lines[] = sprintf('%d. %s', $index + 1, $serial);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function normalizedSerials(StatutoryInvoicePdfPayload $payload): array
    {
        $out = [];
        foreach ($payload->serialNumbers as $serial) {
            $value = trim((string) $serial);
            if ($value !== '') {
                $out[] = $value;
            }
        }

        return $out;
    }

    private function escape(string $text): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? $text;

        return substr(str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ascii), 0, 110);
    }
}

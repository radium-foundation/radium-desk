<?php

namespace App\Services\StatutoryInvoice;

use App\Services\StatutoryInvoice\Data\StatutoryInvoicePdfPayload;

class SimplePdfRenderer
{
    public const ANNEXURE_THRESHOLD = 5;

    private const LINES_PER_PAGE = 52;

    private const PAGE_WIDTH = 595;

    private const PAGE_HEIGHT = 842;

    private const MARGIN = 40;

    public function render(StatutoryInvoicePdfPayload $payload): string
    {
        $contents = $this->invoicePageStreams($payload);
        foreach (array_chunk($this->annexureLines($payload), self::LINES_PER_PAGE) as $pageLines) {
            $contents[] = $this->pageStream($pageLines);
        }

        if ($contents === []) {
            $contents[] = $this->pageStream(['TAX INVOICE']);
        }

        $pageCount = count($contents);
        $regularFont = 3 + ($pageCount * 2);
        $boldFont = $regularFont + 1;
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
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Contents %d 0 R /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> >> >>',
                self::PAGE_WIDTH,
                self::PAGE_HEIGHT,
                $contentObject,
                $regularFont,
                $boldFont,
            );
            $objects[] = '<< /Length '.strlen($contents[$i])." >>\nstream\n".$contents[$i].'endstream';
        }

        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';

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
    private function invoicePageStreams(StatutoryInvoicePdfPayload $payload): array
    {
        $lineCards = $this->lineCards($payload);
        $chunks = $lineCards === [] ? [[]] : array_chunk($lineCards, 6);
        $streams = [];
        $pageCount = count($chunks);

        foreach ($chunks as $index => $cards) {
            $streams[] = $this->invoicePageStream(
                $payload,
                $cards,
                $index + 1,
                $pageCount,
                $index === $pageCount - 1,
            );
        }

        return $streams;
    }

    /**
     * @param  list<list<string>>  $cards
     */
    private function invoicePageStream(
        StatutoryInvoicePdfPayload $payload,
        array $cards,
        int $page,
        int $pageCount,
        bool $includeTotals,
    ): string {
        $ops = [];
        $y = 800;

        $ops[] = $this->rect(self::MARGIN, 768, self::PAGE_WIDTH - (self::MARGIN * 2), 50, false);
        $ops[] = $this->text(self::MARGIN + 8, 800, $payload->sellerLegalName, 13, true);
        $ops[] = $this->text(self::MARGIN + 8, 784, 'GSTIN '.$payload->sellerGstin, 9);
        $ops[] = $this->text(340, 800, 'TAX INVOICE', 16, true);
        $ops[] = $this->text(340, 784, 'Invoice '.$payload->invoiceNumber, 10, true);
        $ops[] = $this->text(340, 770, 'Date '.$this->invoiceDate($payload->issuedAt), 9);
        $y = 750;

        $ops[] = $this->text(self::MARGIN, $y, $this->clip($payload->sellerAddress, 78), 8);
        $y -= 12;
        $ops[] = $this->text(self::MARGIN, $y, 'State '.$payload->sellerState, 8);
        $y -= 18;

        if ($payload->hasIssuedIrn()) {
            $ops[] = $this->text(self::MARGIN, $y, 'IRN '.$payload->irn, 8, true);
            $y -= 12;
            $ack = trim(implode('   ', array_filter([
                $payload->ackNo !== null ? 'Ack. No. '.$payload->ackNo : null,
                $payload->ackDate !== null ? 'Ack. date '.$payload->ackDate : null,
            ])));
            if ($ack !== '') {
                $ops[] = $this->text(self::MARGIN, $y, $ack, 8);
                $y -= 14;
            }
        }

        $ops[] = $this->line(self::MARGIN, $y, 555, $y);
        $y -= 16;
        $ops[] = $this->text(self::MARGIN, $y, 'Seller', 9, true);
        $ops[] = $this->text(310, $y, 'Buyer', 9, true);
        $y -= 13;
        $ops[] = $this->text(self::MARGIN, $y, $this->clip($payload->sellerLegalName, 42), 8);
        $ops[] = $this->text(310, $y, $this->clip($payload->buyerName, 42), 8);
        $y -= 12;
        $ops[] = $this->text(self::MARGIN, $y, 'GSTIN '.$payload->sellerGstin, 8);
        $ops[] = $this->text(310, $y, 'GSTIN '.($payload->buyerGstin ?: 'B2C'), 8);
        $y -= 12;
        $sellerAddressLines = $this->wrap($payload->sellerAddress !== '' ? $payload->sellerAddress : 'unset', 42);
        $buyerAddressLines = $this->wrap($payload->billingAddress ?: 'unset', 42);
        $addressRows = max(count($sellerAddressLines), count($buyerAddressLines));
        for ($i = 0; $i < $addressRows; $i++) {
            $ops[] = $this->text(self::MARGIN, $y, $sellerAddressLines[$i] ?? '', 8);
            $ops[] = $this->text(310, $y, $buyerAddressLines[$i] ?? '', 8);
            $y -= 11;
        }
        $ops[] = $this->text(self::MARGIN, $y, 'State '.$payload->sellerState, 8);
        $ops[] = $this->text(310, $y, 'Place of supply '.$payload->placeOfSupply, 8);
        $y -= 12;
        $ops[] = $this->text(310, $y, 'State '.$payload->placeOfSupply, 8);
        $y -= 16;

        $ops[] = $this->line(self::MARGIN, $y, 555, $y);
        $y -= 16;
        $ops[] = $this->text(self::MARGIN, $y, 'Particulars', 9, true);
        $y -= 14;

        foreach ($cards as $cardLines) {
            foreach ($cardLines as $line) {
                $ops[] = $this->text(self::MARGIN, $y, $line, 8);
                $y -= 11;
            }
            $y -= 6;
        }

        if ($includeTotals) {
            $y -= 4;
            $ops[] = $this->line(self::MARGIN, $y, 555, $y);
            $y -= 16;
            $ops[] = $this->text(330, $y, 'Taxable '.$payload->taxableValue, 9);
            $y -= 12;
            $ops[] = $this->text(330, $y, 'GST rate '.$payload->gstRate, 9);
            $y -= 12;
            $ops[] = $this->text(330, $y, 'CGST '.$payload->cgst, 9);
            $y -= 12;
            $ops[] = $this->text(330, $y, 'SGST '.$payload->sgst, 9);
            $y -= 12;
            $ops[] = $this->text(330, $y, 'IGST '.$payload->igst, 9);
            $y -= 12;
            $ops[] = $this->text(330, $y, 'Total GST '.$payload->taxTotal, 9);
            $y -= 14;
            $ops[] = $this->text(330, $y, 'Invoice value '.$payload->invoiceValue, 11, true);
            $y -= 13;
            $ops[] = $this->text(330, $y, 'Amount payable '.$payload->invoiceValue, 9, true);
            $y -= 18;

            foreach ($this->serialSummaryLines($payload) as $serialLine) {
                $ops[] = $this->text(self::MARGIN, $y, $serialLine, 8);
                $y -= 12;
            }
        }

        if ($pageCount > 1) {
            $ops[] = $this->text(self::MARGIN, 36, 'Page '.$page.' of '.$pageCount, 8);
        }

        return implode('', $ops);
    }

    /**
     * @return list<list<string>>
     */
    private function lineCards(StatutoryInvoicePdfPayload $payload): array
    {
        $cards = [];
        foreach (array_values($payload->lines) as $index => $line) {
            $sr = $index + 1;
            $descriptionLines = $this->wrap((string) $line['description'], 86);
            $first = $descriptionLines[0] ?? '';
            $card = [
                $sr.'. '.$first,
            ];
            foreach (array_slice($descriptionLines, 1) as $wrapped) {
                $card[] = '   '.$wrapped;
            }
            $card[] = sprintf(
                '   HSN/SAC %s  Qty %d  Rate %s  Taxable %s  GST %s',
                $line['hsnSac'],
                $line['qty'],
                $line['unitPrice'] ?? $line['taxableValue'],
                $line['taxableValue'],
                $line['gstPercentage'],
            );
            $card[] = sprintf(
                '   CGST %s  SGST %s  IGST %s  Tax %s  Total %s',
                $line['cgst'],
                $line['sgst'],
                $line['igst'],
                $line['taxTotal'],
                $line['lineTotal'],
            );
            $cards[] = $card;
        }

        return $cards;
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

    /**
     * @return list<string>
     */
    private function wrap(string $text, int $width): array
    {
        $clean = trim($this->ascii($text));
        if ($clean === '') {
            return [''];
        }

        $words = preg_split('/\s+/', $clean) ?: [$clean];
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;
            if (strlen($candidate) <= $width) {
                $current = $candidate;

                continue;
            }
            if ($current !== '') {
                $lines[] = $current;
            }
            $current = $word;
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines === [] ? [''] : $lines;
    }

    private function invoiceDate(string $issuedAt): string
    {
        if ($issuedAt === '') {
            return '';
        }

        try {
            return (new \DateTimeImmutable($issuedAt))->format('d M Y');
        } catch (\Exception) {
            return $issuedAt;
        }
    }

    private function text(float $x, float $y, string $text, int $size, bool $bold = false): string
    {
        $font = $bold ? 'F2' : 'F1';

        return sprintf(
            "BT\n/%s %d Tf\n1 0 0 1 %.2F %.2F Tm (%s) Tj\nET\n",
            $font,
            $size,
            $x,
            $y,
            $this->escape($text),
        );
    }

    private function line(float $x1, float $y1, float $x2, float $y2): string
    {
        return sprintf("q\n0.4 w\n%.2F %.2F m\n%.2F %.2F l\nS\nQ\n", $x1, $y1, $x2, $y2);
    }

    private function rect(float $x, float $y, float $w, float $h, bool $fill): string
    {
        $paint = $fill ? 'f' : 'S';

        return sprintf("q\n0.6 w\n%.2F %.2F %.2F %.2F re\n%s\nQ\n", $x, $y, $w, $h, $paint);
    }

    private function clip(string $text, int $width): string
    {
        $ascii = $this->ascii($text);
        if (strlen($ascii) <= $width) {
            return $ascii;
        }

        return substr($ascii, 0, max(0, $width - 3)).'...';
    }

    private function ascii(string $text): string
    {
        return preg_replace('/[^\x20-\x7E]/', '?', $text) ?? $text;
    }

    private function escape(string $text): string
    {
        $ascii = $this->ascii($text);

        return substr(str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ascii), 0, 140);
    }
}

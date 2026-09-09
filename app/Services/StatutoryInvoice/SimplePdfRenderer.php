<?php

namespace App\Services\StatutoryInvoice;

use App\Services\StatutoryInvoice\Data\StatutoryInvoicePdfPayload;

class SimplePdfRenderer
{
    public const ANNEXURE_THRESHOLD = 5;

    private const PAGE_WIDTH = 595.0;

    private const PAGE_HEIGHT = 842.0;

    private const MARGIN = 40.0;

    private const CONTENT_RIGHT = 555.0;

    private const FOOTER_Y = 42.0;

    private const TOTALS_RESERVE = 168.0;

    public function render(StatutoryInvoicePdfPayload $payload): string
    {
        $contents = $this->invoicePageStreams($payload);
        foreach ($this->annexurePageStreams($payload) as $stream) {
            $contents[] = $stream;
        }

        if ($contents === []) {
            $contents[] = $this->text(self::MARGIN, 800, 'TAX INVOICE', 14, true);
        }

        $totalPages = count($contents);
        if ($totalPages > 1) {
            foreach ($contents as $index => $stream) {
                $contents[$index] = $stream.$this->text(
                    self::MARGIN,
                    self::FOOTER_Y,
                    'Page '.($index + 1).' of '.$totalPages,
                    8,
                );
            }
        }

        return $this->assemble($contents);
    }

    /**
     * @param  list<string>  $contents
     */
    private function assemble(array $contents): string
    {
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
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.0F %.0F] /Contents %d 0 R /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> >> >>',
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
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n";
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
        $rows = $this->lineRows($payload);
        $streams = [];
        $remaining = $rows;
        $page = 1;

        do {
            $isLastAttempt = $page >= 20;
            $needTotals = $remaining === [] || $this->rowsFitWithTotals($remaining);
            [$stream, $remaining] = $this->invoicePage($payload, $remaining, $page, $needTotals || $isLastAttempt);
            $streams[] = $stream;
            $page++;
        } while ($remaining !== [] && $page <= 20);

        if ($remaining !== []) {
            $streams[] = $this->invoicePage($payload, $remaining, $page, true)[0];
        }

        return $streams;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private function invoicePage(
        StatutoryInvoicePdfPayload $payload,
        array $rows,
        int $page,
        bool $includeTotals,
    ): array {
        $ops = [];
        $y = 806.0;
        $first = $page === 1;

        $ops[] = $this->text(self::MARGIN, $y, 'Seller', 8, true);
        $ops[] = $this->text(392, $y, 'TAX INVOICE', 12, true);
        $y -= 14;
        $ops[] = $this->text(self::MARGIN, $y, $payload->sellerLegalName, 11, true);
        $ops[] = $this->text(392, $y, $payload->invoiceNumber, 10, true);
        $y -= 13;
        $ops[] = $this->text(self::MARGIN, $y, 'GSTIN '.$this->display($payload->sellerGstin), 9);
        $ops[] = $this->text(392, $y, 'Invoice date '.$this->invoiceDate($payload->issuedAt), 9);
        $y -= 12;
        foreach (array_slice($this->wrapWidth($this->display($payload->sellerAddress), 330, 8), 0, 2) as $addressLine) {
            $ops[] = $this->text(self::MARGIN, $y, $addressLine, 8);
            $y -= 11;
        }
        if ($payload->sellerState !== '') {
            $ops[] = $this->text(self::MARGIN, $y, 'State '.$this->display($payload->sellerState), 8);
            $y -= 12;
        }
        $ops[] = $this->line(self::MARGIN, $y, self::CONTENT_RIGHT, $y);
        $y -= 14;

        $ops[] = $this->text(self::MARGIN, $y, 'Invoice no. '.$payload->invoiceNumber, 9, true);
        $ops[] = $this->rightText(self::CONTENT_RIGHT, $y, 'Invoice date '.$this->invoiceDate($payload->issuedAt), 9, true);
        $y -= 16;

        if ($first && $payload->hasIssuedIrn()) {
            $irnLines = $this->wrapWidth((string) $payload->irn, 500, 8);
            $ack = trim(implode('    ', array_filter([
                $payload->ackNo !== null && $payload->ackNo !== '' ? 'Ack. No. '.$payload->ackNo : null,
                $payload->ackDate !== null && $payload->ackDate !== '' ? 'Ack. date '.$payload->ackDate : null,
            ])));
            $boxHeight = 20 + (11 * count($irnLines)) + ($ack !== '' ? 12 : 0);
            $ops[] = $this->rect(self::MARGIN, $y - $boxHeight + 10, self::CONTENT_RIGHT - self::MARGIN, $boxHeight);
            $ops[] = $this->text(self::MARGIN + 6, $y - 2, 'IRN', 7, true);
            foreach ($irnLines as $irnLine) {
                $y -= 11;
                $ops[] = $this->text(self::MARGIN + 6, $y, $irnLine, 8);
            }
            if ($ack !== '') {
                $y -= 11;
                $ops[] = $this->text(self::MARGIN + 6, $y, $ack, 8);
            }
            $y -= 16;
        }

        if ($first) {
            $y = $this->partyPanels($ops, $payload, $y);
        }

        $y -= 8;
        $ops[] = $this->tableHeader($y);
        $y -= 18;

        $drawn = 0;
        foreach ($rows as $index => $row) {
            $height = $this->rowHeight($row);
            $floor = $includeTotals && $index === 0 && $drawn === 0
                ? self::FOOTER_Y + self::TOTALS_RESERVE
                : self::FOOTER_Y + 18;
            if ($y - $height < $floor && $drawn > 0) {
                break;
            }
            $ops[] = $this->tableRow($row, $y);
            $y -= $height;
            $drawn++;
        }

        $remaining = array_slice($rows, $drawn);

        if ($includeTotals && $remaining === []) {
            $y -= 10;
            $ops[] = $this->totalsBox($payload, $y);
            $y -= self::TOTALS_RESERVE - 20;
            foreach ($this->serialSummaryLines($payload) as $serialLine) {
                $ops[] = $this->text(self::MARGIN, $y, $serialLine, 8);
                $y -= 12;
            }
        }

        $ops[] = $this->text(self::CONTENT_RIGHT - $this->textWidth($payload->sellerLegalName, 8), self::FOOTER_Y, $payload->sellerLegalName, 8);

        return [implode('', $ops), $remaining];
    }

    /**
     * @param  list<string>  $ops
     */
    private function partyPanels(array &$ops, StatutoryInvoicePdfPayload $payload, float $y): float
    {
        $left = self::MARGIN;
        $mid = 297.0;
        $width = 250.0;
        $sellerLines = $this->partyLines(
            $payload->sellerLegalName,
            $this->display($payload->sellerGstin),
            $payload->sellerAddress,
            $payload->sellerState,
            null,
        );
        $buyerGstin = $payload->buyerGstin !== null && trim($payload->buyerGstin) !== ''
            ? $payload->buyerGstin
            : 'Unregistered';
        $buyerLines = $this->partyLines(
            $payload->buyerName,
            $buyerGstin,
            $payload->billingAddress,
            $payload->placeOfSupply,
            $payload->placeOfSupply,
        );
        $rows = max(count($sellerLines), count($buyerLines));
        $height = 18 + ($rows * 11) + 10;

        $ops[] = $this->rect($left, $y - $height + 8, $width, $height);
        $ops[] = $this->rect($mid, $y - $height + 8, $width, $height);
        $ops[] = $this->fill($left, $y - 4, $width, 14, 0.93, 0.94, 0.95);
        $ops[] = $this->fill($mid, $y - 4, $width, 14, 0.93, 0.94, 0.95);
        $ops[] = $this->text($left + 6, $y - 1, 'Seller', 8, true);
        $ops[] = $this->text($mid + 6, $y - 1, 'Bill To', 8, true);

        $textY = $y - 16;
        for ($i = 0; $i < $rows; $i++) {
            $sellerLine = $sellerLines[$i] ?? '';
            $buyerLine = $buyerLines[$i] ?? '';
            if ($sellerLine !== '') {
                $ops[] = $this->text($left + 6, $textY, $sellerLine, 8);
            }
            if ($buyerLine !== '') {
                $ops[] = $this->text($mid + 6, $textY, $buyerLine, 8);
            }
            $textY -= 11;
        }

        $y = $y - $height - 6;

        if ($payload->hasDistinctShippingAddress()) {
            $shipLines = $this->wrapWidth($this->display($payload->shippingAddress), 500, 8);
            $shipHeight = 18 + (11 * count($shipLines)) + 8;
            $ops[] = $this->rect($left, $y - $shipHeight + 8, self::CONTENT_RIGHT - self::MARGIN, $shipHeight);
            $ops[] = $this->fill($left, $y - 4, self::CONTENT_RIGHT - self::MARGIN, 14, 0.93, 0.94, 0.95);
            $ops[] = $this->text($left + 6, $y - 1, 'Ship To', 8, true);
            $shipY = $y - 16;
            foreach ($shipLines as $line) {
                $ops[] = $this->text($left + 6, $shipY, $line, 8);
                $shipY -= 11;
            }
            $y = $y - $shipHeight - 6;
        }

        return $y;
    }

    /**
     * @return list<string>
     */
    private function partyLines(
        string $name,
        string $gstin,
        ?string $address,
        string $state,
        ?string $placeOfSupply,
    ): array {
        $lines = [];
        foreach ($this->wrapWidth($this->display($name), 236, 8) as $line) {
            $lines[] = $line;
        }
        $lines[] = 'GSTIN '.$this->display($gstin);
        $addressLines = $this->wrapWidth($this->display($address), 236, 8);
        foreach ($addressLines as $line) {
            $lines[] = $line;
        }
        $lines[] = 'State '.$this->display($state);
        if ($placeOfSupply !== null) {
            $lines[] = 'Place of supply '.$this->display($placeOfSupply);
        }

        return $lines;
    }

    private function tableHeader(float $y): string
    {
        $ops = [];
        $ops[] = $this->fill(self::MARGIN, $y - 12, self::CONTENT_RIGHT - self::MARGIN, 16, 0.16, 0.20, 0.28);
        $ops[] = $this->text(44, $y - 8, '#', 7, true, 1, 1, 1);
        $ops[] = $this->text(58, $y - 8, 'Description', 7, true, 1, 1, 1);
        $ops[] = $this->text(248, $y - 8, 'HSN/SAC', 7, true, 1, 1, 1);
        $ops[] = $this->rightText(326, $y - 8, 'Qty', 7, true, 1, 1, 1);
        $ops[] = $this->rightText(378, $y - 8, 'Rate', 7, true, 1, 1, 1);
        $ops[] = $this->rightText(434, $y - 8, 'Taxable', 7, true, 1, 1, 1);
        $ops[] = $this->rightText(476, $y - 8, 'GST %', 7, true, 1, 1, 1);
        $ops[] = $this->rightText(551, $y - 8, 'Total', 7, true, 1, 1, 1);

        return implode('', $ops);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function tableRow(array $row, float $y): string
    {
        $ops = [];
        $ops[] = $this->text(44, $y, (string) $row['sr'], 8);
        $descY = $y;
        foreach ($row['descLines'] as $line) {
            $ops[] = $this->text(58, $descY, $line, 8);
            $descY -= 11;
        }
        $ops[] = $this->text(248, $y, (string) $row['hsn'], 8);
        $ops[] = $this->rightText(326, $y, (string) $row['qty'], 8);
        $ops[] = $this->rightText(378, $y, (string) $row['rate'], 8);
        $ops[] = $this->rightText(434, $y, (string) $row['taxable'], 8);
        $ops[] = $this->rightText(476, $y, (string) $row['gst'], 8);
        $ops[] = $this->rightText(551, $y, (string) $row['amount'], 8);
        $ops[] = $this->text(58, $descY - 1, (string) $row['taxLine'], 7);

        return implode('', $ops);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowHeight(array $row): float
    {
        return 14 + (11 * max(0, count($row['descLines']) - 1)) + 14;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function rowsFitWithTotals(array $rows): bool
    {
        $height = 0.0;
        foreach ($rows as $row) {
            $height += $this->rowHeight($row);
        }

        return $height <= 280;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lineRows(StatutoryInvoicePdfPayload $payload): array
    {
        $rows = [];
        foreach (array_values($payload->lines) as $index => $line) {
            $rows[] = [
                'sr' => (string) ($index + 1),
                'descLines' => $this->wrapWidth((string) $line['description'], 184, 8),
                'hsn' => $this->display((string) $line['hsnSac']),
                'qty' => (string) $line['qty'],
                'rate' => $this->money((string) ($line['unitPrice'] ?? $line['taxableValue'])),
                'taxable' => $this->money((string) $line['taxableValue']),
                'gst' => $this->display((string) $line['gstPercentage']),
                'amount' => $this->money((string) $line['lineTotal']),
                'taxLine' => sprintf(
                    'CGST %s   SGST %s   IGST %s   Tax %s',
                    $this->money((string) $line['cgst']),
                    $this->money((string) $line['sgst']),
                    $this->money((string) $line['igst']),
                    $this->money((string) $line['taxTotal']),
                ),
            ];
        }

        return $rows;
    }

    private function totalsBox(StatutoryInvoicePdfPayload $payload, float $y): string
    {
        $ops = [];
        $x = 330.0;
        $pairs = [
            ['Taxable', $this->money($payload->taxableValue), false],
            ['GST rate', $this->display($payload->gstRate), false],
            ['CGST', $this->money($payload->cgst), false],
            ['SGST', $this->money($payload->sgst), false],
            ['IGST', $this->money($payload->igst), false],
            ['Total GST', $this->money($payload->taxTotal), false],
            ['Invoice value', $this->money($payload->invoiceValue), true],
            ['Amount payable', $this->money($payload->invoiceValue), true],
        ];
        $payment = trim((string) ($payload->paymentMethod ?? ''));
        if ($payment !== '') {
            $pairs[] = ['Payment', $this->display($payment), false];
        }
        $ops[] = $this->rect($x - 8, $y - 118 - ($payment !== '' ? 14 : 0), 233, 130 + ($payment !== '' ? 14 : 0));
        $lineY = $y;
        foreach ($pairs as [$label, $value, $bold]) {
            $ops[] = $this->text($x, $lineY, $label, $bold ? 9 : 8, $bold);
            $ops[] = $this->rightText(547, $lineY, $value, $bold ? 9 : 8, $bold);
            $lineY -= $bold ? 14 : 12;
        }
        $ops[] = $this->line($x, $y - 72, 547, $y - 72);

        return implode('', $ops);
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
    private function annexurePageStreams(StatutoryInvoicePdfPayload $payload): array
    {
        $serials = $this->normalizedSerials($payload);
        if (count($serials) <= self::ANNEXURE_THRESHOLD) {
            return [];
        }

        $lines = [
            'ANNEXURE A - Serial Numbers',
            'Invoice '.$payload->invoiceNumber,
            'Order '.($payload->sourceId ?? '-'),
            'Total serials '.count($serials),
            'This annexure is part of the same tax invoice. It is not a second invoice.',
        ];
        foreach ($serials as $index => $serial) {
            $lines[] = sprintf('%d. %s', $index + 1, $serial);
        }

        $pages = [];
        foreach (array_chunk($lines, 48) as $chunk) {
            $ops = [];
            $y = 800.0;
            foreach ($chunk as $i => $line) {
                $ops[] = $this->text(self::MARGIN, $y, $line, $i === 0 ? 12 : 9, $i === 0);
                $y -= 14;
            }
            $pages[] = implode('', $ops);
        }

        return $pages;
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
    private function wrapWidth(string $text, float $maxWidth, int $size): array
    {
        $clean = trim($this->ascii($text));
        if ($clean === '') {
            return ['-'];
        }

        $words = preg_split('/\s+/', $clean) ?: [$clean];
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;
            if ($this->textWidth($candidate, $size) <= $maxWidth) {
                $current = $candidate;

                continue;
            }
            if ($current !== '') {
                $lines[] = $current;
            }
            if ($this->textWidth($word, $size) > $maxWidth) {
                $lines = array_merge($lines, $this->hardSplit($word, $maxWidth, $size));
                $current = '';

                continue;
            }
            $current = $word;
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines === [] ? ['-'] : $lines;
    }

    /**
     * @return list<string>
     */
    private function hardSplit(string $word, float $maxWidth, int $size): array
    {
        $parts = [];
        $buffer = '';
        foreach (mb_str_split($word) as $char) {
            $candidate = $buffer.$char;
            if ($buffer !== '' && $this->textWidth($candidate, $size) > $maxWidth) {
                $parts[] = $buffer;
                $buffer = $char;
            } else {
                $buffer = $candidate;
            }
        }
        if ($buffer !== '') {
            $parts[] = $buffer;
        }

        return $parts === [] ? [$word] : $parts;
    }

    private function invoiceDate(string $issuedAt): string
    {
        if ($issuedAt === '') {
            return '-';
        }

        try {
            return (new \DateTimeImmutable($issuedAt))->format('d M Y');
        } catch (\Exception) {
            return $this->ascii($issuedAt);
        }
    }

    private function money(string $value): string
    {
        $display = $this->display($value);
        if ($display === '-' || $display === '') {
            return '-';
        }
        if (str_starts_with($display, 'Rs.') || str_contains($display, '%')) {
            return $display;
        }
        if (! is_numeric(str_replace(',', '', $display))) {
            return $display;
        }

        return 'Rs.'.number_format((float) str_replace(',', '', $display), 2, '.', '');
    }

    private function display(?string $value): string
    {
        $ascii = trim($this->ascii((string) $value));
        if ($ascii === '' || in_array(strtolower($ascii), ['unset', 'not recorded', 'null'], true)) {
            return '-';
        }

        return $ascii;
    }

    private function text(
        float $x,
        float $y,
        string $text,
        int $size,
        bool $bold = false,
        float $r = 0,
        float $g = 0,
        float $b = 0,
    ): string {
        return sprintf(
            "BT\n/%s %d Tf\n%.3F %.3F %.3F rg\n1 0 0 1 %.2F %.2F Tm (%s) Tj\n0 0 0 rg\nET\n",
            $bold ? 'F2' : 'F1',
            $size,
            $r,
            $g,
            $b,
            $x,
            $y,
            $this->escape($text),
        );
    }

    private function rightText(
        float $right,
        float $y,
        string $text,
        int $size,
        bool $bold = false,
        float $r = 0,
        float $g = 0,
        float $b = 0,
    ): string {
        return $this->text($right - $this->textWidth($text, $size), $y, $text, $size, $bold, $r, $g, $b);
    }

    private function line(float $x1, float $y1, float $x2, float $y2): string
    {
        return sprintf("q\n0.4 w\n0 0 0 RG\n%.2F %.2F m\n%.2F %.2F l\nS\nQ\n", $x1, $y1, $x2, $y2);
    }

    private function rect(float $x, float $y, float $w, float $h): string
    {
        return sprintf("q\n0.6 w\n0.55 0.58 0.62 RG\n%.2F %.2F %.2F %.2F re\nS\nQ\n", $x, $y, $w, $h);
    }

    private function fill(float $x, float $y, float $w, float $h, float $r, float $g, float $b): string
    {
        return sprintf("q\n%.3F %.3F %.3F rg\n%.2F %.2F %.2F %.2F re\nf\nQ\n", $r, $g, $b, $x, $y, $w, $h);
    }

    private function textWidth(string $text, int $size): float
    {
        $widths = [
            ' ' => 278, '!' => 278, '"' => 355, '#' => 556, '$' => 556, '%' => 889, '&' => 667, '\'' => 191,
            '(' => 333, ')' => 333, '*' => 389, '+' => 584, ',' => 278, '-' => 333, '.' => 278, '/' => 278,
            '0' => 556, '1' => 556, '2' => 556, '3' => 556, '4' => 556, '5' => 556, '6' => 556, '7' => 556,
            '8' => 556, '9' => 556, ':' => 278, ';' => 278, '<' => 584, '=' => 584, '>' => 584, '?' => 556,
            '@' => 1015, 'A' => 667, 'B' => 667, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778,
            'H' => 722, 'I' => 278, 'J' => 500, 'K' => 667, 'L' => 556, 'M' => 833, 'N' => 722, 'O' => 778,
            'P' => 667, 'Q' => 778, 'R' => 722, 'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944,
            'X' => 667, 'Y' => 667, 'Z' => 611, '[' => 278, '\\' => 278, ']' => 278, '_' => 556,
            'a' => 556, 'b' => 556, 'c' => 500, 'd' => 556, 'e' => 556, 'f' => 278, 'g' => 556, 'h' => 556,
            'i' => 222, 'j' => 222, 'k' => 500, 'l' => 222, 'm' => 833, 'n' => 556, 'o' => 556, 'p' => 556,
            'q' => 556, 'r' => 333, 's' => 500, 't' => 278, 'u' => 556, 'v' => 500, 'w' => 722, 'x' => 500,
            'y' => 500, 'z' => 500,
        ];
        $units = 0;
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $units += $widths[$text[$i]] ?? 556;
        }

        return ($units * $size) / 1000;
    }

    private function ascii(string $text): string
    {
        $mapped = strtr($text, [
            '—' => '-',
            '–' => '-',
            '−' => '-',
            '‘' => "'",
            '’' => "'",
            '“' => '"',
            '”' => '"',
            '₹' => 'Rs.',
            '×' => 'x',
            '•' => '-',
            "\u{00A0}" => ' ',
        ]);

        $ascii = preg_replace('/[^\x20-\x7E]/', '', $mapped) ?? $mapped;

        return preg_replace('/\s+/', ' ', $ascii) ?? $ascii;
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $this->ascii($text));
    }
}

<?php

namespace App\Services\StatutoryInvoice;

use App\Services\StatutoryInvoice\Data\StatutoryInvoicePdfPayload;

class SimplePdfRenderer
{
    private const PAGE_WIDTH = 595.0;

    private const PAGE_HEIGHT = 842.0;

    private const MARGIN = 36.0;

    private const CONTENT_RIGHT = 559.0;

    private const FOOTER_Y = 28.0;

    private const CONTENT_FLOOR = 50.0;

    private const COL_NO = 42.0;

    private const COL_PRODUCT = 56.0;

    private const COL_HSN = 220.0;

    private const COL_QTY = 268.0;

    private const COL_UQC = 286.0;

    private const COL_RATE = 348.0;

    private const COL_TAXABLE = 396.0;

    private const COL_GST = 434.0;

    private const COL_TAX = 492.0;

    private const COL_AMOUNT = 559.0;

    private const PRODUCT_WIDTH = 152.0;

    private const LOGO_MAX_WIDTH = 132.0;

    private const LOGO_MAX_HEIGHT = 36.0;

    private const STAMP_MAX_WIDTH = 84.0;

    private const STAMP_MAX_HEIGHT = 48.0;

    private const QR_SIZE = 64.0;

    private const QR_GAP = 10.0;

    private const NAVY_R = 0.145;

    private const NAVY_G = 0.227;

    private const NAVY_B = 0.373;

    public const FIRST_PAGE_SERIAL_LIMIT = 50;

    private const SERIAL_COLUMNS = 4;

    private const SERIAL_ROW_HEIGHT = 10.0;

    /** @var array<string, array{width: int, height: int, data: string}> */
    private array $embeddedImages = [];

    public function render(StatutoryInvoicePdfPayload $payload): string
    {
        $this->embeddedImages = [];
        $this->prepareEmbeddedImages();
        $contents = $this->invoicePageStreams($payload);

        if ($contents === []) {
            $contents[] = $this->text(self::MARGIN, 800, 'TAX INVOICE', 16, true);
        }

        $totalPages = count($contents);
        foreach ($contents as $index => $stream) {
            $contents[$index] = $stream.$this->pageFooter($index + 1, $totalPages);
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
        $imageObjectIds = [];
        $nextObjectId = $boldFont + 1;
        foreach ($this->embeddedImages as $name => $image) {
            $imageObjectIds[$name] = $nextObjectId++;
            unset($image);
        }
        $kids = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $kids[] = (3 + ($i * 2)).' 0 R';
        }

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$pageCount.' >>',
        ];

        $xobjectResource = '';
        if ($imageObjectIds !== []) {
            $pairs = [];
            foreach ($imageObjectIds as $name => $objectId) {
                $pairs[] = '/'.$name.' '.$objectId.' 0 R';
            }
            $xobjectResource = ' /XObject << '.implode(' ', $pairs).' >>';
        }

        for ($i = 0; $i < $pageCount; $i++) {
            $contentObject = 4 + ($i * 2);
            $resources = sprintf(
                '/Font << /F1 %d 0 R /F2 %d 0 R >>%s',
                $regularFont,
                $boldFont,
                $xobjectResource,
            );
            $objects[] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.0F %.0F] /Contents %d 0 R /Resources << %s >> >>',
                self::PAGE_WIDTH,
                self::PAGE_HEIGHT,
                $contentObject,
                $resources,
            );
            $objects[] = '<< /Length '.strlen($contents[$i])." >>\nstream\n".$contents[$i].'endstream';
        }

        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
        foreach ($this->embeddedImages as $image) {
            $objects[] = '<< /Type /XObject /Subtype /Image /Width '.$image['width']
                .' /Height '.$image['height']
                .' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '
                .strlen($image['data'])
                ." >>\nstream\n".$image['data']."\nendstream";
        }

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
        $closing = $this->closingHeight($payload);
        $drewClosing = false;

        do {
            $needClosing = $this->rowsFitWithClosing($remaining, $closing, $page === 1, $payload) || $page >= 20;
            [$stream, $remaining, $closed] = $this->invoicePage($payload, $remaining, $page, $needClosing, $closing);
            $streams[] = $stream;
            $drewClosing = $drewClosing || $closed;
            $page++;
        } while ($remaining !== [] && $page <= 20);

        if ($remaining !== []) {
            [$stream, , $closed] = $this->invoicePage($payload, $remaining, $page, true, $closing);
            $streams[] = $stream;
            $drewClosing = $drewClosing || $closed;
            $page++;
        }

        if (! $drewClosing) {
            $streams[] = $this->invoicePage($payload, [], $page, true, $closing)[0];
        }

        foreach ($this->annexurePageStreams($payload) as $annexure) {
            $streams[] = $annexure;
        }

        return $streams;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: string, 1: list<array<string, mixed>>, 2: bool}
     */
    private function invoicePage(
        StatutoryInvoicePdfPayload $payload,
        array $rows,
        int $page,
        bool $includeClosing,
        float $closing,
    ): array {
        $ops = [];
        $first = $page === 1;

        if ($first) {
            $y = $this->firstPageHeader($ops, $payload);
            $y = $this->partyBlock($ops, $payload, $y);
        } else {
            $y = $this->continuationHeader($ops, $payload);
        }

        if ($first || $rows !== []) {
            $ops[] = $this->tableHeader($y);
            $y -= 18;
        }

        $drawn = 0;
        $serialReserve = $first ? $this->serialSummaryHeight($payload) : 0.0;
        $floor = $includeClosing ? self::CONTENT_FLOOR + $closing + 8 : self::CONTENT_FLOOR + 8;
        $floor += $serialReserve;

        while ($rows !== []) {
            $row = $rows[0];
            $available = $y - $floor;
            if ($available < 18 && $drawn > 0) {
                break;
            }

            [$visible, $leftover] = $this->splitRow($row, $available);
            if ($visible === null) {
                if ($drawn === 0) {
                    [$visible, $leftover] = $this->splitRow($row, $y - self::CONTENT_FLOOR);
                }
                if ($visible === null) {
                    break;
                }
            }

            $ops[] = $this->tableRow($visible, $y);
            $y -= $this->rowHeight($visible);
            $drawn++;
            array_shift($rows);
            if ($leftover !== null) {
                array_unshift($rows, $leftover);
                break;
            }
        }

        if ($first && $serialReserve > 0) {
            $y -= 6;
            $y = $this->serialSummaryBlock($ops, $payload, $y);
        }

        $drewClosing = false;
        if ($includeClosing && $rows === []) {
            $y -= 8;
            $ops[] = $this->closingBlock($payload, $y);
            $drewClosing = true;
        }

        return [implode('', $ops), $rows, $drewClosing];
    }

    /**
     * @param  list<string>  $ops
     */
    private function firstPageHeader(array &$ops, StatutoryInvoicePdfPayload $payload): float
    {
        $y = 808.0;
        $ops[] = $this->logoMark(self::MARGIN, $y);

        $ops[] = $this->rightText(self::CONTENT_RIGHT, $y + 4, 'TAX INVOICE', 16, true, self::NAVY_R, self::NAVY_G, self::NAVY_B);
        $ops[] = $this->accentLine(self::CONTENT_RIGHT - 108, $y - 4, self::CONTENT_RIGHT);

        $cardW = 176.0;
        $cardX = self::CONTENT_RIGHT - $cardW;
        $meta = [
            ['Invoice No.', $payload->invoiceNumber],
            ['Invoice Date', $this->invoiceDate($payload->issuedAt)],
        ];
        $orderId = $this->display($payload->orderId ?: $payload->sourceId);
        if ($orderId !== '-') {
            $meta[] = ['Order ID', $orderId];
        }
        $cardH = 12.0 + (12.0 * count($meta));
        $cardTop = $y - 16;
        $cardBottom = $cardTop - $cardH;
        $ops[] = $this->card($cardX, $cardBottom, $cardW, $cardH);
        $metaY = $cardTop - 12;
        foreach ($meta as [$label, $value]) {
            $ops[] = $this->text($cardX + 8, $metaY, $label, 7, false, 0.38, 0.42, 0.48);
            $ops[] = $this->rightText(self::CONTENT_RIGHT - 8, $metaY, $this->clip((string) $value, 88, 8), 8, true, self::NAVY_R, self::NAVY_G, self::NAVY_B);
            $metaY -= 12;
        }

        $sellerRight = $cardX - 14;
        $sellerWidth = $sellerRight - self::MARGIN;
        $sellerY = $y - self::LOGO_MAX_HEIGHT - 10;
        $ops[] = $this->text(self::MARGIN, $sellerY, $payload->sellerLegalName, 10, true, self::NAVY_R, self::NAVY_G, self::NAVY_B);
        $sellerY -= 12;
        foreach (array_slice($this->wrapWidth($this->display($payload->sellerAddress), $sellerWidth, 8), 0, 2) as $addressLine) {
            $ops[] = $this->text(self::MARGIN, $sellerY, $addressLine, 8, false, 0.22, 0.22, 0.22);
            $sellerY -= 10;
        }

        $contact = implode('  |  ', array_filter([
            $payload->sellerEmail !== null && trim($payload->sellerEmail) !== '' ? $this->display($payload->sellerEmail) : null,
            $payload->sellerPhone !== null && trim($payload->sellerPhone) !== '' ? $this->display($payload->sellerPhone) : null,
        ]));
        if ($contact !== '') {
            $ops[] = $this->text(self::MARGIN, $sellerY, $this->clip($contact, $sellerWidth, 8), 8, false, 0.22, 0.22, 0.22);
            $sellerY -= 10;
        }

        $ops[] = $this->text(self::MARGIN, $sellerY, 'GSTIN '.$this->display($payload->sellerGstin), 8, false, 0.18, 0.18, 0.18);
        $sellerY -= 10;
        $cin = $this->sellerCin();
        if ($cin !== '') {
            $ops[] = $this->text(self::MARGIN, $sellerY, 'CIN: '.$cin, 8, false, 0.18, 0.18, 0.18);
            $sellerY -= 10;
        }

        $y = min($sellerY, $cardBottom) - 10;
        $ops[] = $this->hairline(self::MARGIN, $y, self::CONTENT_RIGHT, 0.82);

        return $y - 12;
    }

    /**
     * @param  list<string>  $ops
     */
    private function continuationHeader(array &$ops, StatutoryInvoicePdfPayload $payload): float
    {
        $y = 808.0;
        $ops[] = $this->logoMark(self::MARGIN, $y);
        $ops[] = $this->rightText(self::CONTENT_RIGHT, $y + 2, 'TAX INVOICE', 11, true, self::NAVY_R, self::NAVY_G, self::NAVY_B);
        $ops[] = $this->rightText(self::CONTENT_RIGHT, $y - 12, $payload->invoiceNumber.'  (continued)', 8, false, 0.32, 0.32, 0.32);
        $y -= 38;
        $ops[] = $this->hairline(self::MARGIN, $y, self::CONTENT_RIGHT, 0.82);

        return $y - 14;
    }

    private function logoMark(float $x, float $topY): string
    {
        return $this->imageDraw('Logo', $x, $topY, self::LOGO_MAX_WIDTH, self::LOGO_MAX_HEIGHT);
    }

    private function stampMark(float $x, float $topY): string
    {
        return $this->imageDraw('Stamp', $x, $topY, self::STAMP_MAX_WIDTH, self::STAMP_MAX_HEIGHT);
    }

    private function imageDraw(string $name, float $x, float $topY, float $maxWidth, float $maxHeight): string
    {
        $image = $this->embeddedImages[$name] ?? null;
        if ($image === null) {
            throw new StatutoryInvoicePdfAssetException('Embedded invoice image missing: '.$name);
        }

        $scale = min($maxWidth / $image['width'], $maxHeight / $image['height']);
        $drawW = $image['width'] * $scale;
        $drawH = $image['height'] * $scale;
        $drawY = $topY - $drawH;

        return sprintf("q\n%.3F 0 0 %.3F %.3F %.3F cm\n/%s Do\nQ\n", $drawW, $drawH, $x, $drawY, $name);
    }

    private function prepareEmbeddedImages(): void
    {
        if ($this->embeddedImages !== []) {
            return;
        }

        $this->embeddedImages['Logo'] = $this->rasterBrandAsset(
            $this->brandAssetPath('logo', 'brand/logo.svg'),
            480,
        );
        $this->embeddedImages['Stamp'] = $this->rasterBrandAsset(
            $this->brandAssetPath('stamp', 'brand/stamp-bgr.png'),
            360,
        );
    }

    private function brandAssetPath(string $key, string $defaultRelative): string
    {
        $configured = config('branding.'.$key);
        $relative = is_string($configured) && $configured !== '' ? $configured : $defaultRelative;
        $path = public_path($relative);
        if (! is_file($path)) {
            throw new StatutoryInvoicePdfAssetException('Authoritative invoice asset missing: '.$relative);
        }

        return $path;
    }

    /**
     * @return array{width: int, height: int, data: string}
     */
    private function rasterBrandAsset(string $path, int $maxRasterWidth): array
    {
        if (! extension_loaded('imagick') || ! class_exists(\Imagick::class)) {
            throw new StatutoryInvoicePdfAssetException('Imagick extension is required to render invoice brand assets.');
        }

        try {
            $image = new \Imagick;
            $image->setBackgroundColor(new \ImagickPixel('white'));
            $image->readImage($path);
            if ($image->getImageWidth() > $maxRasterWidth) {
                $image->resizeImage($maxRasterWidth, 0, \Imagick::FILTER_LANCZOS, 1);
            }
            $flattened = $image->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            $image->destroy();
            $image = $flattened;
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(95);
            $result = [
                'width' => $image->getImageWidth(),
                'height' => $image->getImageHeight(),
                'data' => $image->getImageBlob(),
            ];
            $image->destroy();
        } catch (\Throwable $exception) {
            throw new StatutoryInvoicePdfAssetException(
                'Unable to rasterize invoice asset at '.$path.': '.$exception->getMessage(),
                previous: $exception,
            );
        }

        return $result;
    }

    /**
     * @param  list<string>  $ops
     */
    private function compactIrnVerificationBlock(
        array &$ops,
        StatutoryInvoicePdfPayload $payload,
        float $topY,
        ?float $right = null,
    ): float {
        if (! $payload->hasIssuedIrn()) {
            return $topY;
        }

        $right ??= self::CONTENT_RIGHT;
        $matrix = $payload->hasIssuedSignedQr()
            ? (new EInvoiceSignedQrMatrix)->matrix($payload->signedQr)
            : null;
        $hasQr = $matrix !== null;
        $textX = self::MARGIN + ($hasQr ? self::QR_SIZE + self::QR_GAP + 10 : 8);
        $textRight = $right - 8;
        $irnLines = $this->wrapWidth((string) $payload->irn, max(80, $textRight - $textX), 7);
        $ack = trim(implode('    ', array_filter([
            $payload->ackNo !== null && $payload->ackNo !== '' ? 'Ack No: '.$payload->ackNo : null,
            $payload->ackDate !== null && $payload->ackDate !== '' ? 'Date: '.$this->formatAckDate($payload->ackDate) : null,
        ])));
        $textHeight = 12 + 9 + (9 * count($irnLines)) + ($ack !== '' ? 10 : 0) + 10;
        $blockHeight = max($textHeight + 10, $hasQr ? self::QR_SIZE + 16 : $textHeight + 10);
        $bottomY = $topY - $blockHeight;

        $ops[] = $this->card(self::MARGIN, $bottomY, $right - self::MARGIN, $blockHeight);
        $ty = $topY - 12;
        $ops[] = $this->text(self::MARGIN + 8, $ty, 'e-Invoice Verification', 7, true, self::NAVY_R, self::NAVY_G, self::NAVY_B);
        $ty -= 12;
        if ($hasQr) {
            $ops[] = $this->signedQrImage($matrix, self::MARGIN + 8, $topY - 14, self::QR_SIZE);
        }
        $ops[] = $this->text($textX, $ty, 'IRN', 6, true, 0.42, 0.42, 0.42);
        $ty -= 9;
        foreach ($irnLines as $irnLine) {
            $ops[] = $this->text($textX, $ty, $irnLine, 7);
            $ty -= 9;
        }
        if ($ack !== '') {
            $ops[] = $this->text($textX, $ty, $ack, 7, false, 0.2, 0.2, 0.2);
            $ty -= 10;
        }
        $ops[] = $this->text($textX, $ty, 'Whether tax is payable on reverse charge basis: No', 6, false, 0.32, 0.32, 0.32);
        if (! $hasQr && $payload->hasIssuedSignedQr()) {
            $ops[] = $this->text($textX, $ty - 9, 'Signed QR issued with this IRN.', 7, false, 0.28, 0.28, 0.28);
        }

        return $bottomY - 8;
    }

    /**
     * @param  list<list<bool>>  $matrix
     */
    private function signedQrImage(array $matrix, float $x, float $top, float $size): string
    {
        $n = count($matrix);
        if ($n < 21) {
            return '';
        }

        $module = $size / $n;
        $ops = ['% signed-qr-image'."\n"];
        $ops[] = $this->fill($x, $top - $size, $size, $size, 1, 1, 1);
        $rects = [];
        foreach ($matrix as $row => $cells) {
            if (! is_array($cells) || count($cells) !== $n) {
                return '';
            }
            $col = 0;
            while ($col < $n) {
                if ($cells[$col] !== true) {
                    $col++;

                    continue;
                }
                $start = $col;
                while ($col < $n && $cells[$col] === true) {
                    $col++;
                }
                $rects[] = sprintf(
                    '%.3F %.3F %.3F %.3F re',
                    $x + ($start * $module),
                    $top - $size + (($n - 1 - $row) * $module),
                    ($col - $start) * $module,
                    $module,
                );
            }
        }
        if ($rects === []) {
            return '';
        }

        $ops[] = "q\n0.000 0.000 0.000 rg\n".implode("\n", $rects)."\nf\nQ\n";

        return implode('', $ops);
    }

    /**
     * @param  list<string>  $ops
     */
    private function partyBlock(array &$ops, StatutoryInvoicePdfPayload $payload, float $y): float
    {
        $gap = 10.0;
        $fullWidth = self::CONTENT_RIGHT - self::MARGIN;
        $leftWidth = ($fullWidth - $gap) / 2;
        $rightX = self::MARGIN + $leftWidth + $gap;
        $rightWidth = $fullWidth - $leftWidth - $gap;

        $buyerGstin = $payload->buyerGstin !== null && trim($payload->buyerGstin) !== ''
            ? $payload->buyerGstin
            : 'Unregistered';
        $leftLines = $this->buyerLines($payload, $buyerGstin, $leftWidth - 16);
        $rightLines = $payload->hasDistinctShippingAddress()
            ? $this->rightPartyLines($payload, $rightWidth - 16)
            : ['Same'];
        $rows = max(count($leftLines), count($rightLines), 3);
        $innerH = 16.0 + (11.0 * $rows) + 8.0;
        $cardBottom = $y - $innerH;

        $ops[] = $this->card(self::MARGIN, $cardBottom, $leftWidth, $innerH);
        $ops[] = $this->text(self::MARGIN + 8, $y - 12, 'BILL TO', 7, true, self::NAVY_R, self::NAVY_G, self::NAVY_B);
        $lineY = $y - 24;
        foreach ($leftLines as $i => $line) {
            $ops[] = $this->text(self::MARGIN + 8, $lineY, $line, $i === 0 ? 9 : 8, $i === 0, $i === 0 ? self::NAVY_R : 0.18, $i === 0 ? self::NAVY_G : 0.18, $i === 0 ? self::NAVY_B : 0.18);
            $lineY -= 11;
        }

        $ops[] = $this->card($rightX, $cardBottom, $rightWidth, $innerH);
        $ops[] = $this->text($rightX + 8, $y - 12, 'SHIP TO', 7, true, self::NAVY_R, self::NAVY_G, self::NAVY_B);
        $lineY = $y - 24;
        foreach ($rightLines as $i => $line) {
            $same = $line === 'Same';
            $ops[] = $this->text($rightX + 8, $lineY, $line, $same ? 9 : ($i === 0 ? 9 : 8), $same || $i === 0, $i === 0 || $same ? self::NAVY_R : 0.18, $i === 0 || $same ? self::NAVY_G : 0.18, $i === 0 || $same ? self::NAVY_B : 0.18);
            $lineY -= 11;
        }

        return $cardBottom - 12;
    }

    /**
     * @return list<string>
     */
    private function buyerLines(StatutoryInvoicePdfPayload $payload, string $buyerGstin, float $width): array
    {
        $lines = $this->wrapWidth($this->display($payload->buyerName), $width, 9);
        $addressDisplay = $this->display($payload->billingAddress);
        if ($addressDisplay !== '-') {
            foreach ($this->wrapWidth($addressDisplay, $width, 8) as $line) {
                $lines[] = $line;
            }
        }
        if ($payload->buyerEmail !== null && trim($payload->buyerEmail) !== '') {
            $lines[] = $this->display($payload->buyerEmail);
        }
        if ($payload->buyerPhone !== null && trim($payload->buyerPhone) !== '') {
            $lines[] = $this->display($payload->buyerPhone);
        }
        $lines[] = 'GSTIN '.$this->display($buyerGstin);
        if ($payload->placeOfSupply !== '') {
            $lines[] = 'Place of Supply '.$this->display($payload->placeOfSupply);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function rightPartyLines(StatutoryInvoicePdfPayload $payload, float $width): array
    {
        $lines = [];
        $shippingName = $this->display($payload->buyerName);
        if ($shippingName !== '-') {
            $lines = $this->wrapWidth($shippingName, $width, 9);
        }
        foreach ($this->wrapWidth($this->display($payload->shippingAddress), $width, 8) as $line) {
            $lines[] = $line;
        }
        if ($payload->buyerEmail !== null && trim($payload->buyerEmail) !== '') {
            $lines[] = $this->display($payload->buyerEmail);
        }
        if ($payload->buyerPhone !== null && trim($payload->buyerPhone) !== '') {
            $lines[] = $this->display($payload->buyerPhone);
        }

        return $lines;
    }

    private function tableHeader(float $y): string
    {
        $ops = [];
        $ops[] = $this->hairline(self::MARGIN, $y + 6, self::CONTENT_RIGHT, 0.45);
        $ops[] = $this->hairline(self::MARGIN, $y - 8, self::CONTENT_RIGHT, 0.45);
        $ops[] = $this->text(self::COL_NO, $y, '#', 6, true, self::NAVY_R, self::NAVY_G, self::NAVY_B);
        $ops[] = $this->text(self::COL_PRODUCT, $y, 'Product / Service', 6, true, self::NAVY_R, self::NAVY_G, self::NAVY_B);
        $ops[] = $this->text(self::COL_HSN, $y, 'HSN/SAC', 6, true, self::NAVY_R, self::NAVY_G, self::NAVY_B);
        $ops[] = $this->rightText(self::COL_QTY, $y, 'Qty', 6, true, self::NAVY_R, self::NAVY_G, self::NAVY_B);
        $ops[] = $this->text(self::COL_UQC + 2, $y, 'UQC', 6, true, self::NAVY_R, self::NAVY_G, self::NAVY_B);
        $ops[] = $this->rightText(self::COL_RATE, $y, 'Unit Price', 6, true, self::NAVY_R, self::NAVY_G, self::NAVY_B);
        $ops[] = $this->rightText(self::COL_TAXABLE, $y, 'Taxable', 6, true, self::NAVY_R, self::NAVY_G, self::NAVY_B);
        $ops[] = $this->rightText(self::COL_GST, $y, 'Tax Rate', 6, true, self::NAVY_R, self::NAVY_G, self::NAVY_B);
        $ops[] = $this->rightText(self::COL_TAX, $y, 'Tax Amount', 6, true, self::NAVY_R, self::NAVY_G, self::NAVY_B);
        $ops[] = $this->rightText(self::COL_AMOUNT, $y, 'Amount', 6, true, self::NAVY_R, self::NAVY_G, self::NAVY_B);

        return implode('', $ops);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function tableRow(array $row, float $y): string
    {
        $ops = [];
        if (($row['kind'] ?? 'item') === 'serials') {
            $serialY = $y;
            $label = trim((string) ($row['serialLabel'] ?? ''));
            if ($label !== '') {
                $ops[] = $this->text(self::COL_PRODUCT, $serialY, $label, 7, false, 0.38, 0.38, 0.38);
                $serialY -= 10;
            }
            foreach ($row['serialLines'] as $line) {
                $ops[] = $this->text(self::COL_PRODUCT, $serialY, $line, 7, false, 0.32, 0.32, 0.32);
                $serialY -= 9;
            }
            $ops[] = $this->hairline(self::MARGIN, $y - $this->rowHeight($row) + 4, self::CONTENT_RIGHT, 0.88);

            return implode('', $ops);
        }

        $descY = $y;
        foreach ($row['descLines'] as $index => $line) {
            $ops[] = $this->text(self::COL_PRODUCT, $descY, $line, $index === 0 ? 8 : 7, $index === 0);
            $descY -= $index === 0 ? 10 : 9;
        }
        if (($row['lineNo'] ?? '') !== '') {
            $ops[] = $this->text(self::COL_NO, $y, (string) $row['lineNo'], 8, false, 0.28, 0.28, 0.28);
        }
        $ops[] = $this->text(self::COL_HSN, $y, (string) $row['hsn'], 7, false, 0.22, 0.22, 0.22);
        $ops[] = $this->rightText(self::COL_QTY, $y, (string) $row['qty'], 8);
        $ops[] = $this->text(self::COL_UQC, $y, (string) $row['uqc'], 7, false, 0.22, 0.22, 0.22);
        $ops[] = $this->rightText(self::COL_RATE, $y, (string) $row['rate'], 7);
        $ops[] = $this->rightText(self::COL_TAXABLE, $y, (string) $row['taxable'], 7);
        if (($row['gst'] ?? '') !== '' && ($row['gst'] ?? '') !== '-') {
            $ops[] = $this->rightText(self::COL_GST, $y, (string) $row['gst'], 7, false, 0.22, 0.22, 0.22);
        }
        $ops[] = $this->rightText(self::COL_TAX, $y, (string) $row['tax'], 7);
        $ops[] = $this->rightText(self::COL_AMOUNT, $y, (string) $row['amount'], 8, true, self::NAVY_R, self::NAVY_G, self::NAVY_B);

        $serialY = $descY;
        if ($row['serialLines'] !== []) {
            $ops[] = $this->text(self::COL_PRODUCT, $serialY, 'Serial Numbers', 7, true, 0.38, 0.38, 0.38);
            $serialY -= 10;
            foreach ($row['serialLines'] as $line) {
                $ops[] = $this->text(self::COL_PRODUCT, $serialY, $line, 7, false, 0.32, 0.32, 0.32);
                $serialY -= 9;
            }
        }

        $ops[] = $this->hairline(self::MARGIN, $y - $this->rowHeight($row) + 4, self::CONTENT_RIGHT, 0.88);

        return implode('', $ops);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowHeight(array $row): float
    {
        if (($row['kind'] ?? 'item') === 'serials') {
            $label = trim((string) ($row['serialLabel'] ?? ''));

            return ($label !== '' ? 14 : 4) + (9 * count($row['serialLines'])) + 8;
        }

        $desc = max(1, count($row['descLines']));
        $height = 14 + (11 * $desc);
        if ($row['serialLines'] !== []) {
            $height += 12 + (9 * count($row['serialLines']));
        } else {
            $height += 6;
        }

        return $height;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: ?array<string, mixed>, 1: ?array<string, mixed>}
     */
    private function splitRow(array $row, float $available): array
    {
        if ($this->rowHeight($row) <= $available) {
            return [$row, null];
        }

        $serials = $row['serialLines'];
        if ($serials === []) {
            return $available >= 28 ? [$row, null] : [null, $row];
        }

        $minItem = $row;
        $minItem['serialLines'] = [];
        $used = $this->rowHeight($minItem) + 12;
        if ($used + 9 > $available && ($row['kind'] ?? 'item') === 'item') {
            return [null, $row];
        }

        $fit = 0;
        foreach ($serials as $serialLine) {
            if ($used + 9 > $available) {
                break;
            }
            $fit++;
            $used += 9;
            unset($serialLine);
        }
        if ($fit < 1 && ($row['kind'] ?? 'item') === 'serials') {
            $fit = 1;
        }
        if ($fit < 1) {
            return [null, $row];
        }

        $visible = $row;
        $visible['serialLines'] = array_slice($serials, 0, $fit);
        $rest = array_slice($serials, $fit);
        if ($rest === []) {
            return [$visible, null];
        }

        return [$visible, [
            'kind' => 'serials',
            'serialLabel' => '',
            'serialLines' => $rest,
            'descLines' => [],
            'lineNo' => '',
            'hsn' => '',
            'qty' => '',
            'uqc' => '',
            'rate' => '',
            'taxable' => '',
            'tax' => '',
            'gst' => '',
            'amount' => '',
        ]];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function rowsFitWithClosing(array $rows, float $closing, bool $firstPage, StatutoryInvoicePdfPayload $payload): bool
    {
        $height = 0.0;
        foreach ($rows as $row) {
            $height += $this->rowHeight($row);
        }

        $budget = $firstPage ? 400.0 : 560.0;
        if ($firstPage) {
            $budget -= $this->serialSummaryHeight($payload);
        }

        return $height + $closing <= $budget;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lineRows(StatutoryInvoicePdfPayload $payload): array
    {
        $rows = [];
        foreach (array_values($payload->lines) as $index => $line) {
            $rows[] = [
                'kind' => 'item',
                'lineNo' => (string) ($index + 1),
                'descLines' => $this->wrapWidth((string) $line['description'], self::PRODUCT_WIDTH, 8),
                'hsn' => $this->display((string) $line['hsnSac']),
                'qty' => (string) $line['qty'],
                'uqc' => $this->display(isset($line['uqc']) ? (string) $line['uqc'] : ''),
                'rate' => $this->money((string) ($line['unitPrice'] ?? $line['taxableValue'])),
                'taxable' => $this->money((string) ($line['taxableValue'] ?? $line['unitPrice'])),
                'tax' => $this->money((string) $line['taxTotal']),
                'gst' => $this->display((string) $line['gstPercentage']),
                'amount' => $this->money((string) $line['lineTotal']),
                'serialLines' => [],
            ];
        }

        return $rows;
    }

    private function closingHeight(StatutoryInvoicePdfPayload $payload): float
    {
        $pairs = $this->totalsPairs($payload);
        $words = $this->wrapWidth($this->amountInWords($payload->invoiceValue), 280, 8);
        $height = 28 + (11 * count($words)) + 16;
        $height += 12 * count($pairs);
        $height += 18;
        if ($this->hasPayment($payload)) {
            $height += 40;
        }
        $irnH = $payload->hasIssuedIrn() ? max(76.0, self::QR_SIZE + 18) : 12.0;
        $height += max($irnH, 58.0);

        return $height;
    }

    private function closingBlock(StatutoryInvoicePdfPayload $payload, float $y): string
    {
        $ops = [];
        $words = $this->wrapWidth($this->amountInWords($payload->invoiceValue), 268, 8);
        $wordH = 18.0 + (11.0 * count($words)) + 8.0;
        $pairs = $this->totalsPairs($payload);
        $pairH = 8.0 + (12.0 * (count($pairs) - 1)) + 20.0;
        $blockH = max($wordH, $pairH);
        $gap = 10.0;
        $leftW = 286.0;
        $rightX = self::MARGIN + $leftW + $gap;
        $rightW = self::CONTENT_RIGHT - $rightX;
        $bottom = $y - $blockH;

        $ops[] = $this->card(self::MARGIN, $bottom, $leftW, $blockH);
        $ops[] = $this->text(self::MARGIN + 8, $y - 12, 'Amount in words', 7, true, self::NAVY_R, self::NAVY_G, self::NAVY_B);
        $wordY = $y - 24;
        foreach ($words as $line) {
            $ops[] = $this->text(self::MARGIN + 8, $wordY, $line, 8, true, 0.16, 0.16, 0.16);
            $wordY -= 11;
        }

        $ops[] = $this->card($rightX, $bottom, $rightW, $blockH);
        $pairY = $y - 14;
        foreach ($pairs as [$label, $value, $bold]) {
            if ($bold) {
                $ops[] = $this->hairline($rightX + 6, $pairY + 8, self::CONTENT_RIGHT - 6, 0.45);
                $ops[] = $this->text($rightX + 8, $pairY, $label, 8, true, self::NAVY_R, self::NAVY_G, self::NAVY_B);
                $ops[] = $this->rightText(self::CONTENT_RIGHT - 8, $pairY, $value, 8, true, self::NAVY_R, self::NAVY_G, self::NAVY_B);
                $pairY -= 16;

                continue;
            }
            $ops[] = $this->text($rightX + 8, $pairY, $label, 7, false, 0.32, 0.32, 0.32);
            $ops[] = $this->rightText(self::CONTENT_RIGHT - 8, $pairY, $value, 8, true, 0.12, 0.12, 0.12);
            $pairY -= 12;
        }

        $y = $bottom - 10;

        if ($this->hasPayment($payload)) {
            $cols = $this->paymentColumns($payload);
            $payH = 34.0;
            $payBottom = $y - $payH;
            $ops[] = $this->card(self::MARGIN, $payBottom, self::CONTENT_RIGHT - self::MARGIN, $payH);
            $ops[] = $this->text(self::MARGIN + 8, $y - 11, 'Payment Details', 7, true, self::NAVY_R, self::NAVY_G, self::NAVY_B);
            $colW = (self::CONTENT_RIGHT - self::MARGIN - 16) / max(count($cols), 1);
            $colX = self::MARGIN + 8;
            foreach ($cols as [$label, $value]) {
                $ops[] = $this->text($colX, $y - 21, $label, 6, false, 0.42, 0.42, 0.42);
                $ops[] = $this->text($colX, $y - 31, $this->clip($value, $colW - 8, 8), 8, true, 0.14, 0.14, 0.14);
                $colX += $colW;
            }
            $y = $payBottom - 10;
        }

        $signX = 392.0;
        $closingTop = $y;
        if ($payload->hasIssuedIrn()) {
            $this->compactIrnVerificationBlock($ops, $payload, $closingTop, $signX - 12);
        } else {
            $ops[] = $this->text(self::MARGIN, $closingTop - 2, 'Whether tax is payable on reverse charge basis: No', 7, false, 0.32, 0.32, 0.32);
        }

        $ops[] = $this->text($signX, $closingTop, 'For '.$payload->sellerLegalName, 7, true, self::NAVY_R, self::NAVY_G, self::NAVY_B);
        $stampTop = $closingTop - 10;
        $ops[] = $this->stampMark($signX, $stampTop);
        $ops[] = $this->text($signX, $stampTop - self::STAMP_MAX_HEIGHT - 6, 'Authorized Signatory', 7, true, 0.22, 0.22, 0.22);

        return implode('', $ops);
    }

    /**
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    private function totalsPairs(StatutoryInvoicePdfPayload $payload): array
    {
        $pairs = [
            ['Total Taxable Amount', $this->money($payload->taxableValue), false],
        ];
        if ($payload->discount !== null && $this->numericAmount($payload->discount) !== null) {
            $pairs[] = ['Discount', $this->money($payload->discount), false];
        }

        $cgstMissing = $this->isMissingAmount($payload->cgst);
        $sgstMissing = $this->isMissingAmount($payload->sgst);
        $igstMissing = $this->isMissingAmount($payload->igst);
        $cgst = $this->numericAmount($payload->cgst) ?? 0.0;
        $sgst = $this->numericAmount($payload->sgst) ?? 0.0;
        $igst = $this->numericAmount($payload->igst) ?? 0.0;

        if ($cgstMissing && $sgstMissing && $igstMissing) {
            $pairs[] = ['CGST', '-', false];
            $pairs[] = ['SGST', '-', false];
            $pairs[] = ['IGST', '-', false];
        } else {
            $intra = (! $cgstMissing && $cgst > 0.004) || (! $sgstMissing && $sgst > 0.004);
            $inter = ! $igstMissing && $igst > 0.004;
            if ($intra) {
                $pairs[] = ['CGST', $this->money($payload->cgst), false];
                $pairs[] = ['SGST', $this->money($payload->sgst), false];
            } elseif ($cgstMissing && $sgstMissing && ! $inter) {
                $pairs[] = ['CGST', '-', false];
                $pairs[] = ['SGST', '-', false];
            }
            if ($inter) {
                $pairs[] = ['IGST', $this->money($payload->igst), false];
            } elseif ($igstMissing && ! $intra) {
                $pairs[] = ['IGST', '-', false];
            }
        }

        if ($payload->rounding !== null && $this->numericAmount($payload->rounding) !== null) {
            $pairs[] = ['Round Off', $this->money($payload->rounding), false];
        }

        $pairs[] = ['TOTAL INVOICE VALUE', $this->money($payload->invoiceValue), true];

        return $pairs;
    }

    private function hasPayment(StatutoryInvoicePdfPayload $payload): bool
    {
        return trim((string) ($payload->paymentMethod ?? '')) !== ''
            || trim((string) ($payload->paymentReference ?? '')) !== '';
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function paymentColumns(StatutoryInvoicePdfPayload $payload): array
    {
        $cols = [];
        $status = strtoupper(trim((string) ($payload->paymentStatus ?? '')));
        $method = trim((string) ($payload->paymentMethod ?? ''));
        if ($status === '') {
            $status = $method !== '' ? 'PAID' : '';
        }
        $unpaid = $status === 'UNPAID';
        if ($status !== '') {
            $cols[] = ['Payment Status', $unpaid ? 'UNPAID' : 'Paid'];
        }
        if ($method !== '') {
            $cols[] = ['Mode of Payment', $this->display($method)];
        }
        if (! $unpaid && trim((string) ($payload->paymentReference ?? '')) !== '') {
            $cols[] = ['Reference No.', $this->display($payload->paymentReference)];
        }
        $cols[] = ['Invoice Value', $this->money($payload->invoiceValue)];

        return $cols;
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
    private function firstPageSerials(StatutoryInvoicePdfPayload $payload): array
    {
        $all = $this->normalizedSerials($payload);
        if ($all === []) {
            return [];
        }

        if (count($all) <= self::FIRST_PAGE_SERIAL_LIMIT) {
            return $all;
        }

        return array_slice($all, 0, self::FIRST_PAGE_SERIAL_LIMIT);
    }

    /**
     * Serials after FIRST_PAGE_SERIAL_LIMIT. Empty when everything fits on page 1.
     *
     * @return list<string>
     */
    private function annexureSerials(StatutoryInvoicePdfPayload $payload): array
    {
        $all = $this->normalizedSerials($payload);
        if (count($all) <= self::FIRST_PAGE_SERIAL_LIMIT) {
            return [];
        }

        return array_values(array_slice($all, self::FIRST_PAGE_SERIAL_LIMIT));
    }

    private function serialSummaryHeight(StatutoryInvoicePdfPayload $payload): float
    {
        $first = $this->firstPageSerials($payload);
        if ($first === []) {
            return 0.0;
        }

        $rows = (int) ceil(count($first) / self::SERIAL_COLUMNS);
        $height = 16.0 + ($rows * self::SERIAL_ROW_HEIGHT) + 12.0;
        if ($this->annexureSerials($payload) !== []) {
            $height += 10.0;
        }

        return $height;
    }

    /**
     * @param  list<string>  $ops
     */
    private function serialSummaryBlock(array &$ops, StatutoryInvoicePdfPayload $payload, float $y): float
    {
        $first = $this->firstPageSerials($payload);
        if ($first === []) {
            return $y;
        }

        $height = $this->serialSummaryHeight($payload);
        $ops[] = $this->card(self::MARGIN, $y - $height + 12, self::CONTENT_RIGHT - self::MARGIN, $height - 4);
        $ops[] = $this->text(self::MARGIN + 8, $y, 'Serial Numbers', 7, true, self::NAVY_R, self::NAVY_G, self::NAVY_B);
        if ($this->annexureSerials($payload) !== []) {
            $ops[] = $this->text(self::MARGIN + 92, $y, '(More in Annexure A)', 7, false, 0.4, 0.4, 0.4);
        }
        $y -= 12;
        $y = $this->numberedSerialGrid($ops, $first, 1, $y);
        if ($this->annexureSerials($payload) !== []) {
            $ops[] = $this->text(self::MARGIN + 8, $y, '* More serial numbers in Annexure A', 7, false, 0.32, 0.32, 0.32);
            $y -= 11;
        }

        return $y - 10;
    }

    /**
     * @return list<string>
     */
    private function annexurePageStreams(StatutoryInvoicePdfPayload $payload): array
    {
        $remaining = $this->annexureSerials($payload);
        if ($remaining === []) {
            return [];
        }

        $total = count($this->normalizedSerials($payload));
        $startNumber = self::FIRST_PAGE_SERIAL_LIMIT + 1;
        $perPage = 80;
        $streams = [];
        $offset = 0;
        $first = true;
        while ($offset < count($remaining)) {
            $slice = array_slice($remaining, $offset, $perPage);
            $streams[] = $this->annexurePage($payload, $slice, $total, $first, $startNumber + $offset);
            $offset += count($slice);
            $first = false;
        }

        return $streams;
    }

    /**
     * @param  list<string>  $serials
     */
    private function annexurePage(StatutoryInvoicePdfPayload $payload, array $serials, int $total, bool $first, int $startNumber): string
    {
        $ops = [];
        $y = 808.0;
        $ops[] = $this->logoMark(self::MARGIN, $y);
        $title = $first ? 'ANNEXURE A' : 'ANNEXURE A (continued)';
        $ops[] = $this->text(self::MARGIN + 148, $y - 2, $title, 13, true, self::NAVY_R, self::NAVY_G, self::NAVY_B);
        $ops[] = $this->text(self::MARGIN + 148, $y - 16, 'Serial Numbers', 8, false, 0.38, 0.38, 0.38);
        $ops[] = $this->rightText(self::CONTENT_RIGHT, $y, $payload->invoiceNumber, 9, true, self::NAVY_R, self::NAVY_G, self::NAVY_B);
        $ops[] = $this->rightText(self::CONTENT_RIGHT, $y - 12, $this->invoiceDate($payload->issuedAt), 8, false, 0.35, 0.35, 0.35);
        $y -= 40;
        $ops[] = $this->accentLine(self::MARGIN, $y, self::CONTENT_RIGHT);
        $y -= 14;

        if ($first) {
            $metaHeight = 44.0;
            $metaBottom = $y - $metaHeight;
            $ops[] = $this->card(self::MARGIN, $metaBottom, self::CONTENT_RIGHT - self::MARGIN, $metaHeight);
            $order = $this->display($payload->orderId ?: $payload->sourceId);
            $metaY = $y - 14;
            foreach ([
                ['Invoice', $payload->invoiceNumber],
                ['Order / reference', $order],
                ['Total serials', (string) $total],
            ] as [$label, $value]) {
                $ops[] = $this->text(self::MARGIN + 10, $metaY, $label, 7, true, 0.4, 0.4, 0.4);
                $ops[] = $this->text(self::MARGIN + 118, $metaY, $this->clip((string) $value, 380, 8), 8, true, self::NAVY_R, self::NAVY_G, self::NAVY_B);
                $metaY -= 12;
            }
            $y = $metaBottom - 14;
        }

        $this->numberedSerialGrid($ops, $serials, $startNumber, $y);

        $ops[] = $this->text(self::MARGIN, self::CONTENT_FLOOR - 6, 'Annexure to tax invoice '.$payload->invoiceNumber, 7, false, 0.4, 0.4, 0.4);

        return implode('', $ops);
    }

    /**
     * @param  list<string>  $ops
     * @param  list<string>  $serials
     */
    private function numberedSerialGrid(array &$ops, array $serials, int $startNumber, float $y): float
    {
        $colWidth = (self::CONTENT_RIGHT - self::MARGIN - 16) / self::SERIAL_COLUMNS;
        $col = 0;
        $rowY = $y;
        foreach ($serials as $i => $serial) {
            if ($rowY < self::CONTENT_FLOOR + 8) {
                break;
            }
            $x = self::MARGIN + 8 + ($col * $colWidth);
            $label = ($startNumber + $i).'. '.$this->ascii($serial);
            $ops[] = $this->text($x, $rowY, $this->clip($label, $colWidth - 6, 7), 7, false, 0.15, 0.15, 0.15);
            $col++;
            if ($col >= self::SERIAL_COLUMNS) {
                $col = 0;
                $rowY -= self::SERIAL_ROW_HEIGHT;
            }
        }
        if ($col > 0) {
            $rowY -= self::SERIAL_ROW_HEIGHT;
        }

        return $rowY;
    }

    /**
     * @param  list<string>  $items
     * @return list<string>
     */
    private function wrapDelimited(array $items, float $maxWidth, int $size, string $sep = ', '): array
    {
        $lines = [];
        $current = '';
        foreach ($items as $item) {
            $token = $this->ascii($item);
            if ($token === '') {
                continue;
            }
            $candidate = $current === '' ? $token : $current.$sep.$token;
            if ($this->textWidth($candidate, $size) <= $maxWidth) {
                $current = $candidate;

                continue;
            }
            if ($current !== '') {
                $lines[] = $current;
            }
            if ($this->textWidth($token, $size) > $maxWidth) {
                $lines = array_merge($lines, $this->hardSplit($token, $maxWidth, $size));
                $current = '';

                continue;
            }
            $current = $token;
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
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

    private function clip(string $text, float $maxWidth, int $size): string
    {
        if ($this->textWidth($text, $size) <= $maxWidth) {
            return $text;
        }

        return $this->wrapWidth($text, $maxWidth, $size)[0];
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

    private function formatAckDate(string $ackDate): string
    {
        $trimmed = trim($ackDate);
        if ($trimmed === '') {
            return '-';
        }

        try {
            return (new \DateTimeImmutable($trimmed))->format('d M Y H:i');
        } catch (\Exception) {
            return $this->ascii($trimmed);
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

    private function isMissingAmount(string $value): bool
    {
        return $this->display($value) === '-';
    }

    private function numericAmount(string $value): ?float
    {
        $display = $this->display($value);
        if ($display === '-' || str_contains($display, '%')) {
            return null;
        }
        $raw = str_replace([',', 'Rs.'], '', $display);
        if (! is_numeric($raw)) {
            return null;
        }

        return (float) $raw;
    }

    private function amountInWords(string $value): string
    {
        $amount = $this->numericAmount($value);
        if ($amount === null) {
            return '-';
        }

        $rupees = (int) floor($amount + 0.0001);
        $paise = (int) round(($amount - $rupees) * 100);
        if ($paise === 100) {
            $rupees++;
            $paise = 0;
        }

        $words = $this->indianWords($rupees).' Rupees';
        if ($paise > 0) {
            $words .= ' and '.$this->indianWords($paise).' Paise';
        }

        return $words.' Only';
    }

    private function indianWords(int $number): string
    {
        if ($number === 0) {
            return 'Zero';
        }

        $parts = [];
        $crore = intdiv($number, 10000000);
        $number %= 10000000;
        $lakh = intdiv($number, 100000);
        $number %= 100000;
        $thousand = intdiv($number, 1000);
        $rest = $number % 1000;

        if ($crore > 0) {
            $parts[] = $this->belowThousand($crore).' Crore';
        }
        if ($lakh > 0) {
            $parts[] = $this->belowThousand($lakh).' Lakh';
        }
        if ($thousand > 0) {
            $parts[] = $this->belowThousand($thousand).' Thousand';
        }
        if ($rest > 0) {
            $parts[] = $this->belowThousand($rest);
        }

        return implode(' ', $parts);
    }

    private function belowThousand(int $number): string
    {
        $hundreds = intdiv($number, 100);
        $rest = $number % 100;
        $parts = [];
        if ($hundreds > 0) {
            $parts[] = $this->belowHundred($hundreds).' Hundred';
        }
        if ($rest > 0) {
            $parts[] = $this->belowHundred($rest);
        }

        return implode(' ', $parts);
    }

    private function belowHundred(int $number): string
    {
        $ones = [
            0 => '', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five',
            6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine', 10 => 'Ten',
            11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen',
            15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen', 19 => 'Nineteen',
        ];
        $tens = [
            2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty', 5 => 'Fifty',
            6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety',
        ];

        if ($number < 20) {
            return $ones[$number];
        }

        $ten = $tens[intdiv($number, 10)];
        $one = $ones[$number % 10];

        return $one === '' ? $ten : $ten.'-'.$one;
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

    private function hairline(float $x1, float $y, float $x2, float $gray = 0.72): string
    {
        return sprintf(
            "q\n0.3 w\n%.3F %.3F %.3F RG\n%.2F %.2F m\n%.2F %.2F l\nS\nQ\n",
            $gray,
            $gray,
            $gray,
            $x1,
            $y,
            $x2,
            $y,
        );
    }

    private function accentLine(float $x1, float $y, float $x2): string
    {
        return sprintf("q\n1.15 w\n0.890 0.110 0.141 RG\n%.2F %.2F m\n%.2F %.2F l\nS\nQ\n", $x1, $y, $x2, $y);
    }

    private function card(float $x, float $y, float $w, float $h): string
    {
        return $this->strokeRect($x, $y, $w, $h, 0.55);
    }

    private function pageFooter(int $page, int $totalPages): string
    {
        $ops = [];
        $ops[] = $this->hairline(self::MARGIN, self::CONTENT_FLOOR - 8, self::CONTENT_RIGHT, 0.82);
        $ops[] = $this->text(self::MARGIN, self::FOOTER_Y, 'This is a computer-generated tax invoice.', 7, false, 0.42, 0.42, 0.42);
        $ops[] = $this->text(214, self::FOOTER_Y, 'Thank you for your business.', 7, false, 0.42, 0.42, 0.42);
        $ops[] = $this->rightText(
            self::CONTENT_RIGHT,
            self::FOOTER_Y,
            'Page '.$page.' of '.$totalPages,
            7,
            false,
            0.38,
            0.38,
            0.38,
        );

        return implode('', $ops);
    }

    private function sellerCin(): string
    {
        $cin = config('branding.cin');
        if (! is_string($cin)) {
            return '';
        }

        $cin = strtoupper(trim($cin));

        return $cin !== '' ? $cin : '';
    }

    private function strokeRect(float $x, float $y, float $w, float $h, float $gray = 0.78): string
    {
        return sprintf(
            "q\n0.5 w\n%.3F %.3F %.3F RG\n%.2F %.2F %.2F %.2F re\nS\nQ\n",
            $gray,
            $gray,
            $gray,
            $x,
            $y,
            $w,
            $h,
        );
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
            '·' => '|',
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

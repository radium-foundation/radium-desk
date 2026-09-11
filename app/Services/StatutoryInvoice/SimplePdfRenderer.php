<?php

namespace App\Services\StatutoryInvoice;

use App\Services\StatutoryInvoice\Data\StatutoryInvoicePdfPayload;

class SimplePdfRenderer
{
    private const PAGE_WIDTH = 595.0;

    private const PAGE_HEIGHT = 842.0;

    private const MARGIN = 42.0;

    private const CONTENT_RIGHT = 553.0;

    private const FOOTER_Y = 32.0;

    private const COL_PRODUCT = 42.0;

    private const COL_HSN = 246.0;

    private const COL_QTY = 306.0;

    private const COL_UQC = 334.0;

    private const COL_RATE = 416.0;

    private const COL_TAX = 482.0;

    private const COL_AMOUNT = 553.0;

    private const PRODUCT_WIDTH = 200.0;

    private const LOGO_MAX_WIDTH = 118.0;

    private const LOGO_MAX_HEIGHT = 32.0;

    private const STAMP_MAX_WIDTH = 84.0;

    private const STAMP_MAX_HEIGHT = 48.0;

    private const QR_SIZE = 64.0;

    private const QR_GAP = 10.0;

    public const FIRST_PAGE_SERIAL_LIMIT = 8;

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
        if ($totalPages > 1) {
            foreach ($contents as $index => $stream) {
                $contents[$index] = $stream.$this->text(
                    268,
                    self::FOOTER_Y,
                    'Page '.($index + 1).' of '.$totalPages,
                    8,
                    false,
                    0.38,
                    0.38,
                    0.38,
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

        $ops[] = $this->tableHeader($y);
        $y -= 16;

        $drawn = 0;
        $serialReserve = $first ? $this->serialSummaryHeight($payload) : 0.0;
        $floor = $includeClosing ? self::FOOTER_Y + $closing + 8 : self::FOOTER_Y + 18;
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
                    [$visible, $leftover] = $this->splitRow($row, $y - self::FOOTER_Y - 18);
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
        $y = 806.0;
        $ops[] = $this->logoMark(self::MARGIN, $y);

        $ops[] = $this->rightText(self::CONTENT_RIGHT, $y + 2, 'TAX INVOICE', 15, true);
        $accentWidth = 88.0;
        $ops[] = $this->accentLine(self::CONTENT_RIGHT - $accentWidth, $y - 6, self::CONTENT_RIGHT);

        $meta = [
            'Invoice No.  '.$payload->invoiceNumber,
            'Date  '.$this->invoiceDate($payload->issuedAt),
        ];
        $orderId = $this->display($payload->orderId ?: $payload->sourceId);
        if ($orderId !== '-') {
            $meta[] = 'Order ID  '.$orderId;
        }

        $metaY = $y - 16;
        foreach ($meta as $line) {
            $ops[] = $this->rightText(self::CONTENT_RIGHT, $metaY, $line, 8, false, 0.2, 0.2, 0.2);
            $metaY -= 11;
        }

        $y = min($y - self::LOGO_MAX_HEIGHT, $metaY) - 10;
        $ops[] = $this->hairline(self::MARGIN, $y, self::CONTENT_RIGHT);
        $y -= 14;

        $ops[] = $this->text(self::MARGIN, $y, $payload->sellerLegalName, 9, true);
        $y -= 12;
        foreach (array_slice($this->wrapWidth($this->display($payload->sellerAddress), 360, 8), 0, 2) as $addressLine) {
            $ops[] = $this->text(self::MARGIN, $y, $addressLine, 8, false, 0.18, 0.18, 0.18);
            $y -= 10;
        }
        $contact = implode('  |  ', array_filter([
            $payload->sellerEmail !== null && trim($payload->sellerEmail) !== '' ? $this->display($payload->sellerEmail) : null,
            $payload->sellerPhone !== null && trim($payload->sellerPhone) !== '' ? $this->display($payload->sellerPhone) : null,
            'GSTIN '.$this->display($payload->sellerGstin),
        ]));
        $ops[] = $this->text(self::MARGIN, $y, $this->clip($contact, 500, 8), 8, false, 0.18, 0.18, 0.18);
        $y -= 12;
        $ops[] = $this->hairline(self::MARGIN, $y, self::CONTENT_RIGHT);

        return $y - 14;
    }

    /**
     * @param  list<string>  $ops
     */
    private function continuationHeader(array &$ops, StatutoryInvoicePdfPayload $payload): float
    {
        $y = 806.0;
        $ops[] = $this->text(self::MARGIN, $y, 'TAX INVOICE', 9, true);
        $ops[] = $this->rightText(self::CONTENT_RIGHT, $y, $payload->invoiceNumber.'  (continued)', 8);
        $y -= 12;
        $ops[] = $this->hairline(self::MARGIN, $y, self::CONTENT_RIGHT);

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
    private function compactIrnVerificationBlock(array &$ops, StatutoryInvoicePdfPayload $payload, float $topY): float
    {
        if (! $payload->hasIssuedIrn()) {
            return $topY;
        }

        $matrix = $payload->hasIssuedSignedQr()
            ? (new EInvoiceSignedQrMatrix)->matrix($payload->signedQr)
            : null;
        $hasQr = $matrix !== null;
        $textRight = $hasQr
            ? self::CONTENT_RIGHT - self::QR_SIZE - self::QR_GAP - 4
            : self::CONTENT_RIGHT - 8;
        $irnLines = $this->wrapWidth((string) $payload->irn, $textRight - self::MARGIN - 10, 7);
        $ack = trim(implode('    ', array_filter([
            $payload->ackNo !== null && $payload->ackNo !== '' ? 'Ack No: '.$payload->ackNo : null,
            $payload->ackDate !== null && $payload->ackDate !== '' ? 'Date: '.$this->formatAckDate($payload->ackDate) : null,
        ])));
        $textHeight = 10 + 9 + (9 * count($irnLines)) + ($ack !== '' ? 9 : 0);
        $blockHeight = max($textHeight + 8, $hasQr ? self::QR_SIZE + 10 : $textHeight + 8);
        $bottomY = $topY - $blockHeight;

        $ops[] = $this->strokeRect(self::MARGIN, $bottomY, self::CONTENT_RIGHT - self::MARGIN, $blockHeight, 0.84);

        $ty = $topY - 10;
        $ops[] = $this->text(self::MARGIN + 6, $ty, 'e-Invoice Verification', 7, true, 0.35, 0.35, 0.35);
        $ty -= 10;
        $ops[] = $this->text(self::MARGIN + 6, $ty, 'IRN', 6, true, 0.45, 0.45, 0.45);
        $ty -= 9;
        foreach ($irnLines as $irnLine) {
            $ops[] = $this->text(self::MARGIN + 6, $ty, $irnLine, 7);
            $ty -= 9;
        }
        if ($ack !== '') {
            $ops[] = $this->text(self::MARGIN + 6, $ty, $ack, 7, false, 0.2, 0.2, 0.2);
        }
        if ($hasQr) {
            $ops[] = $this->signedQrImage($matrix, self::CONTENT_RIGHT - self::QR_SIZE - 4, $topY - 4, self::QR_SIZE);
        } elseif ($payload->hasIssuedSignedQr()) {
            $ops[] = $this->text(self::MARGIN + 6, $ty - 9, 'Signed QR issued with this IRN.', 7, false, 0.28, 0.28, 0.28);
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
        $left = self::MARGIN;
        $right = 310.0;
        $leftWidth = 250.0;
        $rightWidth = 243.0;

        $buyerGstin = $payload->buyerGstin !== null && trim($payload->buyerGstin) !== ''
            ? $payload->buyerGstin
            : 'Unregistered';
        $leftLines = $this->buyerLines($payload, $buyerGstin);
        $rightLines = $this->rightPartyLines($payload);
        $rows = max(count($leftLines), count($rightLines));

        $ops[] = $this->text($left, $y, 'BILL TO', 7, true, 0.38, 0.38, 0.38);
        $heading = $this->rightPartyHeading($payload);
        if ($rightLines !== [] && $heading !== '') {
            $ops[] = $this->text($right, $y, $heading, 7, true, 0.38, 0.38, 0.38);
        }
        $y -= 13;

        for ($i = 0; $i < $rows; $i++) {
            if (isset($leftLines[$i])) {
                $bold = $i === 0;
                $ops[] = $this->text($left, $y, $this->clip($leftLines[$i], $leftWidth, $bold ? 9 : 8), $bold ? 9 : 8, $bold);
            }
            if (isset($rightLines[$i])) {
                $ops[] = $this->text($right, $y, $this->clip($rightLines[$i], $rightWidth, 8), 8);
            }
            $y -= 11;
        }

        $y -= 6;
        $ops[] = $this->hairline(self::MARGIN, $y, self::CONTENT_RIGHT);

        return $y - 14;
    }

    /**
     * @return list<string>
     */
    private function buyerLines(StatutoryInvoicePdfPayload $payload, string $buyerGstin): array
    {
        $lines = $this->wrapWidth($this->display($payload->buyerName), 250, 9);
        $addressDisplay = $this->display($payload->billingAddress);
        if ($addressDisplay !== '-') {
            foreach ($this->wrapWidth($addressDisplay, 250, 8) as $line) {
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

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function rightPartyLines(StatutoryInvoicePdfPayload $payload): array
    {
        $lines = [];
        if ($payload->hasDistinctShippingAddress()) {
            foreach ($this->wrapWidth($this->display($payload->shippingAddress), 240, 8) as $line) {
                $lines[] = $line;
            }
            if ($payload->placeOfSupply !== '') {
                $lines[] = 'Place of supply '.$this->display($payload->placeOfSupply);
            }

            return $lines;
        }

        if ($payload->placeOfSupply !== '') {
            $lines[] = 'Place of supply '.$this->display($payload->placeOfSupply);
        }

        return $lines;
    }

    private function rightPartyHeading(StatutoryInvoicePdfPayload $payload): string
    {
        return $payload->hasDistinctShippingAddress() ? 'SHIP TO' : '';
    }

    private function tableHeader(float $y): string
    {
        $ops = [];
        $ops[] = $this->text(self::COL_PRODUCT, $y, 'Product / Service', 7, true, 0.35, 0.35, 0.35);
        $ops[] = $this->text(self::COL_HSN, $y, 'HSN/SAC', 7, true, 0.35, 0.35, 0.35);
        $ops[] = $this->rightText(self::COL_QTY, $y, 'Qty', 7, true, 0.35, 0.35, 0.35);
        $ops[] = $this->text(self::COL_UQC, $y, 'UQC', 7, true, 0.35, 0.35, 0.35);
        $ops[] = $this->rightText(self::COL_RATE, $y, 'Unit Price', 7, true, 0.35, 0.35, 0.35);
        $ops[] = $this->rightText(self::COL_TAX, $y, 'Tax', 7, true, 0.35, 0.35, 0.35);
        $ops[] = $this->rightText(self::COL_AMOUNT, $y, 'Amount', 7, true, 0.35, 0.35, 0.35);
        $ops[] = $this->hairline(self::MARGIN, $y - 6, self::CONTENT_RIGHT);

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
            $ops[] = $this->text(self::COL_PRODUCT, $descY, $line, $index === 0 ? 9 : 8, $index === 0);
            $descY -= $index === 0 ? 11 : 10;
        }
        $ops[] = $this->text(self::COL_HSN, $y, (string) $row['hsn'], 8, false, 0.22, 0.22, 0.22);
        $ops[] = $this->rightText(self::COL_QTY, $y, (string) $row['qty'], 8);
        $ops[] = $this->text(self::COL_UQC, $y, (string) $row['uqc'], 8, false, 0.22, 0.22, 0.22);
        $ops[] = $this->rightText(self::COL_RATE, $y, (string) $row['rate'], 8);
        $ops[] = $this->rightText(self::COL_TAX, $y, (string) $row['tax'], 8);
        $ops[] = $this->rightText(self::COL_AMOUNT, $y, (string) $row['amount'], 8, true);
        if (($row['gst'] ?? '') !== '' && ($row['gst'] ?? '') !== '-') {
            $ops[] = $this->rightText(self::COL_TAX, $y - 10, (string) $row['gst'], 7, false, 0.38, 0.38, 0.38);
        }

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
            'hsn' => '',
            'qty' => '',
            'uqc' => '',
            'rate' => '',
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

        $budget = $firstPage ? 420.0 : 560.0;
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
        foreach (array_values($payload->lines) as $line) {
            $rows[] = [
                'kind' => 'item',
                'descLines' => $this->wrapWidth((string) $line['description'], self::PRODUCT_WIDTH, 9),
                'hsn' => $this->display((string) $line['hsnSac']),
                'qty' => (string) $line['qty'],
                'uqc' => $this->display(isset($line['uqc']) ? (string) $line['uqc'] : ''),
                'rate' => $this->money((string) ($line['unitPrice'] ?? $line['taxableValue'])),
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
        $words = $this->wrapWidth($this->amountInWords($payload->invoiceValue), 320, 9);
        $height = 18 + (11 * count($words)) + 10;
        $height += 12 * count($pairs);
        $height += 18;
        if ($this->hasPayment($payload)) {
            $height += 44;
        }
        if ($payload->hasIssuedIrn()) {
            $height += max(72.0, self::QR_SIZE + 24);
        }
        $height += 40;
        $height += 58;

        return $height;
    }

    private function closingBlock(StatutoryInvoicePdfPayload $payload, float $y): string
    {
        $ops = [];
        $wordY = $y;
        $ops[] = $this->text(self::MARGIN, $wordY, 'Amount in words', 7, true, 0.38, 0.38, 0.38);
        $wordY -= 12;
        foreach ($this->wrapWidth($this->amountInWords($payload->invoiceValue), 300, 9) as $line) {
            $ops[] = $this->text(self::MARGIN, $wordY, $line, 9, true);
            $wordY -= 12;
        }

        $pairs = $this->totalsPairs($payload);
        $pairY = $y;
        foreach ($pairs as [$label, $value, $bold]) {
            if ($bold) {
                $ops[] = $this->hairline(350, $pairY + 8, self::CONTENT_RIGHT);
                $pairY -= 4;
            }
            $ops[] = $this->text(350, $pairY, $label, $bold ? 10 : 8, $bold);
            $ops[] = $this->rightText(self::CONTENT_RIGHT, $pairY, $value, $bold ? 10 : 8, $bold);
            $pairY -= $bold ? 16 : 12;
        }

        $y = min($wordY, $pairY) - 8;

        if ($this->hasPayment($payload)) {
            $ops[] = $this->hairline(self::MARGIN, $y, self::CONTENT_RIGHT);
            $y -= 14;
            $ops[] = $this->text(self::MARGIN, $y, 'Payment', 7, true, 0.38, 0.38, 0.38);
            $y -= 12;
            foreach ($this->paymentLines($payload) as $line) {
                $ops[] = $this->text(self::MARGIN, $y, $line, 8, false, 0.22, 0.22, 0.22);
                $y -= 10;
            }
            $y -= 6;
        }

        if ($payload->hasIssuedIrn()) {
            $y = $this->compactIrnVerificationBlock($ops, $payload, $y);
        }

        $ops[] = $this->hairline(self::MARGIN, $y, self::CONTENT_RIGHT);
        $y -= 14;
        $noteY = $y;
        foreach ($this->statutoryNotes() as $note) {
            $ops[] = $this->text(self::MARGIN, $noteY, $note, 7, false, 0.32, 0.32, 0.32);
            $noteY -= 10;
        }

        $signX = 368.0;
        $ops[] = $this->text($signX, $y, 'For '.$payload->sellerLegalName, 7, false, 0.28, 0.28, 0.28);
        $stampTop = $y - 10;
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
            ['Taxable Amount', $this->money($payload->taxableValue), false],
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
     * @return list<string>
     */
    private function paymentLines(StatutoryInvoicePdfPayload $payload): array
    {
        $lines = [];
        if (trim((string) ($payload->paymentReference ?? '')) !== '') {
            $lines[] = 'Reference  '.$this->display($payload->paymentReference);
        }
        $date = $this->invoiceDate($payload->issuedAt);
        if ($date !== '-') {
            $lines[] = 'Date  '.$date;
        }
        $lines[] = 'Invoice value  '.$this->money($payload->invoiceValue);
        if (trim((string) ($payload->paymentMethod ?? '')) !== '') {
            $lines[] = 'Mode of payment  '.$this->display($payload->paymentMethod);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function statutoryNotes(): array
    {
        return [
            'Whether tax is payable on reverse charge basis: No',
            'This is a computer-generated tax invoice.',
            'Thank you for your business.',
        ];
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
     * Complete unique serial list when page 1 cannot show every serial.
     * Empty when everything fits on page 1 (no annexure).
     *
     * @return list<string>
     */
    private function annexureSerials(StatutoryInvoicePdfPayload $payload): array
    {
        $all = $this->normalizedSerials($payload);
        if (count($all) <= self::FIRST_PAGE_SERIAL_LIMIT) {
            return [];
        }

        return $all;
    }

    private function serialSummaryHeight(StatutoryInvoicePdfPayload $payload): float
    {
        $first = $this->firstPageSerials($payload);
        if ($first === []) {
            return 0.0;
        }

        $lines = $this->wrapDelimited($first, self::CONTENT_RIGHT - self::MARGIN, 8);
        $height = 12.0 + (11.0 * count($lines)) + 16.0;
        if ($this->annexureSerials($payload) !== []) {
            $height += 11.0;
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

        $ops[] = $this->text(self::MARGIN, $y, 'Serial Numbers', 7, true, 0.38, 0.38, 0.38);
        $y -= 12;
        foreach ($this->wrapDelimited($first, self::CONTENT_RIGHT - self::MARGIN, 8) as $line) {
            $ops[] = $this->text(self::MARGIN, $y, $line, 8, false, 0.22, 0.22, 0.22);
            $y -= 11;
        }
        if ($this->annexureSerials($payload) !== []) {
            $ops[] = $this->text(self::MARGIN, $y, '* More serial numbers in Annexure A', 7, false, 0.32, 0.32, 0.32);
            $y -= 11;
        }
        $y -= 4;
        $ops[] = $this->hairline(self::MARGIN, $y, self::CONTENT_RIGHT);

        return $y - 12;
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
        $perPage = 60;
        $streams = [];
        $offset = 0;
        $first = true;
        while ($offset < count($remaining)) {
            $slice = array_slice($remaining, $offset, $perPage);
            $streams[] = $this->annexurePage($payload, $slice, $total, $first);
            $offset += count($slice);
            $first = false;
        }

        return $streams;
    }

    /**
     * @param  list<string>  $serials
     */
    private function annexurePage(StatutoryInvoicePdfPayload $payload, array $serials, int $total, bool $first): string
    {
        $ops = [];
        $y = 806.0;
        $ops[] = $this->logoMark(self::MARGIN, $y);
        $title = $first ? 'ANNEXURE A' : 'ANNEXURE A (continued)';
        $ops[] = $this->text(self::MARGIN + 124, $y - 2, $title, 12, true);
        $ops[] = $this->text(self::MARGIN + 124, $y - 16, 'Serial Numbers', 8, false, 0.35, 0.35, 0.35);
        $ops[] = $this->rightText(self::CONTENT_RIGHT, $y, $payload->invoiceNumber, 8, true);
        $ops[] = $this->rightText(self::CONTENT_RIGHT, $y - 12, $this->invoiceDate($payload->issuedAt), 8, false, 0.35, 0.35, 0.35);
        $y -= 34;
        $ops[] = $this->accentLine(self::MARGIN, $y, self::CONTENT_RIGHT - 168);
        $y -= 14;

        if ($first) {
            $metaTop = $y;
            $metaHeight = 40.0;
            $metaBottom = $metaTop - $metaHeight;
            $ops[] = $this->strokeRect(self::MARGIN, $metaBottom, self::CONTENT_RIGHT - self::MARGIN, $metaHeight, 0.86);
            $order = $this->display($payload->orderId ?: $payload->sourceId);
            $metaY = $metaTop - 12;
            foreach ([
                ['Invoice', $payload->invoiceNumber],
                ['Order / reference', $order],
                ['Total serials', (string) $total],
            ] as [$label, $value]) {
                $ops[] = $this->text(self::MARGIN + 8, $metaY, $label, 7, true, 0.4, 0.4, 0.4);
                $ops[] = $this->text(self::MARGIN + 108, $metaY, $this->clip((string) $value, 360, 8), 8);
                $metaY -= 12;
            }
            $y = $metaBottom - 12;
        }

        $colWidth = (self::CONTENT_RIGHT - self::MARGIN - 12) / 3;
        $col = 0;
        $rowY = $y;
        foreach ($serials as $serial) {
            if ($rowY < self::FOOTER_Y + 24) {
                break;
            }
            $x = self::MARGIN + 6 + ($col * $colWidth);
            $ops[] = $this->text($x, $rowY, $serial, 8, false, 0.15, 0.15, 0.15);
            $col++;
            if ($col >= 3) {
                $col = 0;
                $rowY -= 12;
            }
        }

        $ops[] = $this->hairline(self::MARGIN, self::FOOTER_Y + 14, self::CONTENT_RIGHT);
        $ops[] = $this->text(self::MARGIN, self::FOOTER_Y + 4, 'Annexure to tax invoice '.$payload->invoiceNumber, 7, false, 0.4, 0.4, 0.4);

        return implode('', $ops);
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

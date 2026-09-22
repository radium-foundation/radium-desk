<?php

namespace App\Support\Finance;

use App\Reports\CaMonthly\CaMonthlyReportDefinition;
use App\Reports\CaMonthly\CaMonthlyReportInvoiceExportRow;
use App\Reports\CaMonthly\CaMonthlyReportWorkbookMeta;
use ZipArchive;

/**
 * Streams worksheet XML to a temporary file and packages a valid XLSX without
 * holding the full workbook in PHP memory.
 */
final class CaMonthlyReportXlsxStreamWriter
{
    private ?string $tmpdir = null;

    private ?string $sheetPath = null;

    /** @var resource|null */
    private $sheetHandle = null;

    private int $currentRowNumber = 0;

    private int $parentRowCount = 0;

    private float $taxableTotal = 0.0;

    private float $shippingTotal = 0.0;

    private float $igstTotal = 0.0;

    private float $cgstTotal = 0.0;

    private float $sgstTotal = 0.0;

    private float $invoiceGrandTotal = 0.0;

    private string $outputPath = '';

    /**
     * @param  list<string>  $headers
     */
    public function open(string $outputPath, array $headers, ?CaMonthlyReportWorkbookMeta $meta = null): void
    {
        $this->tmpdir = sys_get_temp_dir().'/ca-xlsx-'.uniqid('', true);
        if (! mkdir($this->tmpdir) && ! is_dir($this->tmpdir)) {
            throw new \RuntimeException('Could not create temporary XLSX workspace.');
        }

        $this->sheetPath = $this->tmpdir.'/sheet1.xml';
        $handle = fopen($this->sheetPath, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Could not open temporary worksheet file.');
        }

        $this->sheetHandle = $handle;
        $this->currentRowNumber = 0;
        $this->parentRowCount = 0;
        $this->taxableTotal = 0.0;
        $this->shippingTotal = 0.0;
        $this->igstTotal = 0.0;
        $this->cgstTotal = 0.0;
        $this->sgstTotal = 0.0;
        $this->invoiceGrandTotal = 0.0;
        $this->outputPath = $outputPath;

        fwrite($handle, '<?xml version="1.0" encoding="UTF-8"?>');
        fwrite($handle, '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">');
        fwrite($handle, '<sheetPr><outlinePr summaryBelow="0"/></sheetPr>');
        fwrite($handle, '<sheetViews><sheetView workbookViewId="0"><pane ySplit="'.CaMonthlyReportDefinition::HEADER_ROW.'" topLeftCell="A'.CaMonthlyReportDefinition::DATA_START_ROW.'" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>');
        fwrite($handle, '<sheetFormatPr defaultRowHeight="15"/>');
        fwrite($handle, '<cols>');
        foreach ($headers as $index => $_header) {
            $width = match ($index) {
                5 => 28,
                10 => 18,
                17 => 14,
                default => 14,
            };
            fwrite($handle, '<col min="'.($index + 1).'" max="'.($index + 1).'" width="'.$width.'" customWidth="1"/>');
        }
        fwrite($handle, '</cols>');
        fwrite($handle, '<sheetData>');

        $this->writeTitleRows($handle, $meta);
        $this->writeRowXml($handle, CaMonthlyReportDefinition::HEADER_ROW, $headers, 0, false);
        $this->currentRowNumber = CaMonthlyReportDefinition::HEADER_ROW;
    }

    public function appendInvoiceGroup(CaMonthlyReportInvoiceExportRow $row): void
    {
        if ($this->sheetHandle === null) {
            throw new \RuntimeException('XLSX stream writer is not open.');
        }

        $this->currentRowNumber++;
        $this->writeRowXml($this->sheetHandle, $this->currentRowNumber, $row->parentCells, 0, false);

        $this->parentRowCount++;
        $this->taxableTotal += $row->taxableAmount;
        $this->shippingTotal += $row->shippingAmount;
        $this->igstTotal += $row->igst;
        $this->cgstTotal += $row->cgst;
        $this->sgstTotal += $row->sgst;
        $this->invoiceGrandTotal += $row->invoiceTotal;

        if (! $row->expandable) {
            return;
        }

        foreach ($row->detailRows as $detail) {
            $this->currentRowNumber++;
            $this->writeRowXml(
                $this->sheetHandle,
                $this->currentRowNumber,
                $this->padDetailToParentWidth($detail),
                1,
                true,
            );
        }
    }

    /**
     * @param  list<string>  $cells
     */
    public function appendRow(array $cells): void
    {
        if ($this->sheetHandle === null) {
            throw new \RuntimeException('XLSX stream writer is not open.');
        }

        $this->currentRowNumber++;
        $this->writeRowXml($this->sheetHandle, $this->currentRowNumber, $cells, 0, false);
    }

    public function close(): void
    {
        if ($this->sheetHandle === null || $this->tmpdir === null || $this->sheetPath === null) {
            throw new \RuntimeException('XLSX stream writer is not open.');
        }

        if ($this->parentRowCount > 0) {
            $this->currentRowNumber++;
            $this->writeRowXml($this->sheetHandle, $this->currentRowNumber, array_fill(0, count(CaMonthlyReportDefinition::HEADERS), ''), 0, false);
            $this->currentRowNumber++;
            $this->writeRowXml($this->sheetHandle, $this->currentRowNumber, $this->summaryLabelRow(), 0, false);
            $this->currentRowNumber++;
            $this->writeRowXml($this->sheetHandle, $this->currentRowNumber, $this->summaryTotalsRow(), 0, false);
        }

        fwrite($this->sheetHandle, '</sheetData>');

        $lastColumn = $this->columnLetter(count(CaMonthlyReportDefinition::HEADERS) - 1);
        fwrite(
            $this->sheetHandle,
            '<autoFilter ref="A'.CaMonthlyReportDefinition::HEADER_ROW.':'.$lastColumn.CaMonthlyReportDefinition::HEADER_ROW.'"/>',
        );
        fwrite($this->sheetHandle, '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>');
        fwrite($this->sheetHandle, '<printOptions horizontalCentered="1"/>');
        fwrite($this->sheetHandle, '</worksheet>');
        fclose($this->sheetHandle);
        $this->sheetHandle = null;

        $zip = new ZipArchive;
        if ($zip->open($this->outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not write CA monthly report workbook.');
        }

        $zip->addFromString('[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>
XML);
        $zip->addFromString('_rels/.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML);
        $zip->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>
XML);
        $zip->addFromString('xl/workbook.xml', sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="%s" sheetId="1" r:id="rId1"/></sheets></workbook>',
            htmlspecialchars(CaMonthlyReportDefinition::SHEET_NAME, ENT_XML1),
        ));
        $zip->addFile($this->sheetPath, 'xl/worksheets/sheet1.xml');
        $zip->close();

        $this->removeTempdir();
    }

    public function abort(): void
    {
        if (is_resource($this->sheetHandle)) {
            fclose($this->sheetHandle);
            $this->sheetHandle = null;
        }

        $this->removeTempdir();
    }

    public function parentRowCount(): int
    {
        return $this->parentRowCount;
    }

    /**
     * @param  resource  $handle
     */
    private function writeTitleRows($handle, ?CaMonthlyReportWorkbookMeta $meta): void
    {
        $title = array_fill(0, count(CaMonthlyReportDefinition::HEADERS), '');
        $title[0] = CaMonthlyReportDefinition::DISPLAY_NAME;
        $this->writeRowXml($handle, CaMonthlyReportDefinition::TITLE_ROW, $title, 0, false);

        $period = array_fill(0, count(CaMonthlyReportDefinition::HEADERS), '');
        if ($meta !== null) {
            $period[0] = sprintf(
                'Reporting period: %s to %s | Generated: %s',
                $meta->periodFrom,
                $meta->periodTo,
                $meta->generatedAt,
            );
        }
        $this->writeRowXml($handle, CaMonthlyReportDefinition::PERIOD_ROW, $period, 0, false);
    }

    /**
     * @return list<string>
     */
    private function summaryLabelRow(): array
    {
        $row = array_fill(0, count(CaMonthlyReportDefinition::HEADERS), '');
        $row[10] = 'Summary';
        $row[11] = 'Taxable total';
        $row[12] = 'Shipping total';
        $row[13] = 'IGST total';
        $row[14] = 'CGST total';
        $row[15] = 'SGST total';
        $row[17] = 'Invoice grand total';

        return $row;
    }

    /**
     * @return list<string>
     */
    private function summaryTotalsRow(): array
    {
        $row = array_fill(0, count(CaMonthlyReportDefinition::HEADERS), '');
        $row[10] = (string) $this->parentRowCount.' invoices';
        $row[11] = $this->money($this->taxableTotal);
        $row[12] = $this->shippingTotal !== 0.0 ? $this->money($this->shippingTotal) : '';
        $row[13] = $this->igstTotal !== 0.0 ? $this->money($this->igstTotal) : '';
        $row[14] = $this->cgstTotal !== 0.0 ? $this->money($this->cgstTotal) : '';
        $row[15] = $this->sgstTotal !== 0.0 ? $this->money($this->sgstTotal) : '';
        $row[17] = $this->money($this->invoiceGrandTotal);

        return $row;
    }

    /**
     * @param  list<string>  $detail
     * @return list<string>
     */
    private function padDetailToParentWidth(array $detail): array
    {
        $product = $detail[0] ?? '';
        $qty = $detail[1] ?? '';
        $label = $product;
        if ($qty !== '' && $qty !== '1') {
            $label .= ' (Qty: '.$qty.')';
        }

        return [
            '',
            '',
            '',
            '',
            '',
            '  » '.$label,
            '',
            '',
            '',
            '',
            $detail[2] ?? '',
            $detail[3] ?? '',
            $detail[4] ?? '',
            $detail[5] ?? '',
            $detail[6] ?? '',
            $detail[7] ?? '',
            '',
            $detail[8] ?? '',
            '',
            '',
            '',
        ];
    }

    /**
     * @param  resource  $handle
     * @param  list<string>  $row
     */
    private function writeRowXml($handle, int $rowNumber, array $row, int $outlineLevel, bool $hidden): void
    {
        $attributes = 'r="'.$rowNumber.'"';
        if ($outlineLevel > 0) {
            $attributes .= ' outlineLevel="'.$outlineLevel.'"';
        }
        if ($hidden) {
            $attributes .= ' hidden="1"';
        }

        fwrite($handle, '<row '.$attributes.'>');
        foreach ($row as $colIndex => $value) {
            if ($value === '') {
                continue;
            }

            $ref = $this->columnLetter($colIndex).$rowNumber;
            if (is_numeric($value) && preg_match('/^-?\d+(\.\d+)?$/', (string) $value) === 1) {
                fwrite($handle, '<c r="'.$ref.'"><v>'.htmlspecialchars((string) $value, ENT_XML1).'</v></c>');

                continue;
            }

            fwrite(
                $handle,
                '<c r="'.$ref.'" t="inlineStr"><is><t>'
                .htmlspecialchars((string) $value, ENT_XML1)
                .'</t></is></c>',
            );
        }
        fwrite($handle, '</row>');
    }

    private function columnLetter(int $index): string
    {
        $letter = '';
        $n = $index + 1;
        while ($n > 0) {
            $n--;
            $letter = chr(65 + ($n % 26)).$letter;
            $n = intdiv($n, 26);
        }

        return $letter;
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function removeTempdir(): void
    {
        if ($this->sheetPath !== null && is_file($this->sheetPath)) {
            @unlink($this->sheetPath);
        }

        if ($this->tmpdir !== null && is_dir($this->tmpdir)) {
            @rmdir($this->tmpdir);
        }

        $this->tmpdir = null;
        $this->sheetPath = null;
    }
}

<?php

namespace App\Support\Oem;

use App\Support\Finance\CaMonthlyReportXmlCellEncoder;
use ZipArchive;

/**
 * Minimal OOXML workbook for Mantra RDWarrantyCheck bulk serial import (column A: SerialNo).
 */
final class RdWarrantyCheckSerialNumbersXlsxWriter
{
    public const WORKSHEET_NAME = 'RDWarrantyCheckSampleSheet';

    public const HEADER_COLUMN = 'SerialNo';

    private readonly CaMonthlyReportXmlCellEncoder $cellEncoder;

    public function __construct(?CaMonthlyReportXmlCellEncoder $cellEncoder = null)
    {
        $this->cellEncoder = $cellEncoder ?? new CaMonthlyReportXmlCellEncoder;
    }

    /**
     * @param  list<string>  $serials
     */
    public function writeToPath(string $outputPath, array $serials): void
    {
        $tmpdir = sys_get_temp_dir().'/rd-warranty-serial-xlsx-'.uniqid('', true);
        if (! mkdir($tmpdir) && ! is_dir($tmpdir)) {
            throw new \RuntimeException('Could not create temporary XLSX workspace.');
        }

        try {
            $sheetPath = $tmpdir.'/sheet1.xml';
            $this->writeWorksheetXml($sheetPath, $serials);

            $zip = new ZipArchive;
            if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not create XLSX archive.');
            }

            $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
            $zip->addFromString('_rels/.rels', $this->rootRelsXml());
            $zip->addFromString('docProps/app.xml', $this->appXml());
            $zip->addFromString('docProps/core.xml', $this->coreXml());
            $zip->addFromString('xl/workbook.xml', $this->workbookXml());
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml());
            $zip->addFromString('xl/styles.xml', $this->stylesXml());
            $zip->addFile($sheetPath, 'xl/worksheets/sheet1.xml');

            if ($zip->close() !== true) {
                throw new \RuntimeException('Could not finalize XLSX archive.');
            }
        } finally {
            if (is_file($sheetPath ?? '')) {
                @unlink($sheetPath);
            }
            @rmdir($tmpdir);
        }
    }

    /**
     * @param  list<string>  $serials
     */
    private function writeWorksheetXml(string $path, array $serials): void
    {
        $lastRow = max(1, count($serials) + 1);
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Could not open worksheet file.');
        }

        fwrite($handle, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>');
        fwrite($handle, '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">');
        fwrite($handle, '<dimension ref="A1:A'.$lastRow.'"/>');
        fwrite($handle, '<sheetViews><sheetView workbookViewId="0"/></sheetViews>');
        fwrite($handle, '<sheetData>');

        $this->writeTextRow($handle, 1, [self::HEADER_COLUMN]);

        $rowNumber = 1;
        foreach ($serials as $serial) {
            $rowNumber++;
            $this->writeTextRow($handle, $rowNumber, [$serial]);
        }

        fwrite($handle, '</sheetData>');
        fwrite($handle, '</worksheet>');
        fclose($handle);
    }

    /**
     * @param  resource  $handle
     * @param  list<string>  $values
     */
    private function writeTextRow($handle, int $rowNumber, array $values): void
    {
        fwrite($handle, '<row r="'.$rowNumber.'">');
        foreach ($values as $colIndex => $value) {
            if ($value === '') {
                continue;
            }

            $ref = $this->columnLetter($colIndex).$rowNumber;
            $inlineString = $this->cellEncoder->inlineStringXml($value);
            if ($inlineString === '') {
                continue;
            }

            fwrite($handle, '<c r="'.$ref.'" t="inlineStr" s="1">'.$inlineString.'</c>');
        }
        fwrite($handle, '</row>');
    }

    private function columnLetter(int $index): string
    {
        $index++;
        $letters = '';
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letters = chr(65 + $mod).$letters;
            $index = intdiv($index - 1, 26);
        }

        return $letters;
    }

    private function contentTypesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
  <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
</Types>
XML;
    }

    private function rootRelsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>
XML;
    }

    private function workbookXml(): string
    {
        $name = htmlspecialchars(self::WORKSHEET_NAME, ENT_XML1 | ENT_DISALLOWED, 'UTF-8');

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="{$name}" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>
XML;
    }

    private function workbookRelsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML;
    }

    private function stylesXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>
  <fills count="1"><fill><patternFill patternType="none"/></fill></fills>
  <borders count="1"><border/></borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="2">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>
  </cellXfs>
  <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>
XML;
    }

    private function appXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">
  <Application>Radium Desk</Application>
</Properties>
XML;
    }

    private function coreXml(): string
    {
        $created = gmdate('Y-m-d\TH:i:s\Z');

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <dc:title>RD Warranty Check Serial Numbers</dc:title>
  <dc:creator>Radium Desk</dc:creator>
  <dcterms:created xsi:type="dcterms:W3CDTF">{$created}</dcterms:created>
</cp:coreProperties>
XML;
    }
}

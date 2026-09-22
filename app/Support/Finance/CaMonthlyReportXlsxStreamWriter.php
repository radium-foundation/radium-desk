<?php

namespace App\Support\Finance;

use App\Reports\CaMonthly\CaMonthlyReportDefinition;
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

    private int $dataRowCount = 0;

    /**
     * @param  list<string>  $headers
     */
    public function open(string $outputPath, array $headers): void
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
        fwrite($handle, '<?xml version="1.0" encoding="UTF-8"?>');
        fwrite($handle, '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');

        for ($row = 1; $row < CaMonthlyReportDefinition::HEADER_ROW; $row++) {
            fwrite($handle, '<row r="'.$row.'"></row>');
        }

        $this->writeRowXml($handle, CaMonthlyReportDefinition::HEADER_ROW, $headers);
        $this->dataRowCount = 0;
        $this->outputPath = $outputPath;
    }

    /**
     * @param  list<string>  $cells
     */
    public function appendRow(array $cells): void
    {
        if ($this->sheetHandle === null) {
            throw new \RuntimeException('XLSX stream writer is not open.');
        }

        $this->dataRowCount++;
        $rowNumber = CaMonthlyReportDefinition::HEADER_ROW + $this->dataRowCount;
        $this->writeRowXml($this->sheetHandle, $rowNumber, $cells);
    }

    public function close(): void
    {
        if ($this->sheetHandle === null || $this->tmpdir === null || $this->sheetPath === null) {
            throw new \RuntimeException('XLSX stream writer is not open.');
        }

        fwrite($this->sheetHandle, '</sheetData></worksheet>');
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

    /**
     * @param  resource  $handle
     * @param  list<string>  $row
     */
    private function writeRowXml($handle, int $rowNumber, array $row): void
    {
        fwrite($handle, '<row r="'.$rowNumber.'">');
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

    private string $outputPath = '';
}

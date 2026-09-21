<?php

namespace App\Support\Finance;

use App\Reports\CaMonthly\CaMonthlyReportDefinition;
use ZipArchive;

final class CaMonthlyReportXlsxWriter
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $dataRows
     */
    public function write(string $path, array $headers, array $dataRows): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
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

        $rows = [];
        for ($row = 1; $row < CaMonthlyReportDefinition::HEADER_ROW; $row++) {
            $rows[] = [];
        }
        $rows[] = $headers;
        foreach ($dataRows as $dataRow) {
            $rows[] = $dataRow;
        }

        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheetXml($rows));
        $zip->close();
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function sheetXml(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        foreach ($rows as $rowIndex => $row) {
            $r = $rowIndex + 1;
            $xml .= '<row r="'.$r.'">';
            foreach ($row as $colIndex => $value) {
                if ($value === '') {
                    continue;
                }
                $ref = $this->columnLetter($colIndex).$r;
                if (is_numeric($value) && preg_match('/^-?\d+(\.\d+)?$/', (string) $value) === 1) {
                    $xml .= '<c r="'.$ref.'"><v>'.htmlspecialchars((string) $value, ENT_XML1).'</v></c>';

                    continue;
                }
                $xml .= '<c r="'.$ref.'" t="inlineStr"><is><t>'
                    .htmlspecialchars((string) $value, ENT_XML1)
                    .'</t></is></c>';
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
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
}

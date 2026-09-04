<?php

namespace App\Services\Inventory\Opening;

use ZipArchive;

class OpeningInventoryWorkbookWriter
{
    /**
     * @param  list<list<string|int|float|null>>  $opening
     * @param  list<list<string|int|float|null>>  $skus
     * @param  list<list<string|int|float|null>>  $branches
     */
    public function write(string $path, array $opening, array $skus, array $branches): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not write opening inventory workbook.');
        }

        $zip->addFromString('[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
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
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/>
</Relationships>
XML);
        $zip->addFromString('xl/workbook.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Inventory Opening" sheetId="1" r:id="rId1"/>
    <sheet name="SKU Master" sheetId="2" r:id="rId2"/>
    <sheet name="Branches" sheetId="3" r:id="rId3"/>
  </sheets>
</workbook>
XML);
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheetXml([
            OpeningInventoryTemplate::OPENING_HEADERS,
            ...$opening,
        ]));
        $zip->addFromString('xl/worksheets/sheet2.xml', $this->sheetXml([
            OpeningInventoryTemplate::SKU_HEADERS,
            ...$skus,
        ]));
        $zip->addFromString('xl/worksheets/sheet3.xml', $this->sheetXml([
            OpeningInventoryTemplate::BRANCH_HEADERS,
            ...$branches,
        ]));
        $zip->close();
    }

    /**
     * @param  list<list<string|int|float|null>>  $rows
     */
    private function sheetXml(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        foreach ($rows as $rowIndex => $row) {
            $r = $rowIndex + 1;
            $xml .= '<row r="'.$r.'">';
            foreach ($row as $colIndex => $value) {
                $ref = $this->columnLetter($colIndex).$r;
                if ($value === null || $value === '') {
                    continue;
                }
                if (is_int($value) || is_float($value)) {
                    $xml .= '<c r="'.$ref.'"><v>'.$value.'</v></c>';

                    continue;
                }
                $xml .= '<c r="'.$ref.'" t="inlineStr"><is><t>'.htmlspecialchars((string) $value, ENT_XML1).'</t></is></c>';
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

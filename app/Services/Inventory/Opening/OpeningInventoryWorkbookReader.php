<?php

namespace App\Services\Inventory\Opening;

use App\Enums\InventorySerialCondition;
use App\Enums\InventorySerialStatus;
use App\Services\Inventory\Opening\Data\OpeningInventoryBranchRow;
use App\Services\Inventory\Opening\Data\OpeningInventoryCountRow;
use App\Services\Inventory\Opening\Data\OpeningInventoryIssue;
use App\Services\Inventory\Opening\Data\OpeningInventorySkuRow;
use App\Services\Inventory\Opening\Data\OpeningInventoryWorkbook;
use App\Support\Inventory\InventorySerialNumber;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;
use SimpleXMLElement;
use ZipArchive;

class OpeningInventoryWorkbookReader
{
    public function read(string $path, ?string $filename = null): OpeningInventoryWorkbook
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw ValidationException::withMessages([
                'workbook' => 'Opening inventory workbook was not found or is not readable.',
            ]);
        }

        $checksum = hash_file('sha256', $path);
        if (! is_string($checksum)) {
            throw ValidationException::withMessages([
                'workbook' => 'Could not checksum the opening inventory workbook.',
            ]);
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages([
                'workbook' => 'The file is not a valid Excel workbook (.xlsx).',
            ]);
        }

        try {
            $sharedStrings = $this->sharedStrings($zip);
            $sheetFiles = $this->sheetFiles($zip);
            $issues = [];

            $opening = $this->requireSheet($sheetFiles, OpeningInventoryTemplate::SHEET_OPENING, $issues);
            $sku = $this->requireSheet($sheetFiles, OpeningInventoryTemplate::SHEET_SKU, $issues);
            $branches = $this->requireSheet($sheetFiles, OpeningInventoryTemplate::SHEET_BRANCHES, $issues);

            $openingRows = $opening !== null
                ? $this->readOpeningSheet($zip, $opening, $sharedStrings, $issues)
                : [];
            $skuRows = $sku !== null
                ? $this->readSkuSheet($zip, $sku, $sharedStrings, $issues)
                : [];
            $branchRows = $branches !== null
                ? $this->readBranchSheet($zip, $branches, $sharedStrings, $issues)
                : [];
        } finally {
            $zip->close();
        }

        return new OpeningInventoryWorkbook(
            checksum: $checksum,
            filename: $filename ?? basename($path),
            openingRows: $openingRows,
            skuRows: $skuRows,
            branchRows: $branchRows,
            parseIssues: $issues,
        );
    }

    /**
     * @param  array<string, string>  $sheetFiles
     * @param  list<OpeningInventoryIssue>  $issues
     */
    private function requireSheet(array $sheetFiles, string $name, array &$issues): ?string
    {
        if (isset($sheetFiles[$name])) {
            return $sheetFiles[$name];
        }

        $issues[] = new OpeningInventoryIssue(
            sheet: $name,
            rowNumber: 0,
            code: 'missing_sheet',
            message: "Workbook is missing the required sheet {$name}.",
        );

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function sheetFiles(ZipArchive $zip): array
    {
        $workbook = $this->xml($zip, 'xl/workbook.xml');
        $rels = $this->xml($zip, 'xl/_rels/workbook.xml.rels');
        if ($workbook === null || $rels === null) {
            return [];
        }

        $relTargets = [];
        foreach ($rels->Relationship as $relationship) {
            $id = (string) $relationship['Id'];
            $target = ltrim((string) $relationship['Target'], '/');
            if (! str_starts_with($target, 'xl/')) {
                $target = 'xl/'.$target;
            }
            $relTargets[$id] = $target;
        }

        $sheets = [];
        foreach ($workbook->sheets->sheet ?? [] as $sheet) {
            $name = (string) $sheet['name'];
            $relId = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            if ($name !== '' && isset($relTargets[$relId])) {
                $sheets[$name] = $relTargets[$relId];
            }
        }

        return $sheets;
    }

    /**
     * @return list<string>
     */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $this->xml($zip, 'xl/sharedStrings.xml');
        if ($xml === null) {
            return [];
        }

        $strings = [];
        foreach ($xml->si as $si) {
            $texts = [];
            foreach ($si->xpath('.//*[local-name()="t"]') ?: [] as $text) {
                $texts[] = (string) $text;
            }
            $strings[] = implode('', $texts);
        }

        return $strings;
    }

    /**
     * @param  list<string>  $sharedStrings
     * @param  list<OpeningInventoryIssue>  $issues
     * @return list<OpeningInventoryCountRow>
     */
    private function readOpeningSheet(ZipArchive $zip, string $sheetPath, array $sharedStrings, array &$issues): array
    {
        $map = $this->headerMap($zip, $sheetPath, $sharedStrings, OpeningInventoryTemplate::OPENING_HEADERS, OpeningInventoryTemplate::SHEET_OPENING, $issues);
        if ($map === null) {
            return [];
        }

        $rows = [];
        foreach ($this->dataRows($zip, $sheetPath, $sharedStrings) as $rowNumber => $cells) {
            $branch = $this->cell($cells, $map, 'Branch Code');
            $sku = strtoupper($this->cell($cells, $map, 'SKU'));
            $serial = InventorySerialNumber::normalize($this->cell($cells, $map, 'Serial Number'));
            if ($branch === '' && $sku === '' && $serial === '') {
                continue;
            }

            $rows[] = new OpeningInventoryCountRow(
                rowNumber: $rowNumber,
                openingDate: $this->parseDate($this->cell($cells, $map, 'Opening Date')),
                branchCode: strtoupper($branch),
                locationType: $this->cell($cells, $map, 'Location Type'),
                sku: $sku,
                variantSku: strtoupper($this->cell($cells, $map, 'Variant SKU')),
                productName: $this->cell($cells, $map, 'Product Name'),
                serializedHint: $this->parseYesNo($this->cell($cells, $map, 'Serialized')),
                rawCondition: $this->cell($cells, $map, 'Condition'),
                condition: InventorySerialCondition::tryFromLabel($this->cell($cells, $map, 'Condition')),
                rawStockStatus: $this->cell($cells, $map, 'Stock Status'),
                stockStatus: $this->parseStockStatus($this->cell($cells, $map, 'Stock Status')),
                serialNumber: $serial,
                quantity: $this->parseQuantity($this->cell($cells, $map, 'Quantity')),
                unitCost: $this->parseDecimal($this->cell($cells, $map, 'Unit Cost')),
                sellingPrice: $this->parseDecimal($this->cell($cells, $map, 'Selling Price')),
                gstPercentage: $this->parseDecimal($this->cell($cells, $map, 'GST %')),
                hsn: $this->cell($cells, $map, 'HSN'),
                countedBy: $this->cell($cells, $map, 'Counted By'),
                remarks: $this->cell($cells, $map, 'Remarks'),
                rowIssues: $this->cell($cells, $map, 'Row Issues'),
            );
        }

        return $rows;
    }

    /**
     * @param  list<string>  $sharedStrings
     * @param  list<OpeningInventoryIssue>  $issues
     * @return list<OpeningInventorySkuRow>
     */
    private function readSkuSheet(ZipArchive $zip, string $sheetPath, array $sharedStrings, array &$issues): array
    {
        $map = $this->headerMap($zip, $sheetPath, $sharedStrings, OpeningInventoryTemplate::SKU_HEADERS, OpeningInventoryTemplate::SHEET_SKU, $issues);
        if ($map === null) {
            return [];
        }

        $rows = [];
        foreach ($this->dataRows($zip, $sheetPath, $sharedStrings) as $rowNumber => $cells) {
            $sku = strtoupper($this->cell($cells, $map, 'SKU'));
            if ($sku === '') {
                continue;
            }

            $rows[] = new OpeningInventorySkuRow(
                rowNumber: $rowNumber,
                sku: $sku,
                name: $this->cell($cells, $map, 'Product Name'),
                variantSku: strtoupper($this->cell($cells, $map, 'Variant SKU')),
                serialized: $this->parseYesNo($this->cell($cells, $map, 'Serialized')),
                hsn: $this->cell($cells, $map, 'HSN'),
                gstPercentage: $this->parseDecimal($this->cell($cells, $map, 'GST %')),
                unitPrice: $this->parseDecimal($this->cell($cells, $map, 'Default Selling Price')),
                unitCost: $this->parseDecimal($this->cell($cells, $map, 'Default Unit Cost')),
                active: $this->parseYesNo($this->cell($cells, $map, 'Active')),
                remarks: $this->cell($cells, $map, 'Remarks'),
            );
        }

        return $rows;
    }

    /**
     * @param  list<string>  $sharedStrings
     * @param  list<OpeningInventoryIssue>  $issues
     * @return list<OpeningInventoryBranchRow>
     */
    private function readBranchSheet(ZipArchive $zip, string $sheetPath, array $sharedStrings, array &$issues): array
    {
        $map = $this->headerMap($zip, $sheetPath, $sharedStrings, OpeningInventoryTemplate::BRANCH_HEADERS, OpeningInventoryTemplate::SHEET_BRANCHES, $issues);
        if ($map === null) {
            return [];
        }

        $rows = [];
        foreach ($this->dataRows($zip, $sheetPath, $sharedStrings) as $rowNumber => $cells) {
            $code = strtoupper($this->cell($cells, $map, 'Branch Code'));
            if ($code === '') {
                continue;
            }

            $rows[] = new OpeningInventoryBranchRow(
                rowNumber: $rowNumber,
                code: $code,
                name: $this->cell($cells, $map, 'Branch Name'),
                locationType: $this->cell($cells, $map, 'Location Type'),
                gstin: $this->cell($cells, $map, 'GSTIN'),
                state: $this->cell($cells, $map, 'State'),
                city: $this->cell($cells, $map, 'City'),
                address: $this->cell($cells, $map, 'Address'),
                active: $this->parseYesNo($this->cell($cells, $map, 'Active')),
                notes: $this->cell($cells, $map, 'Notes'),
            );
        }

        return $rows;
    }

    /**
     * @param  list<string>  $expected
     * @param  list<string>  $sharedStrings
     * @param  list<OpeningInventoryIssue>  $issues
     * @return array<string, int>|null
     */
    private function headerMap(
        ZipArchive $zip,
        string $sheetPath,
        array $sharedStrings,
        array $expected,
        string $sheetName,
        array &$issues,
    ): ?array {
        $headerRow = $this->rows($zip, $sheetPath, $sharedStrings)[1] ?? [];
        $map = [];
        foreach ($headerRow as $index => $value) {
            $label = trim($value);
            if ($label !== '') {
                $map[$label] = $index;
            }
        }

        $missing = array_values(array_filter(
            $expected,
            fn (string $header): bool => ! array_key_exists($header, $map),
        ));
        if ($missing !== []) {
            $issues[] = new OpeningInventoryIssue(
                sheet: $sheetName,
                rowNumber: 1,
                code: 'missing_headers',
                message: 'Sheet is missing required columns: '.implode(', ', $missing).'.',
            );

            return null;
        }

        return $map;
    }

    /**
     * @param  list<string>  $sharedStrings
     * @return array<int, array<int, string>>
     */
    private function dataRows(ZipArchive $zip, string $sheetPath, array $sharedStrings): array
    {
        $rows = $this->rows($zip, $sheetPath, $sharedStrings);
        unset($rows[1]);

        return $rows;
    }

    /**
     * @param  list<string>  $sharedStrings
     * @return array<int, array<int, string>>
     */
    private function rows(ZipArchive $zip, string $sheetPath, array $sharedStrings): array
    {
        $xml = $this->xml($zip, $sheetPath);
        if ($xml === null) {
            return [];
        }

        $rows = [];
        foreach ($xml->sheetData->row ?? [] as $row) {
            $rowNumber = (int) $row['r'];
            $cells = [];
            foreach ($row->c as $cell) {
                $ref = (string) $cell['r'];
                $index = $this->columnIndex($ref);
                $cells[$index] = $this->cellValue($cell, $sharedStrings);
            }
            $rows[$rowNumber] = $cells;
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $cells
     * @param  array<string, int>  $map
     */
    private function cell(array $cells, array $map, string $header): string
    {
        $index = $map[$header] ?? null;
        if ($index === null) {
            return '';
        }

        return trim((string) ($cells[$index] ?? ''));
    }

    /**
     * @param  list<string>  $sharedStrings
     */
    private function cellValue(SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) $cell['t'];
        if ($type === 's') {
            $index = (int) $cell->v;

            return (string) ($sharedStrings[$index] ?? '');
        }

        if ($type === 'inlineStr') {
            $texts = [];
            foreach ($cell->xpath('.//*[local-name()="t"]') ?: [] as $text) {
                $texts[] = (string) $text;
            }

            return implode('', $texts);
        }

        if ($cell->v !== null) {
            return (string) $cell->v;
        }

        return '';
    }

    private function columnIndex(string $ref): int
    {
        preg_match('/^[A-Z]+/', strtoupper($ref), $match);
        $letters = $match[0] ?? 'A';
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index - 1;
    }

    private function parseDate(string $value): ?CarbonInterface
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $serial = (float) $value;
            if ($serial >= 43831 && $serial <= 49674) {
                return CarbonImmutable::create(1899, 12, 30)->addDays((int) $serial);
            }

            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseYesNo(string $value): ?bool
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        return match ($normalized) {
            'y', 'yes', '1', 'true' => true,
            'n', 'no', '0', 'false' => false,
            default => null,
        };
    }

    private function parseStockStatus(string $value): ?InventorySerialStatus
    {
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            'available' => InventorySerialStatus::Available,
            'damaged' => InventorySerialStatus::Damaged,
            default => null,
        };
    }

    private function parseQuantity(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        $qty = (int) $value;
        if ((string) $qty !== (string) (int) (float) $value && (float) $value !== (float) $qty) {
            return null;
        }

        return $qty;
    }

    private function parseDecimal(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function xml(ZipArchive $zip, string $path): ?SimpleXMLElement
    {
        $contents = $zip->getFromName($path);
        if (! is_string($contents) || $contents === '') {
            return null;
        }

        $xml = simplexml_load_string($contents);

        return $xml instanceof SimpleXMLElement ? $xml : null;
    }
}

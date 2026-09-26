<?php

namespace App\Support\Finance;

use App\Reports\CaMonthly\CaMonthlyReportDefinition;
use ZipArchive;

/**
 * Validates CA Monthly XLSX packages for Microsoft Excel Desktop compatibility.
 */
final class CaMonthlyReportXlsxPackageValidator
{
    /**
     * @return list<string>
     */
    public function validate(string $path): array
    {
        $errors = [];

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return ['Could not open XLSX as ZIP archive.'];
        }

        $requiredParts = [
            '[Content_Types].xml',
            '_rels/.rels',
            'xl/workbook.xml',
            'xl/_rels/workbook.xml.rels',
            'xl/styles.xml',
            'xl/worksheets/sheet1.xml',
        ];

        foreach ($requiredParts as $part) {
            if ($zip->locateName($part) === false) {
                $errors[] = 'Missing required package part: '.$part;
            }
        }

        $contentTypes = (string) $zip->getFromName('[Content_Types].xml');
        if ($contentTypes !== '' && ! str_contains($contentTypes, '/xl/styles.xml')) {
            $errors[] = 'Content types must declare xl/styles.xml.';
        }

        $workbookRels = (string) $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookRels !== '' && ! str_contains($workbookRels, 'relationships/styles')) {
            $errors[] = 'Workbook relationships must include styles.';
        }

        $workbookXml = (string) $zip->getFromName('xl/workbook.xml');
        if ($workbookXml !== '' && ! str_contains($workbookXml, '<bookViews>')) {
            $errors[] = 'Workbook must declare bookViews for Excel Desktop.';
        }

        $stylesXml = (string) $zip->getFromName('xl/styles.xml');
        if ($stylesXml !== '') {
            $errors = array_merge($errors, $this->validateXmlFragment($stylesXml, 'styles.xml'));
        }

        $sheetXml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheetXml !== '') {
            $errors = array_merge($errors, $this->validateWorksheetXml($sheetXml));
        }

        $zip->close();

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function validateWorksheetXml(string $xml): array
    {
        $errors = $this->validateXmlFragment($xml, 'sheet1.xml');

        if (! str_contains($xml, '<dimension ')) {
            $errors[] = 'Worksheet must declare a dimension reference.';
        }

        if (! str_contains($xml, '<sheetData>')) {
            $errors[] = 'Worksheet must contain sheetData.';
        }

        if (preg_match('/fitToHeight="0"/', $xml) === 1) {
            $errors[] = 'Worksheet pageSetup must not use fitToHeight="0".';
        }

        $headerNeedle = '<is><t>'.CaMonthlyReportDefinition::HEADERS[0].'</t></is>';
        if (! str_contains($xml, $headerNeedle)) {
            $errors[] = 'Worksheet is missing the first CA Monthly header cell.';
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function validateXmlFragment(string $xml, string $label): array
    {
        if ($xml === '') {
            return [$label.' is empty.'];
        }

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        $parseErrors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded !== true) {
            $message = trim($parseErrors[0]->message ?? 'unknown XML parse error');

            return [$label.' is not well-formed XML: '.$message];
        }

        return [];
    }
}

<?php

namespace Tests\Unit\Finance;

use App\Support\Finance\CaMonthlyReportXlsxPackageValidator;
use Tests\TestCase;
use ZipArchive;

class CaMonthlyReportXlsxPackageValidatorTest extends TestCase
{
    public function test_validator_flags_minimal_package_missing_excel_required_parts(): void
    {
        $path = storage_path('app/tmp/ca-monthly-invalid-package.xlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"></workbook>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData></sheetData></worksheet>');
        $zip->close();

        $errors = (new CaMonthlyReportXlsxPackageValidator)->validate($path);

        $this->assertNotSame([], $errors);
        $this->assertTrue(collect($errors)->contains(fn (string $error): bool => str_contains($error, 'styles.xml')));

        @unlink($path);
    }
}

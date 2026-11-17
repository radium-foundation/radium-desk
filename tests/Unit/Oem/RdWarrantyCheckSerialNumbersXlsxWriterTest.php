<?php

namespace Tests\Unit\Oem;

use App\Support\Oem\RdWarrantyCheckSerialNumbersXlsxWriter;
use Tests\TestCase;
use ZipArchive;

class RdWarrantyCheckSerialNumbersXlsxWriterTest extends TestCase
{
    private RdWarrantyCheckSerialNumbersXlsxWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = new RdWarrantyCheckSerialNumbersXlsxWriter;
    }

    public function test_workbook_uses_sample_sheet_name_and_serialno_header(): void
    {
        $path = $this->writeSample(['SN-A', 'SN-B']);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));

        $workbook = $zip->getFromName('xl/workbook.xml');
        $this->assertIsString($workbook);
        $this->assertStringContainsString(
            'name="'.RdWarrantyCheckSerialNumbersXlsxWriter::WORKSHEET_NAME.'"',
            $workbook,
        );

        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertIsString($sheet);
        $this->assertStringContainsString(
            RdWarrantyCheckSerialNumbersXlsxWriter::HEADER_COLUMN,
            $sheet,
        );
        $this->assertStringContainsString('SN-A', $sheet);
        $this->assertStringContainsString('SN-B', $sheet);
        $this->assertStringContainsString('t="inlineStr"', $sheet);

        $zip->close();
        @unlink($path);
    }

    public function test_serial_numbers_are_written_as_inline_text_not_numeric_cells(): void
    {
        $path = $this->writeSample(['0045678901']);

        $sheet = file_get_contents('zip://'.$path.'#xl/worksheets/sheet1.xml');
        $this->assertIsString($sheet);
        $this->assertStringContainsString('0045678901', $sheet);
        $this->assertStringContainsString('t="inlineStr"', $sheet);
        $this->assertDoesNotMatchRegularExpression('/<c r="A2"[^>]*><v>45678901<\/v>/', $sheet);

        @unlink($path);
    }

    public function test_empty_serial_list_writes_header_only(): void
    {
        $path = $this->writeSample([]);

        $sheet = file_get_contents('zip://'.$path.'#xl/worksheets/sheet1.xml');
        $this->assertIsString($sheet);
        $this->assertStringContainsString(RdWarrantyCheckSerialNumbersXlsxWriter::HEADER_COLUMN, $sheet);
        $this->assertDoesNotMatchRegularExpression('/<row r="2"/', $sheet);

        @unlink($path);
    }

    /**
     * @param  list<string>  $serials
     */
    private function writeSample(array $serials): string
    {
        $path = sys_get_temp_dir().'/rd-warranty-writer-'.uniqid('', true).'.xlsx';
        $this->writer->writeToPath($path, $serials);

        return $path;
    }
}

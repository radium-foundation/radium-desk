<?php

namespace Tests\Unit\DashboardOemHandoff;

use App\Services\DashboardOemHandoff\MantraHandoffResultValidator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MantraHandoffResultValidatorTest extends TestCase
{
    private MantraHandoffResultValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new MantraHandoffResultValidator;
    }

    public function test_accepts_valid_mantra_row_with_distinct_expiry_fields(): void
    {
        $result = $this->validator->validateDraft('6540662', [
            'outcome' => 'found',
            'rows' => [[
                'serial_number' => '6540662',
                'warranty_expiry_date' => '2027-01-15',
                'rd_expiry_date' => '2026-06-01',
                'model' => 'MFS 110',
            ]],
        ]);

        $this->assertSame('6540662', $result['serial_number']);
        $this->assertSame('2027-01-15', $result['warranty_expiry_date']);
        $this->assertSame('2026-06-01', $result['rd_expiry_date']);
        $this->assertSame('MFS 110', $result['model']);
    }

    public function test_rejects_mismatched_serial(): void
    {
        $this->expectException(ValidationException::class);

        $this->validator->validateDraft('6540662', [
            'outcome' => 'found',
            'rows' => [[
                'serial_number' => '6540663',
                'warranty_expiry_date' => '2027-01-15',
                'rd_expiry_date' => '2026-06-01',
                'model' => 'MFS 110',
            ]],
        ]);
    }

    #[DataProvider('invalidDateProvider')]
    public function test_rejects_invalid_date_formats(string $badDate): void
    {
        $this->expectException(ValidationException::class);

        $this->validator->validateDraft('6540662', [
            'outcome' => 'found',
            'rows' => [[
                'serial_number' => '6540662',
                'warranty_expiry_date' => $badDate,
                'rd_expiry_date' => '2026-06-01',
                'model' => 'MFS 110',
            ]],
        ]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidDateProvider(): array
    {
        return [
            'garbage' => ['not-a-date'],
        ];
    }
}

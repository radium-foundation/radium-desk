<?php

namespace Tests\Unit\SerialValidation;

use App\Data\SerialValidationResult;
use App\Enums\SerialValidationStatus;
use App\Services\SerialValidation\Validators\Fm220SerialValidator;
use App\Services\SerialValidation\Validators\Marc11SerialValidator;
use App\Services\SerialValidation\Validators\Mfs110SerialValidator;
use App\Services\SerialValidation\Validators\Mis100SerialValidator;
use App\Services\SerialValidation\Validators\MsoE3SerialValidator;
use App\Services\SerialValidation\Validators\Pb1000SerialValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductSerialValidatorTest extends TestCase
{
    #[DataProvider('mfs110Examples')]
    public function test_mfs110_validator(string $serial, bool $valid): void
    {
        $result = app(Mfs110SerialValidator::class)->validate($serial);

        $this->assertSame($valid, $result->isValid(), $result->reason ?? 'unexpected result');
        $this->assertSame('MFS 110', $result->product);
    }

    public static function mfs110Examples(): array
    {
        return [
            'valid 7 digit starting with 7' => ['7881953', true],
            'valid 7 digit starting with 6' => ['6881953', true],
            'valid 8 digit starting with 1' => ['12345678', true],
            'invalid 7 digit starting with 5' => ['5881953', false],
            'invalid 8 digit not starting with 1' => ['22345678', false],
            'invalid 12 digit serial' => ['252601401258', false],
            'invalid 6 digits' => ['123456', false],
            'invalid non numeric' => ['788195A', false],
        ];
    }

    #[DataProvider('mis100Examples')]
    public function test_mis100_validator(string $serial, bool $valid): void
    {
        $result = app(Mis100SerialValidator::class)->validate($serial);

        $this->assertSame($valid, $result->isValid(), $result->reason ?? 'unexpected result');
        $this->assertSame('MIS 100', $result->product);
    }

    public static function mis100Examples(): array
    {
        return [
            'valid 7 digit any first digit' => ['9655721', true],
            'valid 7 digit starting with 1' => ['1234567', true],
            'valid 8 digit starting with 1' => ['12345678', true],
            'invalid 8 digit not starting with 1' => ['22345678', false],
            'invalid 10 digits' => ['1234567890', false],
        ];
    }

    #[DataProvider('msoE3Examples')]
    public function test_mso_e3_validator(string $serial, bool $valid, ?string $expectedSerial = null, ?string $expectedSeverity = null): void
    {
        $result = app(MsoE3SerialValidator::class)->validate($serial);

        if ($expectedSeverity === 'warning') {
            $this->assertTrue($result->isWarning(), $result->reason ?? 'unexpected result');

            return;
        }

        $this->assertSame($valid, $result->isValid(), $result->reason ?? 'unexpected result');

        if ($valid) {
            $this->assertTrue($result->requiresRadiumBoxVerification);
            $this->assertSame($expectedSerial ?? strtoupper(trim($serial)), $result->normalizedSerial);
        }
    }

    public static function msoE3Examples(): array
    {
        return [
            'valid 2405I002878' => ['2405I002878', true],
            'valid 2423I016089' => ['2423I016089', true],
            'valid 2541I013227' => ['2541I013227', true],
            'valid 2424I023017' => ['2424I023017', true],
            'valid 2425I022416' => ['2425I022416', true],
            'valid 2406I010321' => ['2406I010321', true],
            'valid 2526I057948' => ['2526I057948', true],
            'auto correct L to I' => ['2423L016089', true, '2423I016089'],
            'auto correct 1 to I' => ['25411013227', true, '2541I013227'],
            'auto correct lowercase l to I' => ['2424l023017', true, '2424I023017'],
            'invalid prefix 17' => ['1705I002878', false],
            'invalid prefix 18' => ['1823I016089', false],
            'invalid prefix 19' => ['1941I013227', false],
            'warning prefix 20' => ['2024I023017', false, null, 'warning'],
            'invalid prefix 21' => ['2125I022416', false],
            'invalid prefix 22' => ['2206I010321', false],
            'invalid wrong length' => ['2526I05794', false],
            'invalid non numeric prefix' => ['24A5I002878', false],
            'invalid non numeric suffix' => ['2405I00287A', false],
            'invalid fifth character' => ['2405X002878', false],
            'no correction when prefix rejected' => ['1705L002878', false],
        ];
    }

    public function test_mso_e3_correction_flags(): void
    {
        $result = app(MsoE3SerialValidator::class)->validate('2423L016089');

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->corrected);
        $this->assertTrue($result->requiresRadiumBoxVerification);
        $this->assertSame('Corrected by IRA', $result->reason);
        $this->assertSame('2423I016089', $result->normalizedSerial);
    }

    public function test_mso_e3_valid_without_correction_still_requires_radiumbox_verification(): void
    {
        $result = app(MsoE3SerialValidator::class)->validate('2405I002878');

        $this->assertTrue($result->isValid());
        $this->assertFalse($result->corrected);
        $this->assertTrue($result->requiresRadiumBoxVerification);
    }

    #[DataProvider('fm220Examples')]
    public function test_fm220_validator(string $serial, bool $valid, ?string $expectedSeverity = null): void
    {
        $result = app(Fm220SerialValidator::class)->validate($serial);

        if ($expectedSeverity === 'warning') {
            $this->assertTrue($result->isWarning(), $result->reason ?? 'unexpected result');

            return;
        }

        $this->assertSame($valid, $result->isValid(), $result->reason ?? 'unexpected result');
        $this->assertSame('FM 220', $result->product);
    }

    public static function fm220Examples(): array
    {
        return [
            'valid radiumbox serial' => ['M250546898', true],
            'valid P25 prefix' => ['P250546898', true],
            'valid M22 prefix' => ['M220546898', true],
            'valid M26 prefix' => ['M260779805', true],
            'invalid wrong model code' => ['M210546898', false],
            'invalid wrong first character' => ['A250546898', false],
            'invalid 9 characters' => ['M25054689', false],
            'warning B47 nine character pattern' => ['B47C11929', false, 'warning'],
            'invalid numeric only' => ['1234567890', false],
        ];
    }

    #[DataProvider('pb1000Examples')]
    public function test_pb1000_validator(string $serial, bool $valid): void
    {
        $result = app(Pb1000SerialValidator::class)->validate($serial);

        $this->assertSame($valid, $result->isValid(), $result->reason ?? 'unexpected result');
        $this->assertSame('PB 1000', $result->product);
    }

    public static function pb1000Examples(): array
    {
        return [
            'valid LN prefix' => ['LN1234567890', true],
            'valid LU prefix' => ['LU1234567890', true],
            'invalid wrong prefix' => ['LP1234567890', false],
            'invalid 11 characters' => ['LN123456789', false],
            'invalid numeric only' => ['123456789012', false],
        ];
    }

    #[DataProvider('marc11Examples')]
    public function test_marc11_validator(string $serial, bool $valid, ?string $expectedSeverity = null): void
    {
        $result = app(Marc11SerialValidator::class)->validate($serial);

        if ($expectedSeverity === 'warning') {
            $this->assertTrue($result->isWarning(), $result->reason ?? 'unexpected result');

            return;
        }

        $this->assertSame($valid, $result->isValid(), $result->reason ?? 'unexpected result');
        $this->assertSame('MARC 11', $result->product);
    }

    public static function marc11Examples(): array
    {
        return [
            'valid 7 digits starting with 7' => ['7881953', true],
            'valid 10 digits starting with 8' => ['8123456789', true],
            'warning 10 digits starting with 25' => ['2503102880', false, 'warning'],
            'invalid 7 digits starting with 1' => ['1234567', false],
            'invalid 8 digits' => ['81234567', false],
            'invalid 12 digits' => ['812345678901', false],
        ];
    }

    public function test_validation_result_helpers(): void
    {
        $valid = SerialValidationResult::valid('1234567', 'MFS 110');
        $warning = SerialValidationResult::warning('B47C11929', 'FM 220', 'Needs review.');
        $invalid = SerialValidationResult::invalid('bad', 'MFS 110', 'Invalid serial.');
        $unsupported = SerialValidationResult::unsupported('1234567', 'AST 300');

        $this->assertSame(SerialValidationStatus::Valid, $valid->status);
        $this->assertSame(SerialValidationStatus::Warning, $warning->status);
        $this->assertSame(SerialValidationStatus::Invalid, $invalid->status);
        $this->assertSame(SerialValidationStatus::Unsupported, $unsupported->status);
        $this->assertTrue($unsupported->requiresRadiumBoxVerification);
    }
}

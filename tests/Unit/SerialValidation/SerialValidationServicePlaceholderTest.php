<?php

namespace Tests\Unit\SerialValidation;

use App\Enums\SerialValidationStatus;
use App\Services\SerialValidation\CanonicalProductResolver;
use App\Services\SerialValidation\SerialValidationService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SerialValidationServicePlaceholderTest extends TestCase
{
    private SerialValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SerialValidationService::class);
    }

    #[DataProvider('placeholderSerialExamples')]
    public function test_placeholder_serials_return_pending_without_running_validators(
        string $serial,
    ): void {
        $result = $this->service->validate($serial, 'MFS110');

        $this->assertSame(SerialValidationStatus::Pending, $result->status);
        $this->assertSame('Waiting for customer serial', $result->reason);
        $this->assertSame('MFS 110', $result->product);
    }

    public static function placeholderSerialExamples(): array
    {
        return [
            'fpspl' => ['FPSPL1141XX'],
            'unknown' => ['UNKNOWN'],
            'plus' => ['+'],
            'minus' => ['-'],
            'blank' => [''],
            'whitespace' => ['   '],
        ];
    }

    public function test_real_serial_still_runs_product_validator(): void
    {
        $valid = $this->service->validate('7881953', 'MFS110');
        $invalid = $this->service->validate('ABC123', 'MFS110');

        $this->assertSame(SerialValidationStatus::Valid, $valid->status);
        $this->assertSame(SerialValidationStatus::Invalid, $invalid->status);
    }
}

class CanonicalProductResolverTest extends TestCase
{
    private CanonicalProductResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(CanonicalProductResolver::class);
    }

    #[DataProvider('canonicalProductExamples')]
    public function test_resolves_canonical_product_names(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->resolver->resolve($input));
    }

    public static function canonicalProductExamples(): array
    {
        return [
            'mfs110' => ['MFS110', 'MFS 110'],
            'mis100' => ['MIS100', 'MIS 100'],
            'mantra mis100' => ['Mantra MIS100', 'MIS 100'],
            'msoe3' => ['MSOE3', 'MSO E3'],
            'morpho mso' => ['Morpho MSO1300 E3', 'MSO E3'],
            'fm220' => ['FM220', 'FM 220'],
            'startek fm220' => ['Startek FM220', 'FM 220'],
            'pb1000' => ['PB1000', 'PB 1000'],
            'marc11' => ['MARC11', 'MARC 11'],
        ];
    }
}

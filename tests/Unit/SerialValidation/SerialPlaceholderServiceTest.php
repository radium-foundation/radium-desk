<?php

namespace Tests\Unit\SerialValidation;

use App\Services\SerialValidation\SerialPlaceholderService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SerialPlaceholderServiceTest extends TestCase
{
    private SerialPlaceholderService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SerialPlaceholderService::class);
    }

    #[DataProvider('placeholderExamples')]
    public function test_recognizes_placeholder_serials(?string $serial): void
    {
        $this->assertTrue($this->service->isPlaceholder($serial));
    }

    public static function placeholderExamples(): array
    {
        return [
            'fpspl prefix' => ['FPSPL1141XX'],
            'fpspl lowercase' => ['fpspl999'],
            'unknown' => ['UNKNOWN'],
            'unknown lowercase' => ['unknown'],
            'blank' => [''],
            'null' => [null],
            'whitespace' => ['   '],
            'plus' => ['+'],
            'minus' => ['-'],
            'na slash' => ['N/A'],
            'na compact' => ['NA'],
            'null literal' => ['NULL'],
        ];
    }

    public function test_does_not_treat_real_serial_as_placeholder(): void
    {
        $this->assertFalse($this->service->isPlaceholder('7881953'));
    }

    public function test_normalize_returns_uppercase_trimmed_value(): void
    {
        $this->assertSame('FPSPL1141XX', $this->service->normalize(' fpspl1141xx '));
    }

    public function test_normalize_returns_null_for_blank_values(): void
    {
        $this->assertNull($this->service->normalize(null));
        $this->assertNull($this->service->normalize(''));
        $this->assertNull($this->service->normalize('   '));
    }
}

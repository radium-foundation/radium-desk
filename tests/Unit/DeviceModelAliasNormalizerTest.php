<?php

namespace Tests\Unit;

use App\Services\DeviceModelAliasNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeviceModelAliasNormalizerTest extends TestCase
{
    private DeviceModelAliasNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = app(DeviceModelAliasNormalizer::class);
    }

    #[DataProvider('normalizationExamples')]
    public function test_normalize_collapses_whitespace_removes_separators_and_lowercases(
        string $input,
        string $expected,
    ): void {
        $this->assertSame($expected, $this->normalizer->normalize($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function normalizationExamples(): array
    {
        return [
            'compact token' => ['MFS110', 'mfs110'],
            'spaced token' => ['MFS 110', 'mfs110'],
            'hyphenated token' => ['MFS-110', 'mfs110'],
            'underscored token' => ['MFS_110', 'mfs110'],
            'vendor prefixed label' => ['Morpho MFS110', 'morphomfs110'],
            'trimmed label' => ['  mso   e3  ', 'msoe3'],
            'empty string' => ['', ''],
            'whitespace only' => ['   ', ''],
        ];
    }
}

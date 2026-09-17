<?php

namespace Tests\Unit\OperationalReference;

use App\Support\OperationalReference\OperationalReferenceParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OperationalReferenceParserTest extends TestCase
{
    #[DataProvider('refundCases')]
    public function test_parse_refund_operational_value(?int $expected, string $reference): void
    {
        $this->assertSame($expected, OperationalReferenceParser::parseRefundOperationalValue($reference));
    }

    /**
     * @return array<string, array{0: ?int, 1: string}>
     */
    public static function refundCases(): array
    {
        return [
            'legacy year format ignored' => [null, 'REF-2026-000314'],
            'new format at floor' => [67315, 'REF-67315'],
            'new format above floor' => [67320, 'REF-67320'],
            'below floor ignored' => [null, 'REF-2026'],
            'invalid prefix' => [null, 'REFX-67315'],
        ];
    }

    #[DataProvider('serviceOrderCases')]
    public function test_parse_service_order_operational_value(?int $expected, string $reference): void
    {
        $this->assertSame($expected, OperationalReferenceParser::parseServiceOrderOperationalValue($reference));
    }

    /**
     * @return array<string, array{0: ?int, 1: string}>
     */
    public static function serviceOrderCases(): array
    {
        return [
            'legacy zero padded ignored' => [null, 'SVC-000001'],
            'new format at floor' => [671, 'SVC-671'],
            'new format above floor' => [680, 'SVC-680'],
            'below floor ignored' => [null, 'SVC-1'],
        ];
    }

    #[DataProvider('productPosCases')]
    public function test_parse_product_pos_operational_value(?int $expected, string $reference): void
    {
        $this->assertSame($expected, OperationalReferenceParser::parseProductPosOperationalValue($reference));
    }

    /**
     * @return array<string, array{0: ?int, 1: string}>
     */
    public static function productPosCases(): array
    {
        return [
            'legacy zero padded ignored' => [null, 'POS-000019'],
            'new format at floor' => [6720, 'POS-6720'],
            'new format above floor' => [6725, 'POS-6725'],
            'below floor ignored' => [null, 'POS-19'],
        ];
    }

    public function test_legacy_detectors(): void
    {
        $this->assertTrue(OperationalReferenceParser::isLegacyRefundReference('REF-2026-000001'));
        $this->assertTrue(OperationalReferenceParser::isLegacyServiceOrderReference('SVC-000001'));
        $this->assertTrue(OperationalReferenceParser::isLegacyProductPosReference('POS-000019'));
        $this->assertFalse(OperationalReferenceParser::isLegacyRefundReference('REF-67315'));
    }
}

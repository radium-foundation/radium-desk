<?php

namespace Tests\Unit\Support\Oem;

use App\Support\Oem\MorphoWhitelistQualification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MorphoWhitelistQualificationTest extends TestCase
{
    #[DataProvider('classificationProvider')]
    public function test_classification(string $expected, string $outcome, array $fields): void
    {
        $this->assertSame($expected, MorphoWhitelistQualification::classify($outcome, $fields));
    }

    public static function classificationProvider(): array
    {
        return [
            'already whitelisted phrase' => [
                MorphoWhitelistQualification::ALREADY_WHITELISTED,
                'found',
                ['device_validity' => 'Valid', 'whitelist_status' => 'Device already whitelisted'],
            ],
            'whitelisted label only' => [
                MorphoWhitelistQualification::ALREADY_WHITELISTED,
                'found',
                ['device_validity' => 'Valid', 'whitelist_status' => 'Whitelisted'],
            ],
            'valid not whitelisted' => [
                MorphoWhitelistQualification::VALID_NOT_WHITELISTED,
                'found',
                ['device_validity' => 'Valid', 'whitelist_status' => 'Valid but not whitelisted'],
            ],
            'valid without whitelist signal' => [
                MorphoWhitelistQualification::VALID_NOT_WHITELISTED,
                'found',
                ['device_validity' => 'Valid', 'whitelist_status' => null],
            ],
            'invalid device' => [
                MorphoWhitelistQualification::INVALID_DEVICE,
                'found',
                ['device_validity' => 'Not Valid', 'whitelist_status' => 'Whitelisted'],
            ],
            'ambiguous status' => [
                MorphoWhitelistQualification::AMBIGUOUS,
                'found',
                ['device_validity' => 'Unknown state', 'whitelist_status' => 'Pending review'],
            ],
            'inconclusive' => [
                MorphoWhitelistQualification::INCONCLUSIVE,
                'inconclusive',
                [],
            ],
        ];
    }

    public function test_population_eligibility_requires_found_and_already_whitelisted(): void
    {
        $this->assertTrue(MorphoWhitelistQualification::isPopulationEligible(
            MorphoWhitelistQualification::ALREADY_WHITELISTED,
            'found',
        ));
        $this->assertFalse(MorphoWhitelistQualification::isPopulationEligible(
            MorphoWhitelistQualification::VALID_NOT_WHITELISTED,
            'found',
        ));
    }
}
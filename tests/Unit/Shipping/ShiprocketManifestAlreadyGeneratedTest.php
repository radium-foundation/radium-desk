<?php

namespace Tests\Unit\Shipping;

use App\Services\Shipping\Data\ShiprocketDocumentResult;
use App\Services\Shipping\ShiprocketManifestAlreadyGenerated;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShiprocketManifestAlreadyGeneratedTest extends TestCase
{
    #[Test]
    public function test_matches_provider_manifest_already_generated_errors(): void
    {
        $result = new ShiprocketDocumentResult(
            provider: 'shiprocket',
            status: 'rejected',
            error: 'Manifest is already generated for this shipment.',
            retryable: false,
        );

        $this->assertTrue(ShiprocketManifestAlreadyGenerated::matchesRejectedResult($result));
    }

    #[Test]
    public function test_ignores_unrelated_rejections(): void
    {
        $result = new ShiprocketDocumentResult(
            provider: 'shiprocket',
            status: 'rejected',
            error: 'Manifest not generated',
            retryable: false,
        );

        $this->assertFalse(ShiprocketManifestAlreadyGenerated::matchesRejectedResult($result));
    }
}

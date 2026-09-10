<?php

namespace Tests\Unit\StatutoryInvoice;

use BaconQrCode\Encoder\Encoder;
use Tests\TestCase;

class EInvoiceComposerQrDependencyTest extends TestCase
{
    public function test_composer_json_and_lock_declare_bacon_and_dasprid(): void
    {
        $json = json_decode((string) file_get_contents(base_path('composer.json')), true);
        $lock = json_decode((string) file_get_contents(base_path('composer.lock')), true);

        $this->assertIsArray($json);
        $this->assertIsArray($lock);
        $this->assertSame('^3.0', $json['require']['bacon/bacon-qr-code'] ?? null);
        $this->assertSame('^1.0.3', $json['require']['dasprid/enum'] ?? null);

        $names = [];
        foreach ($lock['packages'] ?? [] as $package) {
            if (is_array($package) && isset($package['name'])) {
                $names[$package['name']] = $package['version'] ?? null;
            }
        }
        $this->assertArrayHasKey('bacon/bacon-qr-code', $names);
        $this->assertArrayHasKey('dasprid/enum', $names);
        $this->assertSame('v3.1.1', $names['bacon/bacon-qr-code']);
        $this->assertSame('1.0.7', $names['dasprid/enum']);
    }

    public function test_qr_encoder_is_autoloadable(): void
    {
        $this->assertTrue(class_exists(Encoder::class));
    }
}

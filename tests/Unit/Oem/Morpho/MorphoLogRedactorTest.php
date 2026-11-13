<?php

namespace Tests\Unit\Oem\Morpho;

use App\Services\Oem\Morpho\MorphoLogRedactor;
use PHPUnit\Framework\TestCase;

class MorphoLogRedactorTest extends TestCase
{
    public function test_redacts_serial_middle(): void
    {
        $this->assertSame('00*******01', MorphoLogRedactor::redactSerial('0000I000001'));
    }

    public function test_redacts_sensitive_headers(): void
    {
        $redacted = MorphoLogRedactor::redactHeaders([
            'Authorization' => 'secret',
            'SecurityStamp' => 'stamp',
            'Accept' => 'application/json',
        ]);

        $this->assertSame('[redacted]', $redacted['Authorization']);
        $this->assertSame('[redacted]', $redacted['SecurityStamp']);
        $this->assertSame('application/json', $redacted['Accept']);
    }
}

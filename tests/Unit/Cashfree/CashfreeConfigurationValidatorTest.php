<?php

namespace Tests\Unit\Cashfree;

use App\Services\Cashfree\CashfreeConfigurationValidator;
use RuntimeException;
use Tests\TestCase;

class CashfreeConfigurationValidatorTest extends TestCase
{
    public function test_fails_when_signature_verification_enabled_without_client_secret(): void
    {
        config([
            'cashfree.verify_signature' => true,
            'cashfree.client_secret' => null,
        ]);

        $validator = app(CashfreeConfigurationValidator::class);

        $this->assertFalse($validator->isValid());
        $this->assertSame(
            [CashfreeConfigurationValidator::ERROR_MISSING_CLIENT_SECRET],
            $validator->failures(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(CashfreeConfigurationValidator::ERROR_MISSING_CLIENT_SECRET);

        $validator->validate();
    }

    public function test_passes_when_signature_verification_enabled_with_client_secret(): void
    {
        config([
            'cashfree.verify_signature' => true,
            'cashfree.client_secret' => 'live-client-secret',
        ]);

        $validator = app(CashfreeConfigurationValidator::class);

        $this->assertTrue($validator->isValid());
        $this->assertSame([], $validator->failures());
        $validator->validate();

        $this->addToAssertionCount(1);
    }

    public function test_passes_when_signature_verification_disabled_without_client_secret(): void
    {
        config([
            'cashfree.verify_signature' => false,
            'cashfree.client_secret' => null,
        ]);

        $validator = app(CashfreeConfigurationValidator::class);

        $this->assertTrue($validator->isValid());
        $this->assertSame([], $validator->failures());
        $validator->validate();

        $this->addToAssertionCount(1);
    }
}

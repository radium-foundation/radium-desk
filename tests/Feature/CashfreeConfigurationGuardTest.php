<?php

namespace Tests\Feature;

use App\Infrastructure\IntegrationHealth\Probes\CashfreeIntegrationHealthProbe;
use App\Services\Cashfree\CashfreeConfigurationValidator;
use Tests\TestCase;

class CashfreeConfigurationGuardTest extends TestCase
{
    public function test_validate_config_command_fails_when_signature_enabled_without_secret(): void
    {
        config([
            'cashfree.verify_signature' => true,
            'cashfree.client_secret' => null,
        ]);

        $this->artisan('cashfree:validate-config')
            ->assertFailed()
            ->expectsOutputToContain(CashfreeConfigurationValidator::ERROR_MISSING_CLIENT_SECRET);
    }

    public function test_validate_config_command_passes_when_signature_enabled_with_secret(): void
    {
        config([
            'cashfree.verify_signature' => true,
            'cashfree.client_secret' => 'live-client-secret',
        ]);

        $this->artisan('cashfree:validate-config')
            ->assertSuccessful()
            ->expectsOutputToContain('Cashfree configuration is valid.');
    }

    public function test_integration_health_probe_reports_misconfiguration(): void
    {
        config([
            'cashfree.verify_signature' => true,
            'cashfree.client_secret' => null,
        ]);

        $snapshot = app(CashfreeIntegrationHealthProbe::class)->probe();

        $this->assertSame('degraded', $snapshot->connectionStatus);
        $this->assertSame(
            CashfreeConfigurationValidator::ERROR_MISSING_CLIENT_SECRET,
            $snapshot->lastErrorMessage,
        );
    }
}

<?php

namespace Tests\Feature\CentralWallet;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CentralWalletFeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_returns_service_unavailable_when_flags_off(): void
    {
        config([
            'central_wallet.enabled' => false,
            'central_wallet.api_enabled' => false,
            'central_wallet.integration_token' => 'cw-test-token',
        ]);

        $this->withToken('cw-test-token')
            ->getJson('/api/central-wallet/v1/health')
            ->assertStatus(503)
            ->assertJsonPath('error', 'central_wallet_disabled');
    }

    public function test_api_fail_closed_without_token_even_when_enabled(): void
    {
        config([
            'central_wallet.enabled' => true,
            'central_wallet.api_enabled' => true,
            'central_wallet.integration_token' => 'cw-test-token',
        ]);

        $this->getJson('/api/central-wallet/v1/health')
            ->assertUnauthorized();
    }

    public function test_default_config_has_central_wallet_disabled(): void
    {
        $this->assertFalse((bool) config('central_wallet.enabled'));
        $this->assertFalse((bool) config('central_wallet.api_enabled'));
        $this->assertFalse((bool) config('central_wallet.reconciliation.enabled'));
        $this->assertSame(90, (int) config('central_wallet.idempotency_retention_days'));
    }
}

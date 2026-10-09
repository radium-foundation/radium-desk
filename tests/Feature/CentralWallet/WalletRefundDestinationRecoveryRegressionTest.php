<?php

namespace Tests\Feature\CentralWallet;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Regression: P-04-10-52 recovery to v4.1.2 removed the wallet-refund-destination
 * endpoint while rdservice.net wallet refund execution remained enabled in production.
 */
class WalletRefundDestinationRecoveryRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_visibility_route_is_registered(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Route::has('central-wallet.wallet-visibility.show'),
        );
    }

    public function test_wallet_refund_destination_route_is_registered(): void
    {
        $this->assertTrue(Route::has('central-wallet.wallet-refund-destination.show'));

        $route = Route::getRoutes()->getByName('central-wallet.wallet-refund-destination.show');
        $this->assertNotNull($route);
        $this->assertSame('GET', $route->methods()[0] ?? null);
        $this->assertStringContainsString('wallet-refund-destination', $route->uri());
    }

    public function test_restored_config_sections_are_available(): void
    {
        $this->assertIsArray(config('central_wallet.historical_wallet_visibility'));
        $this->assertArrayHasKey('enabled', config('central_wallet.historical_wallet_visibility'));
        $this->assertArrayHasKey('contact_index_manifest_path', config('central_wallet.historical_wallet_visibility'));

        $this->assertIsArray(config('central_wallet.wallet_refund_destination'));
        $this->assertArrayHasKey('enabled', config('central_wallet.wallet_refund_destination'));

        $this->assertIsArray(config('central_wallet.customer_identity_ensure'));
        $this->assertArrayHasKey('enabled', config('central_wallet.customer_identity_ensure'));
    }

    public function test_unauthenticated_wallet_refund_destination_request_is_rejected(): void
    {
        config([
            'central_wallet.enabled' => true,
            'central_wallet.api_enabled' => true,
            'central_wallet.integration_token' => 'recovery-regression-token',
            'central_wallet.historical_wallet_visibility.enabled' => true,
        ]);

        $this->getJson('/api/central-wallet/v1/wallet-refund-destination?site_code=rdservice.net&local_user_id=1&email=test@example.com')
            ->assertUnauthorized();
    }
}

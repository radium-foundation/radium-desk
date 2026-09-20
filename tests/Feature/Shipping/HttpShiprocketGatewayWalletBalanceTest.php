<?php

namespace Tests\Feature\Shipping;

use App\Services\Shipping\HttpShiprocketGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpShiprocketGatewayWalletBalanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'shipping.enabled' => true,
            'shipping.provider' => 'shiprocket',
            'shipping.http_enabled' => true,
            'shipping.base_url' => 'https://apiv2.shiprocket.in/v1/external',
            'shipping.api_email' => 'ship@example.test',
            'shipping.api_password' => 'secret',
            'shipping.timeout_seconds' => 2,
            'shipping.connect_timeout_seconds' => 1,
            'shipping.token_ttl_seconds' => 86400,
            'shipping.token_expiry_margin_seconds' => 120,
            'shipping.auth_connect_retries' => 1,
        ]);

        Cache::flush();
    }

    public function test_get_wallet_balance_uses_existing_auth_and_parses_balance_amount(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'tok-1'], 200),
            'https://apiv2.shiprocket.in/v1/external/account/details/wallet-balance' => Http::response([
                'data' => ['balance_amount' => '9084.26'],
            ], 200),
        ]);

        $result = (new HttpShiprocketGateway)->getWalletBalance();

        $this->assertSame('available', $result->status);
        $this->assertSame('9084.26', $result->balanceAmount);

        Http::assertSent(function ($request): bool {
            return str_ends_with($request->url(), '/account/details/wallet-balance')
                && $request->hasHeader('Authorization', 'Bearer tok-1');
        });
    }

    public function test_get_wallet_balance_rejects_missing_balance_amount(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'tok-1'], 200),
            'https://apiv2.shiprocket.in/v1/external/account/details/wallet-balance' => Http::response([
                'data' => [],
            ], 200),
        ]);

        $result = (new HttpShiprocketGateway)->getWalletBalance();

        $this->assertSame('rejected', $result->status);
        $this->assertNull($result->balanceAmount);
        $this->assertSame('provider', $result->failureKind);
    }

    public function test_get_wallet_balance_maps_authentication_failure(): void
    {
        Http::fake([
            'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['message' => 'Invalid credentials'], 401),
        ]);

        $result = (new HttpShiprocketGateway)->getWalletBalance();

        $this->assertSame('failed', $result->status);
        $this->assertSame('auth', $result->failureKind);
        $this->assertNull($result->balanceAmount);
    }
}

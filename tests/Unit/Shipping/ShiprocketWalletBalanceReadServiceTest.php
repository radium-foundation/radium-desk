<?php

namespace Tests\Unit\Shipping;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Enums\ShiprocketWalletBalanceStatus;
use App\Models\User;
use App\Services\Shipping\ShiprocketWalletBalanceReadService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Shipping\Support\FakeShiprocketGateway;
use Tests\TestCase;

class ShiprocketWalletBalanceReadServiceTest extends TestCase
{
    use RefreshDatabase;

    private FakeShiprocketGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Cache::flush();

        $this->gateway = new FakeShiprocketGateway;
        $this->app->instance(ShiprocketGateway::class, $this->gateway);

        config([
            'shipping.enabled' => true,
            'shipping.provider' => 'shiprocket',
            'shipping.http_enabled' => true,
            'shipping.api_email' => 'ship@example.test',
            'shipping.api_password' => 'secret',
            'shipping.wallet_balance_ttl_seconds' => 900,
            'shipping.wallet_balance_low_threshold' => '1000.00',
        ]);
    }

    public function test_returns_available_balance_from_gateway(): void
    {
        $this->gateway->walletBalanceAmount = '9084.26';

        $presentation = app(ShiprocketWalletBalanceReadService::class)
            ->presentFor($this->hardwareOperator());

        $this->assertNotNull($presentation);
        $this->assertSame(ShiprocketWalletBalanceStatus::Available, $presentation->status);
        $this->assertSame('9084.26', $presentation->balanceAmount);
        $this->assertFalse($presentation->isStale);
        $this->assertSame(1, $this->gateway->walletBalanceCalls);
    }

    public function test_parses_decimal_balance_amount(): void
    {
        $this->gateway->walletBalanceAmount = '499.5';

        $presentation = app(ShiprocketWalletBalanceReadService::class)
            ->presentFor($this->hardwareOperator());

        $this->assertSame('499.50', $presentation?->balanceAmount);
    }

    public function test_zero_balance_is_not_unknown(): void
    {
        $this->gateway->walletBalanceAmount = '0.00';

        $presentation = app(ShiprocketWalletBalanceReadService::class)
            ->presentFor($this->hardwareOperator());

        $this->assertSame(ShiprocketWalletBalanceStatus::Zero, $presentation?->status);
        $this->assertSame('0.00', $presentation?->balanceAmount);
    }

    public function test_low_balance_uses_configured_threshold(): void
    {
        config(['shipping.wallet_balance_low_threshold' => '500.00']);
        $this->gateway->walletBalanceAmount = '499.99';

        $presentation = app(ShiprocketWalletBalanceReadService::class)
            ->presentFor($this->hardwareOperator());

        $this->assertSame(ShiprocketWalletBalanceStatus::Low, $presentation?->status);
        $this->assertSame('499.99', $presentation?->balanceAmount);
    }

    public function test_api_failure_without_cache_is_unknown_and_not_zero(): void
    {
        $this->gateway->mode = 'rejected';

        $presentation = app(ShiprocketWalletBalanceReadService::class)
            ->presentFor($this->hardwareOperator());

        $this->assertSame(ShiprocketWalletBalanceStatus::ProviderError, $presentation?->status);
        $this->assertNull($presentation?->balanceAmount);
        $this->assertFalse($presentation?->showsBalanceAmount() ?? true);
    }

    public function test_authentication_failure_is_auth_error_not_zero(): void
    {
        $this->gateway->mode = 'auth_failed';

        $presentation = app(ShiprocketWalletBalanceReadService::class)
            ->presentFor($this->hardwareOperator());

        $this->assertSame(ShiprocketWalletBalanceStatus::AuthError, $presentation?->status);
        $this->assertNull($presentation?->balanceAmount);
    }

    public function test_uses_cache_and_avoids_repeat_gateway_calls(): void
    {
        $service = app(ShiprocketWalletBalanceReadService::class);
        $operator = $this->hardwareOperator();

        $service->presentFor($operator);
        $service->presentFor($operator);

        $this->assertSame(1, $this->gateway->walletBalanceCalls);
    }

    public function test_expired_cache_refreshes_from_gateway(): void
    {
        config(['shipping.wallet_balance_ttl_seconds' => 60]);
        $service = app(ShiprocketWalletBalanceReadService::class);
        $operator = $this->hardwareOperator();

        Carbon::setTestNow('2026-09-20 10:00:00');
        $service->presentFor($operator);

        Carbon::setTestNow('2026-09-20 10:05:00');
        $this->gateway->walletBalanceAmount = '7500.00';
        $presentation = $service->presentFor($operator);

        $this->assertSame(2, $this->gateway->walletBalanceCalls);
        $this->assertSame('7500.00', $presentation?->balanceAmount);
        $this->assertFalse($presentation?->isStale);
    }

    public function test_failed_refresh_serves_stale_last_known_balance(): void
    {
        config(['shipping.wallet_balance_ttl_seconds' => 60]);
        $service = app(ShiprocketWalletBalanceReadService::class);
        $operator = $this->hardwareOperator();

        Carbon::setTestNow('2026-09-20 10:00:00');
        $this->gateway->walletBalanceAmount = '2500.00';
        $service->presentFor($operator);

        Carbon::setTestNow('2026-09-20 10:05:00');
        $this->gateway->mode = 'retryable';
        $presentation = $service->presentFor($operator);

        $this->assertSame('2500.00', $presentation?->balanceAmount);
        $this->assertTrue($presentation?->isStale);
        $this->assertSame(ShiprocketWalletBalanceStatus::Available, $presentation?->status);
    }

    public function test_permission_denied_returns_null(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $presentation = app(ShiprocketWalletBalanceReadService::class)->presentFor($user);

        $this->assertNull($presentation);
        $this->assertSame(0, $this->gateway->walletBalanceCalls);
    }

    public function test_unconfigured_provider_returns_unknown_without_gateway_call(): void
    {
        config(['shipping.enabled' => false]);

        $presentation = app(ShiprocketWalletBalanceReadService::class)
            ->presentFor($this->hardwareOperator());

        $this->assertSame(ShiprocketWalletBalanceStatus::Unknown, $presentation?->status);
        $this->assertNull($presentation?->balanceAmount);
        $this->assertSame(0, $this->gateway->walletBalanceCalls);
    }

    private function hardwareOperator(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }
}

<?php

namespace Tests\Unit\CentralWallet;

use App\CentralWallet\Infrastructure\Http\RoutingWalletMigrationSpokeClient;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class RoutingWalletMigrationSpokeClientTest extends TestCase
{
    private const RDIN_BASE = 'https://rdin-migration.test';

    private const BOX_BASE = 'https://box-migration.test';

    public function test_routes_acquire_lock_to_configured_spoke_by_site_code(): void
    {
        Http::fake([
            self::BOX_BASE.'/api/integrations/v1/wallet-migration-locks/acquire' => Http::response([
                'migration_operation_id' => 'op-1',
                'lock_status' => 'active',
                'users_wallet_id' => 2567,
            ], 201),
        ]);

        $client = new RoutingWalletMigrationSpokeClient([
            'radiumbox.com' => [
                'base_url' => self::BOX_BASE,
                'token' => 'box-token',
            ],
        ]);

        $result = $client->acquireLock(
            'op-1',
            'radiumbox.com',
            '499465',
            2567,
            '849.00',
            'REF-67363',
        );

        $this->assertSame(201, $result['status']);
        Http::assertSent(function ($request) {
            return $request->url() === self::BOX_BASE.'/api/integrations/v1/wallet-migration-locks/acquire'
                && $request['users_wallet_id'] === 2567;
        });
    }

    public function test_unknown_spoke_throws(): void
    {
        $client = new RoutingWalletMigrationSpokeClient([
            'rdservice.in' => [
                'base_url' => self::RDIN_BASE,
                'token' => 'rdin-token',
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('migration_spoke_not_configured:radiumbox.com');

        $client->acquireLock('op-1', 'radiumbox.com', '1', 1, '1.00', 'REF-1');
    }
}

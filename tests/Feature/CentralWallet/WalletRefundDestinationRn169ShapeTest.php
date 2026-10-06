<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REF-67372-shaped local probe only — does not execute production refund REF-67372.
 */
class WalletRefundDestinationRn169ShapeTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cw-rn169-shape-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'central_wallet.enabled' => true,
            'central_wallet.api_enabled' => true,
            'central_wallet.integration_token' => self::TOKEN,
            'central_wallet.wallet_refund_destination.enabled' => true,
            'central_wallet.customer_identity_ensure.enabled' => true,
            'central_wallet.historical_wallet_visibility.enabled' => true,
            'central_wallet.historical_wallet_visibility.contact_match_enabled' => false,
        ]);
    }

    public function test_rn169_shaped_request_resolves_existing_account_link(): void
    {
        $cwid = (string) Str::uuid();
        CentralWallet::query()->create(['id' => $cwid, 'status' => 'active']);

        CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $cwid,
            'site_code' => 'rdservice.net',
            'local_user_id' => 'rn169-local-user',
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'account_link',
            'linked_at' => now(),
            'created_by' => 'test:rn169-shape',
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer '.self::TOKEN,
            'X-Site-Code' => 'rdservice.net',
        ])->getJson('/api/central-wallet/v1/wallet-refund-destination?'.http_build_query([
            'site_code' => 'rdservice.net',
            'local_user_id' => 'rn169-local-user',
            'email' => 'ref67372-shape@example.com',
        ]))
            ->assertOk()
            ->assertJsonPath('central_wallet_id', $cwid)
            ->assertJsonPath('refund_destination_authorized', true)
            ->assertJsonPath('match_basis', 'account_link');
    }
}

<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Reliability\CentralWalletContractCatalog;
use App\CentralWallet\Reliability\WalletVisibilityContractValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CentralWalletContractV1Test extends TestCase
{
    use RefreshDatabase;

    public function test_contract_files_load_and_match_version(): void
    {
        $catalog = app(CentralWalletContractCatalog::class);
        $contract = $catalog->loadContract();
        $catalog->assertContractVersion($contract);

        $this->assertSame('1.0.0', $contract['contract_version']);
        $this->assertContains('UNAVAILABLE != ZERO', $contract['invariants']);
    }

    public function test_compatibility_matrix_requires_v1_for_all_spokes(): void
    {
        $matrix = app(CentralWalletContractCatalog::class)->loadCompatibilityMatrix();

        $this->assertSame('1.0.0', $matrix['central_wallet_contract_version']);
        $this->assertSame('1.0.0', $matrix['consumers']['rdservice.in']['requires_contract']);
        $this->assertSame('1.0.0', $matrix['consumers']['radiumbox.com']['requires_contract']);
        $this->assertSame('1.0.0', $matrix['consumers']['rdservice.net']['requires_contract']);
    }

    public function test_wallet_visibility_route_is_required_by_contract(): void
    {
        $catalog = app(CentralWalletContractCatalog::class);

        $this->assertContains(
            'central-wallet.wallet-visibility.show',
            $catalog->requiredProviderRouteNames(),
        );
        $this->assertTrue(Route::has('central-wallet.wallet-visibility.show'));
    }

    public function test_fixture_success_bodies_validate(): void
    {
        $catalog = app(CentralWalletContractCatalog::class);
        $validator = app(WalletVisibilityContractValidator::class);
        $fixtures = $catalog->loadFixture('fixtures/wallet-visibility-responses.json');

        foreach ($fixtures as $name => $body) {
            $validator->validateSuccessBody($body);
            $this->assertIsString($name);
        }
    }

    public function test_authoritative_zero_is_distinct_from_unavailable(): void
    {
        $catalog = app(CentralWalletContractCatalog::class);
        $validator = app(WalletVisibilityContractValidator::class);
        $zero = $catalog->loadFixture('fixtures/wallet-visibility-responses.json')['authoritative_zero'];

        $validator->validateSuccessBody($zero);
        $this->assertTrue($validator->isAuthoritativeZero($zero));
    }

    public function test_contract_version_mismatch_fails_explicitly(): void
    {
        $catalog = app(CentralWalletContractCatalog::class);

        $this->expectException(\InvalidArgumentException::class);
        $catalog->assertContractVersion(['contract_version' => '9.9.9']);
    }
}

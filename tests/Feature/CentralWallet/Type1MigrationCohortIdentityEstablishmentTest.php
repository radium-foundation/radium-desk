<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\Type1MigrationCohortIdentityEstablishmentService;
use App\CentralWallet\Application\Type1MigrationCohortManifestLoader;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

class Type1MigrationCohortIdentityEstablishmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'central_wallet.type1_migration_cohort.identity_establishment_enabled' => true,
            'central_wallet.type1_migration_cohort.cohort_manifest_path' => $this->cohortManifestPath(),
        ]);
    }

    public function test_establishes_identity_for_cohort_customer_without_m2_otp(): void
    {
        $service = app(Type1MigrationCohortIdentityEstablishmentService::class);

        $result = $service->establish(
            siteCode: 'rdservice.in',
            localUserId: '126483',
            actorId: 'test',
        );

        $this->assertSame('created_customer', $result['status']);
        $this->assertSame('C', $result['identity_class']);
        $this->assertSame('IDENTITY_ESTABLISHED', $result['migration_identity_state']);
        $this->assertSame([278], $result['refund_ids']);
        $this->assertFalse($result['idempotent_replay']);
        $this->assertSame(1, CentralCustomer::query()->count());
        $this->assertSame(1, CentralWallet::query()->count());
        $this->assertSame(0, CentralWalletLedgerEntry::query()->count());
        $this->assertDatabaseHas('central_wallet_account_links', [
            'site_code' => 'rdservice.in',
            'local_user_id' => '126483',
            'verification_method' => 'owner_migration_cohort',
        ]);
        $this->assertDatabaseHas('central_customer_identity_credentials', [
            'credential_type' => CustomerIdentityCredentialType::MigrationCohortAnchor->value,
            'provider' => 'owner_migration_cohort',
        ]);
    }

    public function test_rejects_customer_outside_cohort(): void
    {
        $service = app(Type1MigrationCohortIdentityEstablishmentService::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not_in_type1_migration_cohort');

        $service->establish(
            siteCode: 'rdservice.in',
            localUserId: '999999',
            actorId: 'test',
        );
    }

    public function test_idempotent_replay_does_not_duplicate_customer_or_cwid(): void
    {
        $service = app(Type1MigrationCohortIdentityEstablishmentService::class);

        $first = $service->establish('rdservice.in', '126483', 'test');
        $second = $service->establish('rdservice.in', '126483', 'test');

        $this->assertSame('IDENTITY_ESTABLISHED', $first['migration_identity_state']);
        $this->assertSame('existing_link', $second['status']);
        $this->assertTrue($second['idempotent_replay']);
        $this->assertSame($first['desk_customer_id'], $second['desk_customer_id']);
        $this->assertSame($first['central_wallet_id'], $second['central_wallet_id']);
        $this->assertSame(1, CentralCustomer::query()->count());
        $this->assertSame(1, CentralWallet::query()->count());
    }

    public function test_manifest_loader_enforces_exact_cohort_size(): void
    {
        $loader = app(Type1MigrationCohortManifestLoader::class);
        $manifest = $loader->load($this->cohortManifestPath());

        $this->assertSame(50, count($manifest['customers']));
        $this->assertSame('26230.00', $manifest['amount']);
    }

    public function test_fails_closed_when_feature_disabled(): void
    {
        config(['central_wallet.type1_migration_cohort.identity_establishment_enabled' => false]);
        $service = app(Type1MigrationCohortIdentityEstablishmentService::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type1_migration_cohort_identity_disabled');

        $service->establish('rdservice.in', '126483', 'test');
    }

    public function test_m2_not_required_migration_state_is_establishment_required_before_run(): void
    {
        $loader = app(Type1MigrationCohortManifestLoader::class);
        $customer = $loader->findCustomer($loader->load($this->cohortManifestPath()), 'rdservice.in', '126483');
        $this->assertNotNull($customer);
        $this->assertSame(0, CentralWalletAccountLink::query()->count());
    }

    private function cohortManifestPath(): string
    {
        $source = storage_path('app/private/cw-type1-migration-cohort-p30-10-04.json');
        if (File::exists($source)) {
            return $source;
        }

        $fixture = base_path('tests/fixtures/cw-type1-migration-cohort-p30-10-04.json');
        if (File::exists($fixture)) {
            return $fixture;
        }

        $this->markTestSkipped('TYPE-1 cohort manifest fixture missing');
    }
}

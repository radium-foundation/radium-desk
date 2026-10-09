<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Reliability\CentralWalletManagedFileInventory;
use App\CentralWallet\Reliability\CentralWalletOverlayIntegrityVerifier;
use App\CentralWallet\Reliability\CentralWalletReleaseIdentityCalculator;
use App\CentralWallet\Reliability\CentralWalletRuntimeManifestBuilder;
use App\CentralWallet\Reliability\CentralWalletRuntimeManifestStore;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CentralWalletReleaseManifestHardeningTest extends TestCase
{
    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manifestPath = storage_path('framework/testing/runtime-manifest-hardening-'.uniqid('', true).'.json');
        $this->app->instance(
            CentralWalletRuntimeManifestStore::class,
            new CentralWalletRuntimeManifestStore($this->manifestPath),
        );
    }

    protected function tearDown(): void
    {
        if (is_file($this->manifestPath)) {
            unlink($this->manifestPath);
        }

        parent::tearDown();
    }

    public function test_same_source_state_produces_same_release_identity(): void
    {
        $builder = app(CentralWalletRuntimeManifestBuilder::class);
        $calculator = app(CentralWalletReleaseIdentityCalculator::class);

        $first = $builder->build(
            project: 'radium-desk',
            environment: 'testing',
            deploymentType: 'git',
            gitSha: 'abc1234567890abcdef1234567890abcdef123456',
            gitBranch: 'main',
            gitWorktreeClean: true,
        );

        $second = $builder->build(
            project: 'radium-desk',
            environment: 'testing',
            deploymentType: 'git',
            gitSha: 'abc1234567890abcdef1234567890abcdef123456',
            gitBranch: 'main',
            gitWorktreeClean: false,
        );

        $this->assertSame($first['release_identity'], $second['release_identity']);
        $this->assertSame(
            $first['release_identity'],
            $calculator->calculateFromManifest($first),
        );
    }

    public function test_timestamp_changes_do_not_change_release_identity(): void
    {
        $builder = app(CentralWalletRuntimeManifestBuilder::class);
        $calculator = app(CentralWalletReleaseIdentityCalculator::class);

        $first = $builder->build(
            project: 'radium-desk',
            environment: 'testing',
            deploymentType: 'git',
            gitSha: 'abc1234567890abcdef1234567890abcdef123456',
        );

        sleep(1);

        $second = $builder->build(
            project: 'radium-desk',
            environment: 'testing',
            deploymentType: 'git',
            gitSha: 'abc1234567890abcdef1234567890abcdef123456',
        );

        $this->assertNotSame($first['audit']['generated_at'], $second['audit']['generated_at']);
        $this->assertSame(
            $calculator->calculateFromManifest($first),
            $calculator->calculateFromManifest($second),
        );
    }

    public function test_source_commit_change_changes_release_identity(): void
    {
        $builder = app(CentralWalletRuntimeManifestBuilder::class);

        $first = $builder->build(
            project: 'radium-desk',
            environment: 'testing',
            deploymentType: 'git',
            gitSha: 'abc1234567890abcdef1234567890abcdef123456',
        );

        $second = $builder->build(
            project: 'radium-desk',
            environment: 'testing',
            deploymentType: 'git',
            gitSha: 'def1234567890abcdef1234567890abcdef123456',
        );

        $this->assertNotSame($first['release_identity'], $second['release_identity']);
    }

    public function test_file_ordering_does_not_change_release_identity(): void
    {
        $calculator = app(CentralWalletReleaseIdentityCalculator::class);
        $inventory = app(CentralWalletManagedFileInventory::class);
        $files = $inventory->hashDeclaredFiles();

        $forward = $calculator->hashCanonicalPayload($calculator->buildCanonicalPayload(
            project: 'radium-desk',
            deploymentType: 'git',
            contractVersion: '1.0.0',
            releaseBranch: 'main',
            sourceIdentity: [
                'primary_source_commit' => 'abc1234567890abcdef1234567890abcdef123456',
                'source_components' => [['commit' => 'abc1234567890abcdef1234567890abcdef123456', 'label' => 'primary']],
            ],
            managedFiles: $files,
            inventoryVersion: '1.0.0',
        ));

        $reversed = $files;
        usort($reversed, fn (array $a, array $b): int => strcmp($b['path'], $a['path']));

        $backward = $calculator->hashCanonicalPayload($calculator->buildCanonicalPayload(
            project: 'radium-desk',
            deploymentType: 'git',
            contractVersion: '1.0.0',
            releaseBranch: 'main',
            sourceIdentity: [
                'primary_source_commit' => 'abc1234567890abcdef1234567890abcdef123456',
                'source_components' => [['commit' => 'abc1234567890abcdef1234567890abcdef123456', 'label' => 'primary']],
            ],
            managedFiles: $reversed,
            inventoryVersion: '1.0.0',
        ));

        $this->assertSame($forward, $backward);
    }

    public function test_missing_managed_file_is_detected(): void
    {
        $store = new CentralWalletRuntimeManifestStore($this->manifestPath);
        $manifest = app(CentralWalletRuntimeManifestBuilder::class)->build(
            project: 'radium-desk',
            environment: 'testing',
            deploymentType: 'git',
            gitSha: 'abc1234567890abcdef1234567890abcdef123456',
        );

        $managedFiles = $manifest['managed_files'];
        $managedFiles[0]['sha256'] = str_repeat('0', 64);
        $manifest['managed_files'] = $managedFiles;
        $manifest['runtime_files'] = $managedFiles;
        $manifest['release_identity'] = app(CentralWalletReleaseIdentityCalculator::class)->calculateFromManifest($manifest);
        $store->write($manifest);

        $report = app(CentralWalletOverlayIntegrityVerifier::class)->verify();

        $this->assertSame('FAIL', $report['status']);
        $this->assertNotEmpty($report['findings']['changed']);
    }

    public function test_orphan_overlay_file_is_detected(): void
    {
        $orphanPath = base_path('app/CentralWallet/Reliability/CentralWalletOrphanFixture.php');
        file_put_contents($orphanPath, "<?php\n");

        try {
            $store = new CentralWalletRuntimeManifestStore($this->manifestPath);
            $manifest = app(CentralWalletRuntimeManifestBuilder::class)->build(
                project: 'radium-desk',
                environment: 'testing',
                deploymentType: 'git',
                gitSha: 'abc1234567890abcdef1234567890abcdef123456',
            );
            $store->write($manifest);

            $report = app(CentralWalletOverlayIntegrityVerifier::class)->verify();

            $this->assertSame('FAIL', $report['status']);
            $this->assertContains(
                'app/CentralWallet/Reliability/CentralWalletOrphanFixture.php',
                array_column($report['findings']['orphan'], 'path'),
            );
        } finally {
            if (is_file($orphanPath)) {
                unlink($orphanPath);
            }
        }
    }

    public function test_malformed_manifest_fails_safely(): void
    {
        file_put_contents($this->manifestPath, '{not-json');

        $report = app(CentralWalletOverlayIntegrityVerifier::class)->verify(null);

        $this->assertSame('FAIL', $report['status']);
    }

    public function test_write_command_emits_v2_manifest_with_inventory_count(): void
    {
        $exit = Artisan::call('central-wallet:write-runtime-manifest', [
            '--deployment-type' => 'git',
            '--git-sha' => 'abc1234567890abcdef1234567890abcdef123456',
            '--git-branch' => 'main',
        ]);

        $this->assertSame(0, $exit);

        $manifest = (new CentralWalletRuntimeManifestStore($this->manifestPath))->read();

        $this->assertSame(2, $manifest['schema_version']);
        $this->assertSame('2.0.0', $manifest['manifest_contract_version']);
        $this->assertGreaterThan(20, $manifest['managed_file_count']);
        $this->assertNotEmpty($manifest['release_identity']);
    }
}

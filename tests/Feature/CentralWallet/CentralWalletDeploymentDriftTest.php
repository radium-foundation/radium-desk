<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Reliability\CentralWalletDeploymentDriftVerifier;
use App\CentralWallet\Reliability\CentralWalletRuntimeManifestBuilder;
use App\CentralWallet\Reliability\CentralWalletRuntimeManifestStore;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CentralWalletDeploymentDriftTest extends TestCase
{
    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manifestPath = storage_path('framework/testing/runtime-manifest-drift-'.uniqid('', true).'.json');
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

    public function test_drift_verifier_passes_when_manifest_matches_runtime_files(): void
    {
        Artisan::call('central-wallet:write-runtime-manifest', [
            '--deployment-type' => 'git',
            '--git-sha' => 'ae25e178',
        ]);

        $report = app(CentralWalletDeploymentDriftVerifier::class)->verifyProvider();

        $this->assertSame('PASS', $report['status']);
        $this->assertTrue(
            collect($report['checks'])->contains(
                fn (array $check): bool => ($check['id'] ?? '') === 'required_routes_registered'
                    && ($check['result'] ?? '') === 'PASS',
            ),
        );
    }

    public function test_drift_verifier_fails_when_runtime_file_hash_mismatch(): void
    {
        $builder = app(CentralWalletRuntimeManifestBuilder::class);
        $store = new CentralWalletRuntimeManifestStore($this->manifestPath);

        $manifest = $builder->build(
            project: 'radium-desk',
            environment: 'testing',
            deploymentType: 'overlay',
            runtimeFiles: ['routes/central_wallet.php'],
            sourceCommit: 'deadbeef',
        );

        $manifest['runtime_files'][0]['sha256'] = str_repeat('a', 64);
        $store->write($manifest);

        $report = app(CentralWalletDeploymentDriftVerifier::class)->verifyProvider();

        $this->assertSame('FAIL', $report['status']);
    }

    public function test_verify_deployment_drift_command_emits_json(): void
    {
        Artisan::call('central-wallet:write-runtime-manifest');

        $exit = Artisan::call('central-wallet:verify-deployment-drift', ['--json' => true]);

        $this->assertSame(0, $exit);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertSame('PASS', $decoded['status']);
    }
}

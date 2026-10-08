<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Reliability\CentralWalletRuntimeManifestBuilder;
use App\CentralWallet\Reliability\CentralWalletRuntimeManifestStore;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CentralWalletRuntimeManifestTest extends TestCase
{
    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manifestPath = storage_path('framework/testing/runtime-manifest-'.uniqid('', true).'.json');
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

    public function test_write_runtime_manifest_command_records_overlay_metadata(): void
    {
        $exit = Artisan::call('central-wallet:write-runtime-manifest', [
            '--deployment-type' => 'overlay',
            '--overlay-prompt-id' => 'RadiumDesk-P-04-10-117',
            '--source-commit' => 'ae25e178',
            '--rollback-reference' => 'storage/app/private/overlays/example',
        ]);

        $this->assertSame(0, $exit);

        $manifest = (new CentralWalletRuntimeManifestStore($this->manifestPath))->read();

        $this->assertSame('1.0.0', $manifest['contract_version']);
        $this->assertSame('overlay', $manifest['deployment_type']);
        $this->assertSame('RadiumDesk-P-04-10-117', $manifest['overlay']['prompt_id']);
        $this->assertSame('ae25e178', $manifest['overlay']['source_commit']);
        $this->assertNotEmpty($manifest['runtime_files']);
    }

    public function test_builder_hashes_default_runtime_files(): void
    {
        $manifest = app(CentralWalletRuntimeManifestBuilder::class)->build(
            project: 'radium-desk',
            environment: 'testing',
            deploymentType: 'git',
            runtimeFiles: app(CentralWalletRuntimeManifestBuilder::class)->defaultDeskRuntimeFiles(),
            gitSha: 'testsha',
        );

        $this->assertSame('1.0.0', $manifest['contract_version']);
        $this->assertTrue(collect($manifest['runtime_files'])->every(
            fn (array $entry): bool => ($entry['exists'] ?? false) === true && is_string($entry['sha256'] ?? null),
        ));
    }
}

<?php

namespace Tests\Unit\CentralWallet;

use App\CentralWallet\Reliability\CentralWalletManagedFileInventory;
use App\CentralWallet\Reliability\CentralWalletReleaseIdentityCalculator;
use App\CentralWallet\Reliability\CentralWalletRuntimeManifestStore;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CentralWalletManagedFileInventoryProfileTest extends TestCase
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

    public function test_default_inventory_uses_reliability_profile(): void
    {
        config(['central_wallet.release_gate.managed_file_inventory.path' => null]);

        $inventory = app(CentralWalletManagedFileInventory::class);

        $this->assertStringEndsWith(
            CentralWalletManagedFileInventory::INVENTORY_RELATIVE_PATH,
            $inventory->path(),
        );

        $reliability = json_decode(
            (string) file_get_contents(base_path(CentralWalletManagedFileInventory::INVENTORY_RELATIVE_PATH)),
            true,
        );

        $this->assertSame(count($reliability['files'] ?? []), count($inventory->declaredFiles()));
        $this->assertGreaterThan(count($this->walletDisplayFiles()), count($inventory->declaredFiles()));
    }

    public function test_configured_wallet_display_inventory_profile(): void
    {
        config([
            'central_wallet.release_gate.managed_file_inventory.path' => CentralWalletManagedFileInventory::WALLET_DISPLAY_INVENTORY_RELATIVE_PATH,
        ]);

        $inventory = app(CentralWalletManagedFileInventory::class);

        $this->assertSame(
            count($this->walletDisplayFiles()),
            count($inventory->declaredFiles()),
        );
        $this->assertSame('wallet-display-overlay', $inventory->load()['profile'] ?? null);

        $hashes = $inventory->hashDeclaredFiles();
        $this->assertTrue(collect($hashes)->every(fn (array $entry): bool => ($entry['exists'] ?? false) && is_string($entry['sha256'] ?? null)));
    }

    public function test_wallet_display_manifest_write_and_overlay_integrity_pass(): void
    {
        config([
            'central_wallet.release_gate.managed_file_inventory.path' => CentralWalletManagedFileInventory::WALLET_DISPLAY_INVENTORY_RELATIVE_PATH,
        ]);

        $inventory = app(CentralWalletManagedFileInventory::class);
        $expectedCount = count($this->walletDisplayFiles());

        $exit = Artisan::call('central-wallet:write-runtime-manifest', [
            '--deployment-type' => 'git',
            '--git-sha' => 'ea54492ba796868e657820a497c7b25ac544c87b',
            '--overlay-prompt-id' => 'RadiumDesk-P-12-10-054',
        ]);

        $this->assertSame(0, $exit);

        $manifest = (new CentralWalletRuntimeManifestStore($this->manifestPath))->read();
        $this->assertSame($expectedCount, $manifest['managed_file_count'] ?? null);
        $this->assertSame($expectedCount, count($manifest['managed_files'] ?? []));
        $this->assertSame(
            app(CentralWalletReleaseIdentityCalculator::class)->calculateFromManifest($manifest),
            $manifest['release_identity'] ?? '',
        );

        $integrityExit = Artisan::call('central-wallet:verify-overlay-integrity', ['--json' => true]);
        $report = json_decode(Artisan::output(), true);

        $this->assertSame(0, $integrityExit);
        $this->assertSame('PASS', $report['status'] ?? null);
        $this->assertFalse($report['findings']['release_identity_mismatch'] ?? true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function walletDisplayFiles(): array
    {
        $decoded = json_decode(
            (string) file_get_contents(base_path(CentralWalletManagedFileInventory::WALLET_DISPLAY_INVENTORY_RELATIVE_PATH)),
            true,
        );

        return is_array($decoded['files'] ?? null) ? $decoded['files'] : [];
    }
}

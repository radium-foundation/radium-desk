<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Reliability\CentralWalletManagedFileInventory;
use App\CentralWallet\Reliability\CentralWalletOverlayCompatibilityVerifier;
use App\CentralWallet\Reliability\CentralWalletOverlayIntegrityVerifier;
use App\CentralWallet\Reliability\CentralWalletRuntimeManifestBuilder;
use App\CentralWallet\Reliability\CentralWalletRuntimeManifestStore;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Non-production deployment-readiness matrix (fixtures + local runtime only).
 */
final class CentralWalletDeploymentReadinessTest extends TestCase
{
    public function test_a_safe_release_baseline_matches_compatible_target(): void
    {
        if (! is_dir(base_path('contracts/central-wallet/v1/fixtures/overlay-baseline'))) {
            $this->markTestSkipped('overlay-baseline fixture not present on this project');
        }

        $report = app(CentralWalletOverlayCompatibilityVerifier::class)->compareProjectRoots(
            base_path('contracts/central-wallet/v1/fixtures/overlay-baseline'),
            base_path(),
        );

        $this->assertSame('PASS', $report['status']);
    }

    public function test_b_reduced_release_like_config_fails_against_baseline(): void
    {
        $releaseLike = base_path('contracts/central-wallet/v1/fixtures/overlay-release-like');
        if (! is_dir($releaseLike)) {
            $this->markTestSkipped('overlay-release-like fixture not present on this project');
        }

        $report = app(CentralWalletOverlayCompatibilityVerifier::class)->compareProjectRoots(
            base_path('contracts/central-wallet/v1/fixtures/overlay-baseline'),
            $releaseLike,
        );

        $this->assertSame('FAIL', $report['status']);
    }

    public function test_c_refactor_preserving_capabilities_passes_when_target_is_current_tree(): void
    {
        if (! is_dir(base_path('contracts/central-wallet/v1/fixtures/overlay-baseline'))) {
            $this->markTestSkipped('overlay-baseline fixture not present');
        }

        $report = app(CentralWalletOverlayCompatibilityVerifier::class)->compareProjectRoots(
            base_path('contracts/central-wallet/v1/fixtures/overlay-baseline'),
            base_path(),
        );

        $this->assertSame('PASS', $report['status']);
    }

    public function test_d_removed_config_path_fails(): void
    {
        $releaseLike = base_path('contracts/central-wallet/v1/fixtures/overlay-release-like');
        if (! is_dir($releaseLike)) {
            $this->markTestSkipped('overlay-release-like fixture not present');
        }

        $report = app(CentralWalletOverlayCompatibilityVerifier::class)->compareProjectRoots(
            base_path('contracts/central-wallet/v1/fixtures/overlay-baseline'),
            $releaseLike,
        );

        $failedConfig = array_filter(
            $report['checks'] ?? [],
            static fn (array $c): bool => ($c['result'] ?? '') === 'FAIL'
                && str_starts_with((string) ($c['id'] ?? ''), 'config_path_'),
        );

        $this->assertNotEmpty($failedConfig);
    }

    public function test_e_removed_provider_binding_fails(): void
    {
        $releaseLike = base_path('contracts/central-wallet/v1/fixtures/overlay-release-like');
        if (! is_dir($releaseLike)) {
            $this->markTestSkipped('overlay-release-like fixture not present');
        }

        $report = app(CentralWalletOverlayCompatibilityVerifier::class)->compareProjectRoots(
            base_path('contracts/central-wallet/v1/fixtures/overlay-baseline'),
            $releaseLike,
        );

        $failedBinding = array_filter(
            $report['checks'] ?? [],
            static fn (array $c): bool => ($c['result'] ?? '') === 'FAIL'
                && str_starts_with((string) ($c['id'] ?? ''), 'binding_'),
        );

        $this->assertNotEmpty($failedBinding);
    }

    public function test_g_orphan_overlay_fails_integrity_check(): void
    {
        $manifest = [
            'schema_version' => 2,
            'manifest_contract_version' => '2.0.0',
            'project' => config('central_wallet.release_gate.project_key', 'radium-desk'),
            'managed_files' => [],
            'release_identity' => str_repeat('a', 64),
            'source_identity' => ['primary_source_commit' => str_repeat('b', 40)],
        ];

        $report = app(CentralWalletOverlayIntegrityVerifier::class)->verify($manifest);

        $this->assertSame('FAIL', $report['status']);
    }

    public function test_h_manifest_hash_mismatch_fails_integrity(): void
    {
        $store = app(CentralWalletRuntimeManifestStore::class);
        $existing = $store->read();
        if (! is_array($existing) || ($existing['schema_version'] ?? 0) < 2) {
            $this->markTestSkipped('No v2 runtime manifest in test environment');
        }

        $tampered = $existing;
        $tampered['managed_files'] = array_map(static function (array $entry): array {
            $entry['sha256'] = str_repeat('0', 64);

            return $entry;
        }, $tampered['managed_files'] ?? []);

        $report = app(CentralWalletOverlayIntegrityVerifier::class)->verify($tampered);

        $this->assertSame('FAIL', $report['status']);
    }

    public function test_release_gate_pre_supports_baseline_root_env(): void
    {
        config([
            'central_wallet.release_gate.overlay_compatibility.enabled' => true,
            'central_wallet.release_gate.overlay_compatibility.baseline_root' => base_path('contracts/central-wallet/v1/fixtures/overlay-baseline'),
            'central_wallet.release_gate.overlay_compatibility.target_root' => base_path(),
        ]);

        $runnerClass = class_exists(\App\CentralWallet\Reliability\CentralWalletReleaseGateRunner::class)
            ? \App\CentralWallet\Reliability\CentralWalletReleaseGateRunner::class
            : \App\CentralWallet\Reliability\CentralWalletSpokeReleaseGateRunner::class;

        $report = app($runnerClass)->run('pre');

        $this->assertArrayHasKey('overlay_compatibility', $report['sections']);
    }
}

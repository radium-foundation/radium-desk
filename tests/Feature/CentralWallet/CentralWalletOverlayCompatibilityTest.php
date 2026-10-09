<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Reliability\CentralWalletOverlayCompatibilityVerifier;
use App\CentralWallet\Reliability\CentralWalletReleaseGateRunner;
use Tests\TestCase;

final class CentralWalletOverlayCompatibilityTest extends TestCase
{
    public function test_runtime_matches_production_dependency_baseline(): void
    {
        $baseline = base_path('contracts/central-wallet/v1/fixtures/overlay-baseline');
        $report = app(CentralWalletOverlayCompatibilityVerifier::class)->compareProjectRoots($baseline, base_path());

        $this->assertSame('PASS', $report['status']);
    }

    public function test_release_gate_includes_overlay_compatibility_section(): void
    {
        config([
            'central_wallet.release_gate.overlay_compatibility.enabled' => true,
            'central_wallet.release_gate.overlay_compatibility.baseline_root' => base_path('contracts/central-wallet/v1/fixtures/overlay-baseline'),
        ]);

        $report = app(CentralWalletReleaseGateRunner::class)->run('pre');

        $this->assertArrayHasKey('overlay_compatibility', $report['sections']);
        $this->assertArrayHasKey('production_dependency_closure', $report['sections']);
    }
}

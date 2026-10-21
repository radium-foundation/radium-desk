<?php

namespace Tests\Unit\Release;

use App\Services\Release\RipCheckpointManifestValidator;
use App\Services\Release\RipCheckpointIdPolicy;
use App\Services\Release\RipCheckpointStatusPolicy;
use App\Services\Release\RipCheckpointVerifier;
use App\Services\Release\RipPreDeployGate;
use InvalidArgumentException;

class RipPreDeployGateTest extends RipCheckpointTestCase
{
    public function test_passes_when_checkpoint_matches_release_sha(): void
    {
        $dir = $this->createMinimalCheckpoint();

        $gate = new RipPreDeployGate(new RipCheckpointVerifier(
            new RipCheckpointIdPolicy(),
            new RipCheckpointManifestValidator(new RipCheckpointIdPolicy()),
            new RipCheckpointStatusPolicy(),
        ));

        $result = $gate->assertProductionBaselineCheckpoint($dir, '7441de0d');
        $this->assertSame('RIP-211026-1200-radium-desk-test-fixture-7441de0', $result['checkpoint_id']);
    }

    public function test_fails_when_production_baseline_sha_mismatches(): void
    {
        $dir = $this->createMinimalCheckpoint();

        $gate = new RipPreDeployGate(new RipCheckpointVerifier(
            new RipCheckpointIdPolicy(),
            new RipCheckpointManifestValidator(new RipCheckpointIdPolicy()),
            new RipCheckpointStatusPolicy(),
        ));

        $this->expectException(InvalidArgumentException::class);
        $gate->assertProductionBaselineCheckpoint($dir, 'aaaaaaaa');
    }

    public function test_fails_when_deploy_target_sha_differs_from_production_baseline(): void
    {
        $dir = $this->createMinimalCheckpoint();

        $gate = new RipPreDeployGate(new RipCheckpointVerifier(
            new RipCheckpointIdPolicy(),
            new RipCheckpointManifestValidator(new RipCheckpointIdPolicy()),
            new RipCheckpointStatusPolicy(),
        ));

        $this->expectException(InvalidArgumentException::class);
        // Checkpoint is for 7441de0 (production); passing next deploy commit must fail.
        $gate->assertProductionBaselineCheckpoint($dir, 'dacbc7a');
    }
}

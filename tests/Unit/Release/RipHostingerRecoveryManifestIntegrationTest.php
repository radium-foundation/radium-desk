<?php

namespace Tests\Unit\Release;

use App\Services\Release\RipCheckpointIdPolicy;
use App\Services\Release\RipCheckpointManifestValidator;
use App\Services\Release\RipCheckpointStatusPolicy;
use App\Services\Release\RipCheckpointVerifier;
use App\Services\Release\RipHostingerSnapshotPolicy;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;

/**
 * P-14-26: builder output must pass the real manifest validator / verifier path.
 */
class RipHostingerRecoveryManifestIntegrationTest extends RipCheckpointTestCase
{
    private function verifier(): RipCheckpointVerifier
    {
        return new RipCheckpointVerifier(
            new RipCheckpointIdPolicy,
            new RipCheckpointManifestValidator(new RipCheckpointIdPolicy),
            new RipCheckpointStatusPolicy,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRecoveryBlockFromGateFixture(): array
    {
        $gatePath = base_path('tests/fixtures/hostinger_snapshot_gate_verified.json');
        $script = base_path('tools/lib/build_rip_hostinger_block.py');

        $result = Process::timeout(30)->run([
            'python3',
            $script,
            $gatePath,
            '--source-commit',
            '7441de0d65c110de9f8c6dd11fdb419c67931a9c',
            '--authorization-reference',
            'RadiumDesk-P-14-18',
        ]);

        $this->assertTrue($result->successful(), $result->errorOutput());

        /** @var array<string, mixed> $block */
        $block = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);

        return $block;
    }

    public function test_builder_recovery_block_passes_full_checkpoint_verification(): void
    {
        $block = $this->buildRecoveryBlockFromGateFixture();

        $this->assertArrayNotHasKey('authorization_reference', $block);

        $dir = $this->createMinimalCheckpoint(['hostinger_vps_recovery' => $block]);

        $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true);
        (new RipCheckpointManifestValidator(new RipCheckpointIdPolicy))
            ->validate($manifest, $dir);

        $outcome = $this->verifier()->verifyDirectory($dir);
        $this->assertTrue($outcome['checksum_ok']);
    }

    public function test_manifest_without_recovery_block_remains_valid(): void
    {
        $dir = $this->createMinimalCheckpoint();

        $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true);
        $this->assertArrayNotHasKey('hostinger_vps_recovery', $manifest);

        (new RipCheckpointManifestValidator(new RipCheckpointIdPolicy))
            ->validate($manifest, $dir);

        $this->assertTrue($this->verifier()->verifyDirectory($dir)['checksum_ok']);
    }

    public function test_policy_rejects_forbidden_authorization_reference_in_manifest(): void
    {
        $block = $this->buildRecoveryBlockFromGateFixture();
        $block['authorization_reference'] = 'must-not-appear';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('authorization_reference');

        (new RipHostingerSnapshotPolicy)->validateRecoveryBlock($block);
    }
}
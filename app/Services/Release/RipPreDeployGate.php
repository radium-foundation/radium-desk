<?php

namespace App\Services\Release;

use InvalidArgumentException;

class RipPreDeployGate
{
    public function __construct(
        private readonly RipCheckpointVerifier $verifier,
    ) {}

    /**
     * Verify a checkpoint is a valid recovery point for **current production** (not the next deploy commit).
     *
     * @param  string  $productionBuildShortSha  Value of production release.json "build" (7-char commit) before deploy
     * @return array{checkpoint_id: string, manifest: array<string, mixed>}
     */
    public function assertProductionBaselineCheckpoint(
        string $checkpointDirectory,
        string $productionBuildShortSha,
        ?string $expectedCheckpointId = null,
    ): array {
        $result = $this->verifier->verifyDirectory($checkpointDirectory, expectChecksumMatch: true);
        $manifest = $result['manifest'];
        $checkpointId = (string) $manifest['checkpoint_id'];

        if ($expectedCheckpointId !== null && $checkpointId !== $expectedCheckpointId) {
            throw new InvalidArgumentException('Checkpoint ID does not match RIP_PRE_DEPLOY_CHECKPOINT_ID.');
        }

        $manifestShort = strtolower((string) ($manifest['source_identity']['git_commit_short'] ?? ''));
        $expectedShort = strtolower(substr(trim($productionBuildShortSha), 0, 7));

        if ($manifestShort !== $expectedShort) {
            throw new InvalidArgumentException(
                'Checkpoint commit does not match the current production release.json build (pre-deploy baseline).',
            );
        }

        $suffix = strtolower(substr($checkpointId, -7));

        if ($suffix !== $expectedShort) {
            throw new InvalidArgumentException(
                'Checkpoint ID suffix does not match the current production release.json build (pre-deploy baseline).',
            );
        }

        return [
            'checkpoint_id' => $checkpointId,
            'manifest' => $manifest,
        ];
    }

    /**
     * @deprecated Use assertProductionBaselineCheckpoint() — name retained for internal callers during transition.
     *
     * @return array{checkpoint_id: string, manifest: array<string, mixed>}
     */
    public function assertPreDeployCheckpoint(
        string $checkpointDirectory,
        string $productionBuildShortSha,
        ?string $expectedCheckpointId = null,
    ): array {
        return $this->assertProductionBaselineCheckpoint(
            $checkpointDirectory,
            $productionBuildShortSha,
            $expectedCheckpointId,
        );
    }
}

<?php

namespace App\Services\Release;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;

class RipCheckpointVerifier
{
    public function __construct(
        private readonly RipCheckpointIdPolicy $idPolicy,
        private readonly RipCheckpointManifestValidator $manifestValidator,
        private readonly RipCheckpointStatusPolicy $statusPolicy,
    ) {}

    /**
     * @return array{manifest: array<string, mixed>, checksum_ok: bool}
     */
    public function verifyDirectory(string $checkpointDirectory, bool $expectChecksumMatch = true): array
    {
        $checkpointDirectory = rtrim($checkpointDirectory, '/');

        if (! is_dir($checkpointDirectory)) {
            throw new InvalidArgumentException("Checkpoint directory does not exist: {$checkpointDirectory}");
        }

        $manifestPath = $checkpointDirectory.'/manifest.json';
        $sumsPath = $checkpointDirectory.'/SHA256SUMS';

        if (! is_file($manifestPath)) {
            throw new InvalidArgumentException('manifest.json is missing.');
        }

        if (! is_file($sumsPath)) {
            throw new InvalidArgumentException('SHA256SUMS is missing.');
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        if (! is_array($manifest)) {
            throw new InvalidArgumentException('manifest.json is not valid JSON.');
        }

        $this->manifestValidator->validate($manifest, $checkpointDirectory);

        $dirName = basename($checkpointDirectory);
        $checkpointId = (string) ($manifest['checkpoint_id'] ?? '');

        if ($dirName !== $checkpointId) {
            throw new InvalidArgumentException('Directory name must equal manifest checkpoint_id.');
        }

        $this->idPolicy->assertCheckpointId($checkpointId);

        $checksumOk = $this->verifySha256Sums($checkpointDirectory, $sumsPath);

        if ($expectChecksumMatch && ! $checksumOk) {
            throw new RuntimeException('SHA256SUMS verification failed.');
        }

        $status = (string) ($manifest['status'] ?? RipCheckpointStatusPolicy::DEPLOYED);
        $this->statusPolicy->assertStatus($status);
        $this->statusPolicy->assertStatusTransitionRequirements($status, $manifest);

        return [
            'manifest' => $manifest,
            'checksum_ok' => $checksumOk,
        ];
    }

    public function verifySha256Sums(string $checkpointDirectory, ?string $sumsPath = null): bool
    {
        $sumsPath ??= $checkpointDirectory.'/SHA256SUMS';

        if (! is_file($sumsPath)) {
            return false;
        }

        $lines = file($sumsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            if (! preg_match('/^([0-9a-f]{64})\s+(.+)$/', trim($line), $matches)) {
                return false;
            }

            $expected = $matches[1];
            $relative = $matches[2];
            $file = $checkpointDirectory.'/'.$relative;

            if (! is_file($file)) {
                return false;
            }

            $actual = hash_file('sha256', $file);

            if (! hash_equals($expected, $actual)) {
                return false;
            }
        }

        return $lines !== [];
    }

    public function assertNotConflicting(string $parentDirectory, string $checkpointId): void
    {
        $target = rtrim($parentDirectory, '/').'/'.$checkpointId;

        if (File::exists($target)) {
            throw new InvalidArgumentException("Checkpoint already exists and must not be overwritten: {$target}");
        }
    }
}

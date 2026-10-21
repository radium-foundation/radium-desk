<?php

namespace App\Services\Release;

use InvalidArgumentException;

class RipCheckpointManifestValidator
{
    public function __construct(
        private readonly RipCheckpointIdPolicy $idPolicy,
    ) {}

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function validate(array $manifest, string $checkpointDirectory): void
    {
        $required = [
            'schema_version',
            'checkpoint_id',
            'status',
            'captured_at_utc',
            'project_key',
            'change_label',
            'source_identity',
            'integrity',
            'storage',
            'excludes',
            'database_policy',
            'central_wallet_overlay',
        ];

        foreach ($required as $key) {
            if (! array_key_exists($key, $manifest)) {
                throw new InvalidArgumentException("manifest.json missing required key: {$key}");
            }
        }

        if ((int) $manifest['schema_version'] !== 1) {
            throw new InvalidArgumentException('Unsupported manifest schema_version.');
        }

        if (($manifest['project_key'] ?? '') !== RipCheckpointIdPolicy::PROJECT_KEY) {
            throw new InvalidArgumentException('manifest project_key must be radium-desk.');
        }

        $checkpointId = (string) $manifest['checkpoint_id'];
        $this->idPolicy->assertCheckpointId($checkpointId);

        $short = (string) (($manifest['source_identity']['git_commit_short'] ?? '') ?: '');

        if ($short !== '' && $short !== substr($checkpointId, -7)) {
            throw new InvalidArgumentException('source_identity.git_commit_short must match checkpoint_id suffix.');
        }

        $archive = (string) ($manifest['application_archive']['filename'] ?? 'desk-application.tar.zst');
        $archivePath = $checkpointDirectory.'/'.$archive;

        if (! is_file($archivePath)) {
            throw new InvalidArgumentException("Application archive missing: {$archive}");
        }

        $restorePath = $checkpointDirectory.'/RESTORE.md';

        if (! is_file($restorePath)) {
            throw new InvalidArgumentException('RESTORE.md is missing.');
        }

        $integrity = $manifest['integrity'] ?? [];

        if (($integrity['sha256sums_file'] ?? '') !== 'SHA256SUMS') {
            throw new InvalidArgumentException('integrity.sha256sums_file must be SHA256SUMS.');
        }

        if (($integrity['verified_at_capture'] ?? false) !== true) {
            throw new InvalidArgumentException('integrity.verified_at_capture must be true for a usable checkpoint.');
        }

        $storage = $manifest['storage'] ?? [];

        if (($storage['class'] ?? '') === 'public_web') {
            throw new InvalidArgumentException('Public web storage class is not permitted for RIP checkpoints.');
        }
    }
}

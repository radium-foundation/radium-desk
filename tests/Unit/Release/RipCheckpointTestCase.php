<?php

namespace Tests\Unit\Release;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

abstract class RipCheckpointTestCase extends TestCase
{
    protected function tearDown(): void
    {
        if (isset($this->checkpointDir) && is_string($this->checkpointDir) && File::isDirectory($this->checkpointDir)) {
            File::deleteDirectory(dirname($this->checkpointDir));
        }

        parent::tearDown();
    }

    protected string $checkpointDir = '';

    /**
     * @param  array<string, mixed>  $manifestOverrides
     */
    protected function createMinimalCheckpoint(array $manifestOverrides = []): string
    {
        $id = 'RIP-211026-1200-radium-desk-test-fixture-7441de0';
        $parent = storage_path('framework/testing/rip-'.uniqid());
        $this->checkpointDir = $parent.'/'.$id;
        File::makeDirectory($this->checkpointDir, 0755, true);

        $archive = $this->checkpointDir.'/desk-application.tar.zst';
        file_put_contents($archive, 'test-archive-payload');

        $manifest = array_merge([
            'schema_version' => 1,
            'checkpoint_id' => $id,
            'status' => 'deployed',
            'captured_at_utc' => '2026-10-21T12:00:00Z',
            'project_key' => 'radium-desk',
            'change_label' => 'test-fixture',
            'capture_mode' => 'test-fixture',
            'source_identity' => [
                'git_remote' => 'github.com/radium-foundation/radium-desk',
                'git_branch' => 'main',
                'git_commit_short' => '7441de0',
                'git_commit_full' => '7441de0d65c110de9f8c6dd11fdb419c67931a9c',
            ],
            'application_archive' => [
                'filename' => 'desk-application.tar.zst',
                'bytes' => filesize($archive),
            ],
            'build_identity' => [],
            'excludes' => ['.env', 'database dumps'],
            'database_policy' => [
                'rollback_scope' => 'application_code_only',
                'forward_only_migrations' => true,
                'pending_migrations_at_capture' => '',
            ],
            'central_wallet_overlay' => [
                'required_for_cw_overlay_deploys' => true,
            ],
            'integrity' => [
                'sha256sums_file' => 'SHA256SUMS',
                'verified_at_capture' => true,
            ],
            'storage' => [
                'class' => 'kvm8_private_disk',
                'path' => 'storage/app/private/release-checkpoints/'.$id,
            ],
            'smoke_evidence' => null,
            'known_good_signoff' => null,
        ], $manifestOverrides);

        file_put_contents(
            $this->checkpointDir.'/manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
        );

        file_put_contents($this->checkpointDir.'/RESTORE.md', "# Restore\n");

        $hash = hash_file('sha256', $archive);
        file_put_contents($this->checkpointDir.'/SHA256SUMS', "{$hash}  desk-application.tar.zst\n");

        return $this->checkpointDir;
    }
}

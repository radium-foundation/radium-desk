<?php

namespace Tests\Unit\Release;

use App\Services\Release\RipCheckpointManifestValidator;
use App\Services\Release\RipCheckpointIdPolicy;
use App\Services\Release\RipCheckpointStatusPolicy;
use App\Services\Release\RipCheckpointVerifier;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class RipCheckpointVerifierTest extends RipCheckpointTestCase
{
    private function verifier(): RipCheckpointVerifier
    {
        return new RipCheckpointVerifier(
            new RipCheckpointIdPolicy(),
            new RipCheckpointManifestValidator(new RipCheckpointIdPolicy()),
            new RipCheckpointStatusPolicy(),
        );
    }

    public function test_verifies_valid_checkpoint(): void
    {
        $dir = $this->createMinimalCheckpoint();

        $result = $this->verifier()->verifyDirectory($dir);
        $this->assertTrue($result['checksum_ok']);
    }

    public function test_rejects_corrupted_checksum(): void
    {
        $dir = $this->createMinimalCheckpoint();

        file_put_contents($dir.'/SHA256SUMS', str_repeat('a', 64).'  desk-application.tar.zst'."\n");

        $this->expectException(\RuntimeException::class);
        $this->verifier()->verifyDirectory($dir);
    }

    public function test_rejects_known_good_without_signoff(): void
    {
        $dir = $this->createMinimalCheckpoint(['status' => 'known-good']);

        $this->expectException(InvalidArgumentException::class);
        $this->verifier()->verifyDirectory($dir);
    }

    public function test_assert_not_conflicting(): void
    {
        $dir = $this->createMinimalCheckpoint();
        $parent = dirname($dir);
        $id = 'RIP-211026-1200-radium-desk-test-fixture-7441de0';

        $this->expectException(InvalidArgumentException::class);
        $this->verifier()->assertNotConflicting($parent, $id);
    }

    public function test_manifest_excludes_secrets_from_required_fields(): void
    {
        $dir = $this->createMinimalCheckpoint();
        $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true);
        $excludes = implode(' ', $manifest['excludes'] ?? []);

        $this->assertStringContainsString('.env', $excludes);
        $this->assertStringNotContainsString('DB_PASSWORD', $excludes);
    }
}

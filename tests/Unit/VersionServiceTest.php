<?php

namespace Tests\Unit;

use App\Services\Release\GitReleaseInspector;
use App\Services\Release\ReleaseManifestStore;
use App\Services\VersionService;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Mockery;
use Tests\TestCase;

class VersionServiceTest extends TestCase
{
    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manifestPath = storage_path('framework/testing/release-'.uniqid('', true).'.json');
        config([
            'app.release_manifest' => $this->manifestPath,
            'app.version' => null,
            'app.release_date' => null,
            'app.name' => 'Radium Desk',
            'app.env' => 'testing',
        ]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->manifestPath)) {
            unlink($this->manifestPath);
        }

        parent::tearDown();
    }

    public function test_version_prefers_git_tag_when_available(): void
    {
        $git = Mockery::mock(GitReleaseInspector::class);
        $git->shouldReceive('latestSemverVersion')->andReturn('4.0.1');
        $git->shouldReceive('shortCommit')->andReturn('f6e9302');

        $service = new VersionService($git, new ReleaseManifestStore);

        $this->assertSame('4.0.1', $service->version());
        $this->assertSame('f6e9302', $service->build());
        $this->assertSame('Radium Desk v4.0.1', $service->applicationLabel());
        $this->assertSame('Build f6e9302', $service->buildLabel());
        $this->assertSame('v4.0.1', $service->shortVersionLabel());
    }

    public function test_version_prefers_release_manifest_over_live_git(): void
    {
        $this->writeManifest([
            'version' => '4.1.0',
            'tag' => 'v4.1.0',
            'build' => 'deadbee',
            'deployed_at' => '2026-07-28T12:00:00+00:00',
            'release_date' => '2026-07-28',
        ]);

        $git = Mockery::mock(GitReleaseInspector::class);
        $git->shouldReceive('latestSemverVersion')->andReturn('9.9.9');
        $git->shouldReceive('shortCommit')->andReturn('ffffff');

        $service = new VersionService($git, new ReleaseManifestStore);

        $this->assertSame('4.1.0', $service->version());
        $this->assertSame('deadbee', $service->build());
        $this->assertSame('2026-07-28T12:00:00+00:00', $service->deployedAt());
        $this->assertSame('2026-07-28', $service->releaseDate());
    }

    public function test_version_falls_back_to_changelog_when_git_unavailable(): void
    {
        $git = Mockery::mock(GitReleaseInspector::class);
        $git->shouldReceive('latestSemverVersion')->andReturn(null);
        $git->shouldReceive('shortCommit')->andReturn(null);

        $service = new VersionService($git, new ReleaseManifestStore);

        $this->assertSame('4.0.0', $service->version());
        $this->assertSame('2026-07-26', $service->releaseDate());
        $this->assertNull($service->build());
    }

    public function test_version_falls_back_to_app_version_when_git_and_changelog_unavailable(): void
    {
        $changelogPath = base_path('CHANGELOG.md');
        $original = (string) file_get_contents($changelogPath);
        file_put_contents($changelogPath, "# Changelog\n\n## Unversioned notes\n\n- Item\n");

        try {
            config(['app.version' => '3.2.1']);

            $git = Mockery::mock(GitReleaseInspector::class);
            $git->shouldReceive('latestSemverVersion')->andReturn(null);
            $git->shouldReceive('shortCommit')->andReturn(null);

            $service = new VersionService($git, new ReleaseManifestStore);

            $this->assertSame('3.2.1', $service->version());
        } finally {
            file_put_contents($changelogPath, $original);
        }
    }

    public function test_version_returns_unknown_when_all_sources_missing(): void
    {
        $changelogPath = base_path('CHANGELOG.md');
        $original = (string) file_get_contents($changelogPath);
        file_put_contents($changelogPath, "# Changelog\n");

        try {
            config(['app.version' => null]);

            $git = Mockery::mock(GitReleaseInspector::class);
            $git->shouldReceive('latestSemverVersion')->andReturn(null);
            $git->shouldReceive('shortCommit')->andReturn(null);

            $service = new VersionService($git, new ReleaseManifestStore);

            $this->assertSame(VersionService::UNKNOWN_VERSION, $service->version());
            $this->assertSame('Radium Desk Unknown', $service->applicationLabel());
            $this->assertNull($service->buildLabel());
        } finally {
            file_put_contents($changelogPath, $original);
        }
    }

    public function test_ignores_non_semver_git_tags_via_inspector(): void
    {
        Process::fake(function (PendingProcess $process) {
            $command = $this->pendingCommandLine($process);

            if (str_contains($command, 'tag')) {
                return Process::result("v3.0-hybrid-realtime\nv2.7-role-aware-kpi\nv1.0.0\n");
            }

            return Process::result("abc1234\n");
        });

        $service = new VersionService(new GitReleaseInspector, new ReleaseManifestStore);

        $this->assertSame('1.0.0', $service->version());
    }

    public function test_release_metadata_includes_build_and_deployed_at(): void
    {
        $this->writeManifest([
            'version' => '4.0.1',
            'tag' => 'v4.0.1',
            'build' => 'f6e9302',
            'deployed_at' => '2026-07-28T16:00:00+05:30',
            'release_date' => '2026-07-28',
        ]);

        $git = Mockery::mock(GitReleaseInspector::class);
        $git->shouldReceive('latestSemverVersion')->never();
        $git->shouldReceive('shortCommit')->never();

        $metadata = (new VersionService($git, new ReleaseManifestStore))->releaseMetadata();

        $this->assertSame('4.0.1', $metadata['version']);
        $this->assertSame('f6e9302', $metadata['build']);
        $this->assertSame('f6e9302', $metadata['git_commit']);
        $this->assertSame('2026-07-28T16:00:00+05:30', $metadata['deployed_at']);
        $this->assertSame('2026-07-28', $metadata['release_date']);
        $this->assertSame('testing', $metadata['environment']);
    }

    public function test_current_version_alias_matches_version(): void
    {
        $git = Mockery::mock(GitReleaseInspector::class);
        $git->shouldReceive('latestSemverVersion')->andReturn('4.0.0');
        $git->shouldReceive('shortCommit')->andReturn('abc1234');

        $service = new VersionService($git, new ReleaseManifestStore);

        $this->assertSame($service->version(), $service->currentVersion());
        $this->assertSame($service->build(), $service->gitCommitShort());
    }

    /**
     * @param  array{version: ?string, tag: ?string, build: ?string, deployed_at: ?string, release_date: ?string}  $manifest
     */
    private function writeManifest(array $manifest): void
    {
        (new ReleaseManifestStore)->write($manifest);
    }

    private function pendingCommandLine(PendingProcess $process): string
    {
        $property = new \ReflectionProperty($process, 'command');

        $command = $property->getValue($process);

        return is_array($command) ? implode(' ', $command) : (string) $command;
    }
}

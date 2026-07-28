<?php

namespace Tests\Feature;

use App\Services\Release\GitReleaseInspector;
use App\Services\Release\ReleaseManifestStore;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class ReleaseSnapshotCommandTest extends TestCase
{
    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manifestPath = storage_path('framework/testing/snapshot-'.uniqid('', true).'.json');
        config(['app.release_manifest' => $this->manifestPath]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->manifestPath)) {
            unlink($this->manifestPath);
        }

        parent::tearDown();
    }

    public function test_snapshot_command_writes_release_manifest_when_changelog_matches(): void
    {
        $git = Mockery::mock(GitReleaseInspector::class);
        $git->shouldReceive('latestSemverVersion')->once()->andReturn('4.0.0');
        $git->shouldReceive('shortCommit')->once()->andReturn('f6e9302');
        $this->app->instance(GitReleaseInspector::class, $git);

        $exit = Artisan::call('release:snapshot');

        $this->assertSame(0, $exit);

        $manifest = (new ReleaseManifestStore)->read();

        $this->assertSame('4.0.0', $manifest['version']);
        $this->assertSame('v4.0.0', $manifest['tag']);
        $this->assertSame('f6e9302', $manifest['build']);
        $this->assertNotNull($manifest['deployed_at']);
    }

    public function test_snapshot_command_fails_when_changelog_section_missing(): void
    {
        $git = Mockery::mock(GitReleaseInspector::class);
        $git->shouldReceive('latestSemverVersion')->once()->andReturn('4.0.2');
        $git->shouldReceive('shortCommit')->never();
        $this->app->instance(GitReleaseInspector::class, $git);

        $exit = Artisan::call('release:snapshot');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString(
            'Release notes for v4.0.2 are missing from CHANGELOG.md.',
            Artisan::output(),
        );
        $this->assertNull((new ReleaseManifestStore)->read());
        $this->assertFileDoesNotExist($this->manifestPath);
    }
}

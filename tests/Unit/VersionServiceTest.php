<?php

namespace Tests\Unit;

use App\Services\VersionService;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class VersionServiceTest extends TestCase
{
    public function test_current_version_uses_configured_application_version(): void
    {
        config(['app.version' => '9.8.7']);

        $service = new VersionService;

        $this->assertSame('9.8.7', $service->currentVersion());
    }

    public function test_current_version_falls_back_to_changelog_when_config_empty(): void
    {
        config(['app.version' => '']);

        $service = new VersionService;

        $this->assertSame('4.0.0', $service->currentVersion());
    }

    public function test_environment_returns_application_environment(): void
    {
        config(['app.env' => 'staging']);

        $service = new VersionService;

        $this->assertSame('staging', $service->environment());
    }

    public function test_release_date_returns_configured_value(): void
    {
        config(['app.release_date' => '2026-07-26']);

        $service = new VersionService;

        $this->assertSame('2026-07-26', $service->releaseDate());
    }

    public function test_release_date_falls_back_to_changelog_when_not_configured(): void
    {
        config(['app.release_date' => null]);

        $service = new VersionService;

        $this->assertSame('2026-07-26', $service->releaseDate());
    }

    public function test_git_commit_short_returns_null_when_git_directory_missing(): void
    {
        if (is_dir(base_path('.git'))) {
            $this->markTestSkipped('Requires a workspace without a .git directory.');
        }

        $service = new VersionService;

        $this->assertNull($service->gitCommitShort());
    }

    public function test_git_commit_short_returns_trimmed_hash_when_git_available(): void
    {
        if (! is_dir(base_path('.git'))) {
            $this->markTestSkipped('Requires a git repository.');
        }

        Process::fake([
            '*' => Process::result(output: "abc1234\n"),
        ]);

        $service = new VersionService;

        $this->assertSame('abc1234', $service->gitCommitShort());
    }

    public function test_git_commit_short_falls_back_to_filesystem_when_process_fails(): void
    {
        if (! is_dir(base_path('.git'))) {
            $this->markTestSkipped('Requires a git repository.');
        }

        Process::fake([
            '*' => Process::result(output: '', exitCode: 1),
        ]);

        $service = new VersionService;
        $commit = $service->gitCommitShort();

        $this->assertNotNull($commit);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{7}$/i', $commit);
    }

    public function test_application_label_includes_name_and_version(): void
    {
        config([
            'app.name' => 'Radium Desk',
            'app.version' => '4.0.0',
        ]);

        $service = new VersionService;

        $this->assertSame('Radium Desk v4.0.0', $service->applicationLabel());
        $this->assertSame('v4.0.0', $service->shortVersionLabel());
    }

    public function test_release_metadata_returns_expected_keys(): void
    {
        config([
            'app.version' => '4.0.0',
            'app.release_date' => '2026-07-26',
            'app.env' => 'testing',
        ]);

        Process::fake([
            '*' => Process::result(output: "deadbeef\n"),
        ]);

        $service = new VersionService;
        $metadata = $service->releaseMetadata();

        $this->assertSame('4.0.0', $metadata['version']);
        $this->assertSame('2026-07-26', $metadata['release_date']);
        $this->assertSame('testing', $metadata['environment']);
        $this->assertSame('deadbeef', $metadata['git_commit']);
    }
}

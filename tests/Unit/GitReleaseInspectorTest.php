<?php

namespace Tests\Unit;

use App\Services\Release\GitReleaseInspector;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class GitReleaseInspectorTest extends TestCase
{
    public function test_latest_semver_version_returns_highest_matching_tag(): void
    {
        Process::fake(function (PendingProcess $process) {
            $command = $this->commandLine($process);

            if (str_contains($command, 'rev-parse --git-dir')) {
                return Process::result(".git\n");
            }

            if (str_contains($command, 'tag')) {
                return Process::result("v4.0.1\nv4.0.0\nv1.0.0\n");
            }

            return Process::result('', exitCode: 1);
        });

        $version = app(GitReleaseInspector::class)->latestSemverVersion();

        $this->assertSame('4.0.1', $version);
    }

    public function test_latest_semver_version_returns_null_when_git_fails(): void
    {
        Process::fake(fn () => Process::result('', exitCode: 1));

        $this->assertNull(app(GitReleaseInspector::class)->latestSemverVersion());
    }

    public function test_latest_semver_version_works_when_git_metadata_comes_from_worktree_pointer(): void
    {
        Process::fake(function (PendingProcess $process) {
            $command = $this->commandLine($process);

            if (str_contains($command, 'rev-parse --git-dir')) {
                return Process::result("/tmp/example/.git/worktrees/deploy\n");
            }

            if (str_contains($command, 'tag')) {
                return Process::result("v4.0.46\nv4.0.45\n");
            }

            if (str_contains($command, 'rev-parse --short HEAD')) {
                return Process::result("06a2f6a\n");
            }

            return Process::result('', exitCode: 1);
        });

        $inspector = app(GitReleaseInspector::class);

        $this->assertSame('4.0.46', $inspector->latestSemverVersion());
        $this->assertSame('06a2f6a', $inspector->shortCommit());
    }

    public function test_git_repository_detection_uses_rev_parse_not_dot_git_directory(): void
    {
        Process::fake(function (PendingProcess $process) {
            $command = $this->commandLine($process);

            if (str_contains($command, 'rev-parse --git-dir')) {
                $this->assertFalse(
                    is_dir(base_path('.git')),
                    'Regression guard: linked worktrees use a .git file, not a directory.',
                );

                return Process::result(".git\n");
            }

            if (str_contains($command, 'tag')) {
                return Process::result("v4.0.46\n");
            }

            return Process::result('', exitCode: 1);
        });

        $this->assertSame('4.0.46', app(GitReleaseInspector::class)->latestSemverVersion());
    }

    private function commandLine(PendingProcess $process): string
    {
        $property = new \ReflectionProperty($process, 'command');
        $command = $property->getValue($process);

        return is_array($command) ? implode(' ', $command) : (string) $command;
    }
}

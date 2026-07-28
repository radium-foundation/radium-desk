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

    private function commandLine(PendingProcess $process): string
    {
        $property = new \ReflectionProperty($process, 'command');
        $command = $property->getValue($process);

        return is_array($command) ? implode(' ', $command) : (string) $command;
    }
}

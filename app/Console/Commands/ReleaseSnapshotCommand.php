<?php

namespace App\Console\Commands;

use App\Services\ChangelogService;
use App\Services\Release\GitReleaseInspector;
use App\Services\Release\ReleaseManifestStore;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class ReleaseSnapshotCommand extends Command
{
    protected $signature = 'release:snapshot';

    protected $description = 'Write deploy-time release metadata (version, build, deployed_at) for VersionService';

    public function handle(
        GitReleaseInspector $git,
        ReleaseManifestStore $manifestStore,
        ChangelogService $changelogService,
    ): int {
        $version = $git->latestSemverVersion();

        if ($version === null) {
            $this->error('No semver Git tag found for this release.');

            return SymfonyCommand::FAILURE;
        }

        if (! $changelogService->hasEntryForVersion($version)) {
            $this->error("Release notes for v{$version} are missing from CHANGELOG.md.");

            return SymfonyCommand::FAILURE;
        }

        $build = $git->shortCommit();

        $releaseDate = $this->configuredReleaseDate();

        $manifest = [
            'version' => $version,
            'tag' => 'v'.$version,
            'build' => $build,
            'deployed_at' => now()->toIso8601String(),
            'release_date' => $releaseDate,
        ];

        $manifestStore->write($manifest);

        $this->info(sprintf(
            'Release snapshot written to %s (version=%s, build=%s)',
            $manifestStore->path(),
            $manifest['version'] ?? 'null',
            $manifest['build'] ?? 'null',
        ));

        return SymfonyCommand::SUCCESS;
    }

    private function configuredReleaseDate(): ?string
    {
        $configured = config('app.release_date');

        if (! is_string($configured) || trim($configured) === '') {
            return null;
        }

        return trim($configured);
    }
}

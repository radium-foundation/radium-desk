<?php

namespace App\Console\Commands;

use App\Services\Release\GitReleaseInspector;
use App\Services\Release\ReleaseManifestStore;
use Illuminate\Console\Command;

class ReleaseSnapshotCommand extends Command
{
    protected $signature = 'release:snapshot';

    protected $description = 'Write deploy-time release metadata (version, build, deployed_at) for VersionService';

    public function handle(GitReleaseInspector $git, ReleaseManifestStore $manifestStore): int
    {
        $version = $git->latestSemverVersion();
        $build = $git->shortCommit();
        $releaseDate = $this->configuredReleaseDate();

        $manifest = [
            'version' => $version,
            'tag' => $version !== null ? 'v'.$version : null,
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

        return self::SUCCESS;
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

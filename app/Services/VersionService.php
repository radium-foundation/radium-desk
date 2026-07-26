<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

class VersionService
{
    public function currentVersion(): string
    {
        return (string) config('app.version', '0.0.0');
    }

    public function environment(): string
    {
        return (string) config('app.env', 'production');
    }

    public function releaseDate(): ?string
    {
        $releaseDate = config('app.release_date');

        if (! is_string($releaseDate) || $releaseDate === '') {
            return null;
        }

        return $releaseDate;
    }

    public function gitCommitShort(): ?string
    {
        if (! is_dir(base_path('.git'))) {
            return null;
        }

        $result = Process::path(base_path())
            ->timeout(3)
            ->run(['git', 'rev-parse', '--short', 'HEAD']);

        if (! $result->successful()) {
            return null;
        }

        $commit = trim($result->output());

        return $commit !== '' ? $commit : null;
    }

    public function applicationName(): string
    {
        return (string) config('app.name', 'Radium Desk');
    }

    public function applicationLabel(): string
    {
        return sprintf('%s v%s', $this->applicationName(), $this->currentVersion());
    }

    /**
     * @return array{
     *     version: string,
     *     release_date: string|null,
     *     environment: string,
     *     git_commit: string|null,
     * }
     */
    public function releaseMetadata(): array
    {
        return [
            'version' => $this->currentVersion(),
            'release_date' => $this->releaseDate(),
            'environment' => $this->environment(),
            'git_commit' => $this->gitCommitShort(),
        ];
    }
}

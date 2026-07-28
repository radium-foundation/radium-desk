<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

class VersionService
{
    public function currentVersion(): string
    {
        $configured = config('app.version');

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        return $this->versionFromChangelog() ?? '0.0.0';
    }

    public function environment(): string
    {
        return (string) config('app.env', 'production');
    }

    public function releaseDate(): ?string
    {
        $releaseDate = config('app.release_date');

        if (! is_string($releaseDate) || trim($releaseDate) === '') {
            return $this->releaseDateFromChangelog();
        }

        return trim($releaseDate);
    }

    public function gitCommitShort(): ?string
    {
        $fromProcess = $this->gitCommitFromProcess();

        if ($fromProcess !== null) {
            return $fromProcess;
        }

        return $this->gitCommitFromFilesystem();
    }

    public function applicationName(): string
    {
        $name = config('app.name');

        if (is_string($name) && trim($name) !== '') {
            return trim($name);
        }

        return 'Radium Desk';
    }

    public function applicationLabel(): string
    {
        return sprintf('%s v%s', $this->applicationName(), $this->currentVersion());
    }

    public function shortVersionLabel(): string
    {
        return 'v'.$this->currentVersion();
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

    private function gitCommitFromProcess(): ?string
    {
        if (! is_dir(base_path('.git'))) {
            return null;
        }

        try {
            $result = Process::path(base_path())
                ->timeout(3)
                ->run(['git', 'rev-parse', '--short', 'HEAD']);
        } catch (\Throwable) {
            return null;
        }

        if (! $result->successful()) {
            return null;
        }

        $commit = trim($result->output());

        return $commit !== '' ? $commit : null;
    }

    private function gitCommitFromFilesystem(): ?string
    {
        $gitDir = base_path('.git');

        if (! is_dir($gitDir)) {
            return null;
        }

        $headPath = $gitDir.DIRECTORY_SEPARATOR.'HEAD';

        if (! is_file($headPath)) {
            return null;
        }

        $head = trim((string) file_get_contents($headPath));

        if ($head === '') {
            return null;
        }

        if (str_starts_with($head, 'ref:')) {
            $ref = trim(substr($head, 4));
            $refPath = $gitDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $ref);

            if (! is_file($refPath)) {
                return null;
            }

            $head = trim((string) file_get_contents($refPath));
        }

        if (! preg_match('/^[0-9a-f]{7,40}$/i', $head)) {
            return null;
        }

        return substr($head, 0, 7);
    }

    private function versionFromChangelog(): ?string
    {
        $header = $this->latestChangelogHeader();

        if ($header === null) {
            return null;
        }

        if (preg_match('/^(\d+\.\d+\.\d+)\b/u', $header, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function releaseDateFromChangelog(): ?string
    {
        $header = $this->latestChangelogHeader();

        if ($header === null) {
            return null;
        }

        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/u', $header, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function latestChangelogHeader(): ?string
    {
        $path = base_path('CHANGELOG.md');

        if (! is_file($path)) {
            return null;
        }

        $content = (string) file_get_contents($path);
        $sections = preg_split('/\R##\s+/u', $content) ?: [];

        if (count($sections) < 2) {
            return null;
        }

        $lines = preg_split('/\R/u', trim((string) $sections[1])) ?: [];
        $header = trim((string) ($lines[0] ?? ''));

        return $header !== '' ? $header : null;
    }
}

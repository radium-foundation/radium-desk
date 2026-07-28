<?php

namespace App\Services;

use App\Services\Release\GitReleaseInspector;
use App\Services\Release\ReleaseManifestStore;

class VersionService
{
    public const UNKNOWN_VERSION = 'Unknown';

    /** @var array{version: string|null, tag: string|null, build: string|null, deployed_at: string|null, release_date: string|null}|null */
    private ?array $manifest = null;

    private bool $manifestLoaded = false;

    private ?string $resolvedVersion = null;

    private bool $versionResolved = false;

    public function __construct(
        private readonly GitReleaseInspector $git,
        private readonly ReleaseManifestStore $manifestStore,
    ) {}

    /**
     * Semantic version without a leading "v" (e.g. "4.0.1"), or "Unknown".
     */
    public function version(): string
    {
        if ($this->versionResolved) {
            return $this->resolvedVersion ?? self::UNKNOWN_VERSION;
        }

        $this->versionResolved = true;

        $fromManifest = $this->manifest()['version'] ?? null;
        if ($this->isPresent($fromManifest)) {
            return $this->resolvedVersion = $this->normalizeVersion((string) $fromManifest);
        }

        $fromGit = $this->git->latestSemverVersion();
        if ($this->isPresent($fromGit)) {
            return $this->resolvedVersion = $this->normalizeVersion($fromGit);
        }

        $fromChangelog = $this->versionFromChangelog();
        if ($this->isPresent($fromChangelog)) {
            return $this->resolvedVersion = $this->normalizeVersion($fromChangelog);
        }

        $fromConfig = config('app.version');
        if (is_string($fromConfig) && trim($fromConfig) !== '') {
            return $this->resolvedVersion = $this->normalizeVersion(trim($fromConfig));
        }

        return $this->resolvedVersion = self::UNKNOWN_VERSION;
    }

    /**
     * @deprecated Use version()
     */
    public function currentVersion(): string
    {
        return $this->version();
    }

    public function build(): ?string
    {
        $fromManifest = $this->manifest()['build'] ?? null;
        if ($this->isPresent($fromManifest)) {
            return (string) $fromManifest;
        }

        return $this->git->shortCommit();
    }

    /**
     * @deprecated Use build()
     */
    public function gitCommitShort(): ?string
    {
        return $this->build();
    }

    public function deployedAt(): ?string
    {
        $deployedAt = $this->manifest()['deployed_at'] ?? null;

        return $this->isPresent($deployedAt) ? (string) $deployedAt : null;
    }

    public function releaseDate(): ?string
    {
        $fromManifest = $this->manifest()['release_date'] ?? null;
        if ($this->isPresent($fromManifest)) {
            return (string) $fromManifest;
        }

        $fromConfig = config('app.release_date');
        if (is_string($fromConfig) && trim($fromConfig) !== '') {
            return trim($fromConfig);
        }

        return $this->releaseDateFromChangelog();
    }

    public function environment(): string
    {
        return (string) config('app.env', 'production');
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
        $version = $this->version();

        if ($version === self::UNKNOWN_VERSION) {
            return sprintf('%s %s', $this->applicationName(), $version);
        }

        return sprintf('%s v%s', $this->applicationName(), $version);
    }

    public function buildLabel(): ?string
    {
        $build = $this->build();

        return $this->isPresent($build) ? 'Build '.$build : null;
    }

    public function shortVersionLabel(): string
    {
        $version = $this->version();

        return $version === self::UNKNOWN_VERSION ? $version : 'v'.$version;
    }

    public function footerTitle(): string
    {
        $parts = [$this->applicationLabel()];

        if ($this->buildLabel() !== null) {
            $parts[] = $this->buildLabel();
        }

        if ($this->deployedAt() !== null) {
            $parts[] = 'Deployed '.$this->deployedAt();
        }

        return implode(' · ', $parts);
    }

    /**
     * @return array{
     *     version: string,
     *     build: string|null,
     *     deployed_at: string|null,
     *     release_date: string|null,
     *     environment: string,
     *     git_commit: string|null,
     * }
     */
    public function releaseMetadata(): array
    {
        $build = $this->build();

        return [
            'version' => $this->version(),
            'build' => $build,
            'deployed_at' => $this->deployedAt(),
            'release_date' => $this->releaseDate(),
            'environment' => $this->environment(),
            'git_commit' => $build,
        ];
    }

    /**
     * @return array{version: string|null, tag: string|null, build: string|null, deployed_at: string|null, release_date: string|null}
     */
    private function manifest(): array
    {
        if (! $this->manifestLoaded) {
            $this->manifestLoaded = true;
            $this->manifest = $this->manifestStore->read() ?? [
                'version' => null,
                'tag' => null,
                'build' => null,
                'deployed_at' => null,
                'release_date' => null,
            ];
        }

        return $this->manifest ?? [
            'version' => null,
            'tag' => null,
            'build' => null,
            'deployed_at' => null,
            'release_date' => null,
        ];
    }

    private function normalizeVersion(string $version): string
    {
        $version = trim($version);

        if (str_starts_with(strtolower($version), 'v') && preg_match('/^v(\d+\.\d+\.\d+)$/i', $version, $matches) === 1) {
            return $matches[1];
        }

        return $version;
    }

    private function isPresent(?string $value): bool
    {
        return is_string($value) && trim($value) !== '';
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

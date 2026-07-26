<?php

namespace App\Services;

class ChangelogService
{
    public function __construct(
        private readonly VersionService $versionService,
    ) {}

    public function path(): string
    {
        return base_path('CHANGELOG.md');
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    /**
     * @return list<array{
     *     title: string,
     *     items: list<string>,
     *     version: string|null,
     *     release_date: string|null,
     *     environment: string|null,
     *     git_commit: string|null,
     *     is_current: bool,
     * }>
     */
    public function entries(): array
    {
        if (! $this->exists()) {
            return [];
        }

        $content = (string) file_get_contents($this->path());
        $sections = preg_split('/\R##\s+/u', $content) ?: [];

        $entries = [];

        foreach ($sections as $index => $section) {
            if ($index === 0) {
                continue;
            }

            $lines = preg_split('/\R/u', trim($section)) ?: [];
            $header = array_shift($lines);

            if ($header === null || $header === '') {
                continue;
            }

            $parsedHeader = $this->parseSectionHeader($header);
            $items = [];

            foreach ($lines as $line) {
                $line = trim($line);

                if ($line === '' || ! str_starts_with($line, '-')) {
                    continue;
                }

                $items[] = ltrim($line, "- \t");
            }

            $entries[] = [
                'title' => $parsedHeader['title'],
                'items' => $items,
                'version' => $parsedHeader['version'],
                'release_date' => $parsedHeader['release_date'],
                'environment' => null,
                'git_commit' => null,
                'is_current' => false,
            ];
        }

        return $this->enrichEntries($entries);
    }

    /**
     * @param  list<array{
     *     title: string,
     *     items: list<string>,
     *     version: string|null,
     *     release_date: string|null,
     *     environment: string|null,
     *     git_commit: string|null,
     *     is_current: bool,
     * }>  $entries
     * @return list<array{
     *     title: string,
     *     items: list<string>,
     *     version: string|null,
     *     release_date: string|null,
     *     environment: string|null,
     *     git_commit: string|null,
     *     is_current: bool,
     * }>
     */
    private function enrichEntries(array $entries): array
    {
        if ($entries === []) {
            return [];
        }

        $currentVersion = $this->versionService->currentVersion();
        $currentMetadata = $this->versionService->releaseMetadata();
        $hasCurrentEntry = false;

        foreach ($entries as $index => $entry) {
            if ($entry['version'] === $currentVersion) {
                $entries[$index]['is_current'] = true;
                $entries[$index]['environment'] = $currentMetadata['environment'];
                $entries[$index]['git_commit'] = $currentMetadata['git_commit'];
                $hasCurrentEntry = true;

                if ($entries[$index]['release_date'] === null) {
                    $entries[$index]['release_date'] = $currentMetadata['release_date'];
                }

                continue;
            }

            if ($index === 0 && $entry['version'] === null) {
                $entries[$index]['version'] = $currentVersion;
                $entries[$index]['release_date'] = $entry['release_date'] ?? $currentMetadata['release_date'];
                $entries[$index]['environment'] = $currentMetadata['environment'];
                $entries[$index]['git_commit'] = $currentMetadata['git_commit'];
                $entries[$index]['is_current'] = true;
                $hasCurrentEntry = true;
            }
        }

        if (! $hasCurrentEntry) {
            $entries[0]['version'] = $entries[0]['version'] ?? $currentVersion;
            $entries[0]['release_date'] = $entries[0]['release_date'] ?? $currentMetadata['release_date'];
            $entries[0]['environment'] = $currentMetadata['environment'];
            $entries[0]['git_commit'] = $currentMetadata['git_commit'];
            $entries[0]['is_current'] = true;
        }

        return $entries;
    }

    /**
     * @return array{title: string, version: string|null, release_date: string|null}
     */
    private function parseSectionHeader(string $header): array
    {
        if (preg_match('/^(\d+\.\d+\.\d+)\s*[—\-]\s*(\d{4}-\d{2}-\d{2})\s*[—\-]\s*(.+)$/u', $header, $matches) === 1) {
            return [
                'version' => $matches[1],
                'release_date' => $matches[2],
                'title' => trim($matches[3]),
            ];
        }

        if (preg_match('/^(\d+\.\d+\.\d+)\s*[—\-]\s*(.+)$/u', $header, $matches) === 1) {
            return [
                'version' => $matches[1],
                'release_date' => null,
                'title' => trim($matches[2]),
            ];
        }

        return [
            'version' => null,
            'release_date' => null,
            'title' => $header,
        ];
    }
}

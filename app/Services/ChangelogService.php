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
    public function currentReleaseEntries(): array
    {
        return array_values(array_filter(
            $this->entries(),
            static fn (array $entry): bool => $entry['is_current'] === true,
        ));
    }

    public function missingReleaseNotesMessage(): string
    {
        return sprintf(
            'Release notes for v%s are not available.',
            $this->versionService->version(),
        );
    }

    public function hasEntryForVersion(string $version): bool
    {
        foreach ($this->parseEntriesFromFile() as $entry) {
            if ($entry['version'] === $version) {
                return true;
            }
        }

        return false;
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
    private function parseEntriesFromFile(): array
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

        return $entries;
    }

    public function entries(): array
    {
        return $this->enrichEntries($this->parseEntriesFromFile());
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

        foreach ($entries as $index => $entry) {
            if ($entry['version'] !== $currentVersion) {
                continue;
            }

            $entries[$index]['is_current'] = true;
            $entries[$index]['environment'] = $currentMetadata['environment'];
            $entries[$index]['git_commit'] = $currentMetadata['git_commit'];

            if ($entries[$index]['release_date'] === null) {
                $entries[$index]['release_date'] = $currentMetadata['release_date'];
            }
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

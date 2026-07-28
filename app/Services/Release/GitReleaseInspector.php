<?php

namespace App\Services\Release;

use Illuminate\Support\Facades\Process;

/**
 * Centralizes Git probe commands for release metadata.
 */
class GitReleaseInspector
{
    private const SEMVER_TAG_PATTERN = '/^v?(\d+\.\d+\.\d+)$/';

    public function latestSemverVersion(): ?string
    {
        if (! $this->gitDirectoryExists()) {
            return null;
        }

        $tags = $this->listTags();

        foreach ($tags as $tag) {
            $normalized = $this->normalizeSemverTag($tag);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    public function shortCommit(): ?string
    {
        if (! $this->gitDirectoryExists()) {
            return null;
        }

        $fromProcess = $this->shortCommitFromProcess();

        if ($fromProcess !== null) {
            return $fromProcess;
        }

        return $this->shortCommitFromFilesystem();
    }

    /**
     * @return list<string>
     */
    private function listTags(): array
    {
        try {
            $result = Process::path(base_path())
                ->timeout(5)
                ->run(['git', 'tag', '-l', '--sort=-v:refname']);
        } catch (\Throwable) {
            return [];
        }

        if (! $result->successful()) {
            return [];
        }

        $tags = preg_split('/\R/u', trim($result->output())) ?: [];

        return array_values(array_filter(array_map('trim', $tags), static fn (string $tag): bool => $tag !== ''));
    }

    private function shortCommitFromProcess(): ?string
    {
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

    private function shortCommitFromFilesystem(): ?string
    {
        $gitDir = base_path('.git');
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

    private function normalizeSemverTag(string $tag): ?string
    {
        if (preg_match(self::SEMVER_TAG_PATTERN, trim($tag), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function gitDirectoryExists(): bool
    {
        return is_dir(base_path('.git'));
    }
}

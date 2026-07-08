<?php

namespace App\Services;

class ChangelogService
{
    public function path(): string
    {
        return base_path('CHANGELOG.md');
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    /**
     * @return list<array{title: string, items: list<string>}>
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
            $title = array_shift($lines);

            if ($title === null || $title === '') {
                continue;
            }

            $items = [];

            foreach ($lines as $line) {
                $line = trim($line);

                if ($line === '' || ! str_starts_with($line, '-')) {
                    continue;
                }

                $items[] = ltrim($line, "- \t");
            }

            $entries[] = [
                'title' => $title,
                'items' => $items,
            ];
        }

        return $entries;
    }
}

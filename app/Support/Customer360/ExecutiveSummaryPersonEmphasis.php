<?php

namespace App\Support\Customer360;

/**
 * Escapes executive-summary text and wraps known people in semantic <strong> markup.
 */
final class ExecutiveSummaryPersonEmphasis
{
    /**
     * @param  list<string>  $names
     */
    public function emphasize(string $text, array $names): string
    {
        if ($text === '') {
            return '';
        }

        // Pre-built HTML communication blocks are already escaped.
        if (str_contains($text, 'c360-ira-comm')) {
            return $text;
        }

        $escaped = e($text);
        $names = $this->normalizeNames($names);

        foreach ($names as $name) {
            $needle = e($name);
            if ($needle === '') {
                continue;
            }

            $escaped = preg_replace(
                '/(?<![\w@])'.preg_quote($needle, '/').'(?![\w@])/u',
                '<strong class="c360-ira-person">'.$needle.'</strong>',
                $escaped,
            ) ?? $escaped;
        }

        return $escaped;
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function normalizeNames(array $names): array
    {
        $normalized = [];

        foreach ($names as $name) {
            $name = trim($name);
            if ($name === '' || strcasecmp($name, 'Customer') === 0) {
                continue;
            }
            $normalized[$name] = $name;
        }

        // Always allow IRA as an emphasized persona when present in copy.
        $normalized['IRA'] = 'IRA';

        $list = array_values($normalized);
        usort($list, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $list;
    }
}

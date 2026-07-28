<?php

namespace App\Support\Dashboard;

class TeamActivityDurationPresenter
{
    public function isDuration(string $value): bool
    {
        return $this->parts($value) !== [];
    }

    public function compact(string $value): string
    {
        $value = trim($value);

        if ($value === '' || $value === '—') {
            return $value;
        }

        $value = preg_replace('/\b(\d+)\s*(?:secs?|seconds?)\b/i', '$1s', $value) ?? $value;
        $value = preg_replace('/\b(\d+)\s*(?:mins?|minutes?)\b/i', '$1m', $value) ?? $value;
        $value = preg_replace('/\b(\d+)\s*(?:hrs?|hours?)\b/i', '$1h', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @return list<array{value: string, unit: string}>
     */
    public function parts(string $value): array
    {
        $compact = $this->compact($value);

        if ($compact === '' || $compact === '—') {
            return [];
        }

        preg_match_all('/(\d+)(h|m|s)/', $compact, $matches, PREG_SET_ORDER);

        $parts = [];

        foreach ($matches as $match) {
            $parts[] = [
                'value' => $match[1],
                'unit' => $match[2],
            ];
        }

        return $parts;
    }
}

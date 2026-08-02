<?php

namespace App\Console\Concerns;

/**
 * Hostinger-safe CLI lifetime cap (same pattern as Gmail sync).
 * Uses set_time_limit so a single artisan invocation cannot run unbounded.
 */
trait AppliesConfiguredCliTimeout
{
    protected function applyConfiguredCliTimeout(string $configKey, int $defaultSeconds): void
    {
        $timeoutSeconds = max(0, (int) config($configKey, $defaultSeconds));

        if ($timeoutSeconds === 0) {
            return;
        }

        set_time_limit($timeoutSeconds);
    }
}

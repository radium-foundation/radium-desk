<?php

namespace App\Services\Operations;

/**
 * Classifies automation execution failure messages for alerting.
 *
 * Terminal / idempotent outcomes are historical audit noise — they must not
 * drive Critical Alerts or Telegram. Ledger rows are never rewritten.
 */
final class AutomationFailureClassifier
{
    /**
     * Exact or substring matchers for terminal / idempotent failure messages.
     *
     * @var list<string>
     */
    private const TERMINAL_MESSAGE_MARKERS = [
        'service case is already closed',
        'waiting state is no longer active',
        'already closed - waiting cleared',
        'waiting already cleared',
    ];

    public function isTerminal(?string $errorMessage): bool
    {
        if ($errorMessage === null || trim($errorMessage) === '') {
            return false;
        }

        $normalized = mb_strtolower(trim($errorMessage));

        foreach (self::TERMINAL_MESSAGE_MARKERS as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }

    public function isOpen(?string $errorMessage): bool
    {
        return ! $this->isTerminal($errorMessage);
    }
}

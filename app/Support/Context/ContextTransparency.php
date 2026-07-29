<?php

namespace App\Support\Context;

use App\Data\Context\ContextBadge;
use App\Enums\ContextScope;

/**
 * Feature-flag gate for BR-03 Context Transparency.
 *
 * Phase 1: metadata only — no UI, query, or API behaviour changes.
 */
final class ContextTransparency
{
    public static function enabled(): bool
    {
        return (bool) config('context_transparency.enabled', false);
    }

    public static function badgeFor(ContextScope $scope, ?string $label = null): ?ContextBadge
    {
        if (! self::enabled()) {
            return null;
        }

        return ContextBadge::forScope($scope, $label);
    }
}

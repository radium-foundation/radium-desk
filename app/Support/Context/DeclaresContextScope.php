<?php

namespace App\Support\Context;

use App\Data\Context\ContextBadge;
use App\Enums\ContextScope;

/**
 * Mixin for presenters that declare a fixed ContextScope (BR-03 Phase 1).
 *
 * Requires the host class to implement contextScope(): ContextScope.
 */
trait DeclaresContextScope
{
    abstract public function contextScope(): ContextScope;

    public function contextBadge(): ?ContextBadge
    {
        if (! ContextTransparency::enabled()) {
            return null;
        }

        return ContextBadge::forScope($this->contextScope());
    }
}

<?php

namespace App\Contracts\Context;

use App\Data\Context\ContextBadge;
use App\Enums\ContextScope;

/**
 * Optional contract for presenters that declare their authoritative context (BR-03).
 *
 * Implementing this does not change rendering. contextBadge() returns null when
 * context transparency is disabled so callers can stay no-op in Phase 1.
 */
interface ProvidesContextScope
{
    public function contextScope(): ContextScope;

    public function contextBadge(): ?ContextBadge;
}

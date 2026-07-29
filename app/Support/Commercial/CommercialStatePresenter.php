<?php

namespace App\Support\Commercial;

use App\Contracts\Context\ProvidesContextScope;
use App\Data\Commercial\CommercialStateSnapshot;
use App\Enums\ContextScope;
use App\Support\Context\DeclaresContextScope;

/**
 * Formats CommercialStateSnapshot for Blade (BR-04).
 */
class CommercialStatePresenter implements ProvidesContextScope
{
    use DeclaresContextScope;

    public function contextScope(): ContextScope
    {
        return ContextScope::Case;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(CommercialStateSnapshot $snapshot): array
    {
        return $snapshot->toArray();
    }
}

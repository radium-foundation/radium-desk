<?php

namespace App\Contracts\Customer360;

use App\Data\Customer360\Intelligence\CaseIntelligenceSnapshot;

/**
 * Optional language/reasoning enhancer over a completed CaseIntelligenceSnapshot.
 *
 * Implementations may refine wording only. They must not alter business facts
 * (waiting party, payment, SLA, appointment, engineer, blockers, risks sources).
 */
interface CaseIntelligenceLanguageEnhancer
{
    public function name(): string;

    public function enhance(CaseIntelligenceSnapshot $snapshot): CaseIntelligenceSnapshot;
}

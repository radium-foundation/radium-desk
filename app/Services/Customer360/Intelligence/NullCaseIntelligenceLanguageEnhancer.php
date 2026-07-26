<?php

namespace App\Services\Customer360\Intelligence;

use App\Contracts\Customer360\CaseIntelligenceLanguageEnhancer;
use App\Data\Customer360\Intelligence\CaseIntelligenceSnapshot;

/**
 * Default enhancer — returns the deterministic snapshot unchanged.
 */
class NullCaseIntelligenceLanguageEnhancer implements CaseIntelligenceLanguageEnhancer
{
    public function name(): string
    {
        return 'null';
    }

    public function enhance(CaseIntelligenceSnapshot $snapshot): CaseIntelligenceSnapshot
    {
        return $snapshot;
    }
}

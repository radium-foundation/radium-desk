<?php

namespace App\Data\Customer360\Intelligence;

use Illuminate\Support\Carbon;

readonly class CaseIntelligenceBlocker
{
    /**
     * @param  list<string>  $evidenceRefs
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $party,
        public string $severity,
        public ?Carbon $since = null,
        public array $evidenceRefs = [],
        public ?string $clearsWhen = null,
        public ?string $explanation = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'party' => $this->party,
            'severity' => $this->severity,
            'since' => $this->since?->toIso8601String(),
            'evidence_refs' => $this->evidenceRefs,
            'clears_when' => $this->clearsWhen,
            'explanation' => $this->explanation,
        ];
    }
}

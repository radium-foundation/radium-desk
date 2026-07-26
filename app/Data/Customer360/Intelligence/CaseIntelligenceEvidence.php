<?php

namespace App\Data\Customer360\Intelligence;

use Illuminate\Support\Carbon;

readonly class CaseIntelligenceEvidence
{
    /**
     * @param  list<string>  $supportsFields
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $source,
        public string $tone,
        public ?Carbon $occurredAt = null,
        public ?string $timelineEventId = null,
        public array $supportsFields = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'source' => $this->source,
            'tone' => $this->tone,
            'occurred_at' => $this->occurredAt?->toIso8601String(),
            'timeline_event_id' => $this->timelineEventId,
            'supports_fields' => $this->supportsFields,
        ];
    }
}

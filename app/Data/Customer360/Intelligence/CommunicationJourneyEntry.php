<?php

namespace App\Data\Customer360\Intelligence;

use Illuminate\Support\Carbon;

readonly class CommunicationJourneyEntry
{
    public function __construct(
        public Carbon $occurredAt,
        public string $dateLabel,
        public string $narrative,
        public string $channel,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'occurred_at' => $this->occurredAt->toIso8601String(),
            'date_label' => $this->dateLabel,
            'narrative' => $this->narrative,
            'channel' => $this->channel,
        ];
    }
}

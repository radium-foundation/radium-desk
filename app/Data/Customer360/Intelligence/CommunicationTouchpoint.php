<?php

namespace App\Data\Customer360\Intelligence;

use Illuminate\Support\Carbon;

/**
 * One structured customer-communication touchpoint.
 */
readonly class CommunicationTouchpoint
{
    public function __construct(
        public string $channel,
        public Carbon $occurredAt,
        public string $direction,
        public ?string $actorName,
        public string $summary,
        public ?string $preview = null,
        public ?string $subject = null,
        public ?string $templateName = null,
        public ?string $language = null,
        public ?string $outcome = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'channel' => $this->channel,
            'occurred_at' => $this->occurredAt->toIso8601String(),
            'direction' => $this->direction,
            'actor_name' => $this->actorName,
            'summary' => $this->summary,
            'preview' => $this->preview,
            'subject' => $this->subject,
            'template_name' => $this->templateName,
            'language' => $this->language,
            'outcome' => $this->outcome,
        ];
    }
}

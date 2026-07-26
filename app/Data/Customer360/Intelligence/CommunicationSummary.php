<?php

namespace App\Data\Customer360\Intelligence;

use Illuminate\Support\Carbon;

/**
 * Structured communication intelligence for executive briefing / future AI wording.
 * Never contains raw email bodies or full WhatsApp threads.
 */
readonly class CommunicationSummary
{
    public const PREVIEW_MAX_CHARS = 100;

    public const EMAIL_PREVIEW_MIN_CHARS = 40;

    public const EMAIL_PREVIEW_MAX_CHARS = 80;

    public const JOURNEY_MAX_ENTRIES = 8;

    /**
     * @param  list<CommunicationJourneyEntry>  $communicationJourney
     * @param  list<string>  $channelsUsed
     * @param  list<string>  $agentsInvolved
     * @param  list<CommunicationTouchpoint>  $touchpoints
     * @param  list<string>  $briefingLines
     */
    public function __construct(
        public ?CommunicationTouchpoint $latestWhatsapp,
        public ?CommunicationTouchpoint $latestEmail,
        public ?CommunicationTouchpoint $latestCall,
        public array $communicationJourney,
        public ?CommunicationTouchpoint $customerLastReply,
        public ?CommunicationTouchpoint $ourLastContact,
        public array $channelsUsed,
        public array $agentsInvolved,
        public array $touchpoints,
        public ?string $briefingParagraph,
        public array $briefingLines = [],
        public ?Carbon $sinceLastCustomerReplyAt = null,
        public ?string $sinceLastCustomerReplyLabel = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->touchpoints === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'latest_whatsapp' => $this->latestWhatsapp?->toArray(),
            'latest_email' => $this->latestEmail?->toArray(),
            'latest_call' => $this->latestCall?->toArray(),
            'communication_journey' => array_map(
                fn (CommunicationJourneyEntry $entry): array => $entry->toArray(),
                $this->communicationJourney,
            ),
            'customer_last_reply' => $this->customerLastReply?->toArray(),
            'our_last_contact' => $this->ourLastContact?->toArray(),
            'channels_used' => $this->channelsUsed,
            'agents_involved' => $this->agentsInvolved,
            'briefing_paragraph' => $this->briefingParagraph,
            'briefing_lines' => $this->briefingLines,
            'since_last_customer_reply_at' => $this->sinceLastCustomerReplyAt?->toIso8601String(),
            'since_last_customer_reply_label' => $this->sinceLastCustomerReplyLabel,
            'touchpoint_count' => count($this->touchpoints),
        ];
    }
}

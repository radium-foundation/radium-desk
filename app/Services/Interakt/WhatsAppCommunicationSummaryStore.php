<?php

namespace App\Services\Interakt;

use App\Models\InteraktMessage;
use App\Models\WhatsAppCommunicationSummary;

class WhatsAppCommunicationSummaryStore
{
    public function __construct(
        private readonly WhatsAppCommunicationSummaryBuilder $builder,
    ) {}

    /**
     * @param  array<string, mixed>|null  $webhookPayload
     */
    public function refreshForMessage(InteraktMessage $message, ?array $webhookPayload = null): ?WhatsAppCommunicationSummary
    {
        if (! filled($message->customer_phone)) {
            return null;
        }

        return $this->refreshForPhone($message->customer_phone, $webhookPayload);
    }

    /**
     * @param  array<string, mixed>|null  $webhookPayload
     */
    public function refreshForPhone(string $customerPhone, ?array $webhookPayload = null): ?WhatsAppCommunicationSummary
    {
        $summary = $this->builder->buildForPhone($customerPhone, $webhookPayload);

        if ($summary === null) {
            WhatsAppCommunicationSummary::query()
                ->where('customer_phone', $customerPhone)
                ->delete();

            return null;
        }

        return WhatsAppCommunicationSummary::query()->updateOrCreate(
            ['customer_phone' => $customerPhone],
            $summary->only([
                'conversation_id',
                'interakt_customer_id',
                'conversation_status',
                'messages_exchanged_count',
                'unread_count',
                'last_sender',
                'last_template_name',
                'last_message_id',
                'last_delivery_status',
                'last_activity_at',
                'last_communication_at',
            ]),
        );
    }
}

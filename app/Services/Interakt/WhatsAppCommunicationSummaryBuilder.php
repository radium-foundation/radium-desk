<?php

namespace App\Services\Interakt;

use App\Enums\InteraktDeliveryStatus;
use App\Enums\InteraktMessageDirection;
use App\Enums\WhatsAppConversationStatus;
use App\Models\InteraktMessage;
use App\Models\WhatsAppCommunicationSummary;
use Illuminate\Support\Carbon;

class WhatsAppCommunicationSummaryBuilder
{
    public function __construct(
        private readonly InteraktWebhookPayloadParser $payloadParser,
    ) {}

    /**
     * @param  array<string, mixed>|null  $webhookPayload
     */
    public function buildForPhone(string $customerPhone, ?array $webhookPayload = null): ?WhatsAppCommunicationSummary
    {
        if (! filled($customerPhone)) {
            return null;
        }

        $messages = InteraktMessage::query()
            ->where('customer_phone', $customerPhone)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->get();

        if ($messages->isEmpty()) {
            return null;
        }

        $latest = $messages->first();
        $existing = WhatsAppCommunicationSummary::query()
            ->where('customer_phone', $customerPhone)
            ->first();

        $lastActivityAt = $this->resolveLastActivityAt($latest);
        $conversationStatus = $this->resolveConversationStatus($latest);
        $lastSender = $this->resolveLastSender($latest);

        return new WhatsAppCommunicationSummary([
            'customer_phone' => $customerPhone,
            'conversation_id' => $this->resolveConversationId($webhookPayload, $existing),
            'interakt_customer_id' => $this->resolveInteraktCustomerId($webhookPayload, $existing, $latest),
            'conversation_status' => $conversationStatus,
            'messages_exchanged_count' => $messages->count(),
            'unread_count' => $this->resolveUnreadCount($webhookPayload, $existing),
            'last_sender' => $lastSender,
            'last_template_name' => $latest->template_name,
            'last_message_id' => $latest->message_id,
            'last_delivery_status' => $latest->delivery_status?->value,
            'last_activity_at' => $lastActivityAt,
            'last_communication_at' => $lastActivityAt,
        ]);
    }

    private function resolveLastActivityAt(InteraktMessage $message): Carbon
    {
        return $message->sent_at
            ?? $message->delivered_at
            ?? $message->read_at
            ?? $message->created_at;
    }

    private function resolveConversationStatus(InteraktMessage $latest): WhatsAppConversationStatus
    {
        if ($latest->direction === InteraktMessageDirection::Outgoing
            && $latest->delivery_status === InteraktDeliveryStatus::Failed) {
            return WhatsAppConversationStatus::Failed;
        }

        if ($latest->direction === InteraktMessageDirection::Incoming) {
            return WhatsAppConversationStatus::WaitingForAgent;
        }

        return WhatsAppConversationStatus::WaitingForCustomer;
    }

    private function resolveLastSender(InteraktMessage $message): string
    {
        if ($message->direction === InteraktMessageDirection::Incoming) {
            return 'customer';
        }

        if (filled($message->template_name)) {
            return 'template';
        }

        return 'agent';
    }

    /**
     * @param  array<string, mixed>|null  $webhookPayload
     */
    private function resolveConversationId(?array $webhookPayload, ?WhatsAppCommunicationSummary $existing): ?string
    {
        if ($webhookPayload !== null) {
            $conversationId = $this->payloadParser->conversationId($webhookPayload);

            if ($conversationId !== null) {
                return $conversationId;
            }
        }

        return $existing?->conversation_id;
    }

    /**
     * @param  array<string, mixed>|null  $webhookPayload
     */
    private function resolveInteraktCustomerId(
        ?array $webhookPayload,
        ?WhatsAppCommunicationSummary $existing,
        InteraktMessage $latest,
    ): ?string {
        if ($webhookPayload !== null) {
            $customerId = $this->payloadParser->customerId($webhookPayload);

            if ($customerId !== null) {
                return $customerId;
            }
        }

        if ($existing?->interakt_customer_id !== null) {
            return $existing->interakt_customer_id;
        }

        $payloadCustomerId = data_get($latest->payload, 'data.customer.id');

        return is_scalar($payloadCustomerId) && trim((string) $payloadCustomerId) !== ''
            ? trim((string) $payloadCustomerId)
            : null;
    }

    /**
     * @param  array<string, mixed>|null  $webhookPayload
     */
    private function resolveUnreadCount(?array $webhookPayload, ?WhatsAppCommunicationSummary $existing): ?int
    {
        if ($webhookPayload !== null) {
            $unreadCount = $this->payloadParser->unreadCount($webhookPayload);

            if ($unreadCount !== null) {
                return $unreadCount;
            }
        }

        return $existing?->unread_count;
    }
}

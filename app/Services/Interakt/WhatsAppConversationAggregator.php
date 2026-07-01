<?php

namespace App\Services\Interakt;

use App\Data\WhatsAppConversationSnapshot;
use App\Enums\InteraktDeliveryStatus;
use App\Enums\InteraktMessageDirection;
use App\Enums\WhatsAppConversationStatus;
use App\Models\InteraktMessage;
use Illuminate\Support\Carbon;

class WhatsAppConversationAggregator
{
    public function forPhone(string $customerPhone): ?WhatsAppConversationSnapshot
    {
        if (! filled($customerPhone)) {
            return null;
        }

        $latest = InteraktMessage::query()
            ->where('customer_phone', $customerPhone)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->first();

        if ($latest === null) {
            return null;
        }

        $messagesExchangedCount = InteraktMessage::query()
            ->where('customer_phone', $customerPhone)
            ->count();

        return new WhatsAppConversationSnapshot(
            customerPhone: $customerPhone,
            messagesExchangedCount: $messagesExchangedCount,
            conversationStatus: $this->resolveConversationStatus($latest),
            lastSender: $this->resolveLastSender($latest),
            lastTemplateName: $latest->template_name,
            lastMessageId: $latest->message_id,
            lastActivityAt: $this->resolveLastActivityAt($latest),
            interaktCustomerId: $this->resolveInteraktCustomerId($latest),
            conversationId: $this->resolveConversationId($latest),
        );
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

    private function resolveInteraktCustomerId(InteraktMessage $message): ?string
    {
        if (filled($message->interakt_customer_id)) {
            return $message->interakt_customer_id;
        }

        $payloadCustomerId = data_get($message->payload, 'data.customer.id');

        return is_scalar($payloadCustomerId) && trim((string) $payloadCustomerId) !== ''
            ? trim((string) $payloadCustomerId)
            : null;
    }

    private function resolveConversationId(InteraktMessage $message): ?string
    {
        if (filled($message->conversation_id)) {
            return $message->conversation_id;
        }

        foreach ([
            'data.conversation.id',
            'data.message.conversation_id',
            'conversation.id',
        ] as $path) {
            $value = data_get($message->payload, $path);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }
}

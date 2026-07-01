<?php

namespace App\Services\Interakt;

use App\Data\WhatsAppConversationSnapshot;

class InteraktDeepLinkService
{
    public function conversationUrl(WhatsAppConversationSnapshot $snapshot): string
    {
        $conversationTemplate = (string) config('interakt.conversation_url_template');

        if ($conversationTemplate !== '') {
            return $this->applyTemplate($conversationTemplate, $snapshot);
        }

        if (filled($snapshot->interaktCustomerId)) {
            return $this->applyTemplate(
                (string) config('interakt.customer_profile_url_template'),
                $snapshot,
            );
        }

        return (string) config('interakt.app_url');
    }

    private function applyTemplate(string $template, WhatsAppConversationSnapshot $snapshot): string
    {
        $replacements = [
            '{app_url}' => rtrim((string) config('interakt.app_url'), '/'),
            '{customer_id}' => (string) ($snapshot->interaktCustomerId ?? ''),
            '{conversation_id}' => (string) ($snapshot->conversationId ?? ''),
            '{phone}' => $snapshot->customerPhone,
            '{message_id}' => (string) ($snapshot->lastMessageId ?? ''),
        ];

        $url = strtr($template, $replacements);

        return rtrim($url, '?&');
    }
}

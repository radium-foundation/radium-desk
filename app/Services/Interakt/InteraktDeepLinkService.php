<?php

namespace App\Services\Interakt;

use App\Models\WhatsAppCommunicationSummary;

class InteraktDeepLinkService
{
    public function conversationUrl(WhatsAppCommunicationSummary $summary): string
    {
        $conversationTemplate = (string) config('interakt.conversation_url_template');

        if ($conversationTemplate !== '') {
            return $this->applyTemplate($conversationTemplate, $summary);
        }

        if (filled($summary->interakt_customer_id)) {
            return $this->applyTemplate(
                (string) config('interakt.customer_profile_url_template'),
                $summary,
            );
        }

        return (string) config('interakt.app_url');
    }

    private function applyTemplate(string $template, WhatsAppCommunicationSummary $summary): string
    {
        $replacements = [
            '{app_url}' => rtrim((string) config('interakt.app_url'), '/'),
            '{customer_id}' => (string) ($summary->interakt_customer_id ?? ''),
            '{conversation_id}' => (string) ($summary->conversation_id ?? ''),
            '{phone}' => (string) $summary->customer_phone,
            '{message_id}' => (string) ($summary->last_message_id ?? ''),
        ];

        $url = strtr($template, $replacements);

        return rtrim($url, '?&');
    }
}

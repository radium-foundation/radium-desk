<?php

namespace App\Support\Customer360;

use App\Enums\InteraktMessageDirection;
use App\Models\Incident;
use App\Models\InteraktMessage;
use App\Models\User;
use App\Services\IncomingEmail\IncomingEmailConversationService;
use App\Services\Interakt\InteraktDeepLinkService;
use App\Services\Interakt\WhatsAppConversationAggregator;
use App\Support\AppDateFormatter;

class Customer360CommunicationSectionPresenter
{
    public function __construct(
        private readonly WhatsAppConversationAggregator $whatsAppAggregator,
        private readonly InteraktDeepLinkService $interaktDeepLinkService,
        private readonly IncomingEmailConversationService $emailConversationService,
    ) {}

    /**
     * @return array{
     *     customer_label: string,
     *     owner_label: string,
     *     channels: list<array<string, mixed>>,
     *     future_channels: list<array<string, mixed>>,
     * }
     */
    public function forIncident(Incident $incident, ?User $user): array
    {
        $incident->loadMissing(['order', 'assignee']);
        $order = $incident->order;
        $phone = trim((string) ($order?->customer_phone ?? ''));
        $email = trim((string) ($order?->customer_email ?? ''));
        $customerLabel = trim((string) ($order?->customer_name ?? '')) !== ''
            ? (string) $order->customer_name
            : ($email !== '' ? $email : ($phone !== '' ? $phone : 'Customer'));
        $ownerLabel = $incident->assignee?->name ?? 'Unassigned';

        return [
            'customer_label' => $customerLabel,
            'owner_label' => $ownerLabel,
            'channels' => [
                $this->whatsappChannel($incident, $phone, $customerLabel, $ownerLabel),
                $this->emailChannel($incident, $user, $email, $customerLabel, $ownerLabel),
            ],
            'future_channels' => [
                ['key' => 'calls', 'label' => 'Calls', 'status' => 'Coming later'],
                ['key' => 'sms', 'label' => 'SMS', 'status' => 'Coming later'],
                ['key' => 'ai_notes', 'label' => 'AI Notes', 'status' => 'Coming later'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function whatsappChannel(
        Incident $incident,
        string $phone,
        string $customerLabel,
        string $ownerLabel,
    ): array {
        $phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
        $waMeUrl = strlen($phoneDigits) >= 10
            ? 'https://wa.me/'.(str_starts_with($phoneDigits, '91') ? $phoneDigits : '91'.$phoneDigits)
            : null;

        $snapshot = filled($phone) ? $this->whatsAppAggregator->forPhone($phone) : null;
        $interaktUrl = $snapshot !== null
            ? $this->interaktDeepLinkService->conversationUrl($snapshot)
            : null;

        $lastInbound = $this->latestInteraktAt($phone, InteraktMessageDirection::Incoming);
        $lastOutbound = $this->latestInteraktAt($phone, InteraktMessageDirection::Outgoing);

        return [
            'key' => 'whatsapp',
            'label' => 'WhatsApp',
            'icon' => 'bi-whatsapp',
            'available' => $waMeUrl !== null || $interaktUrl !== null,
            'unavailable_reason' => $waMeUrl === null && $interaktUrl === null
                ? 'No phone number on this Service Case.'
                : null,
            'open_mode' => 'whatsapp_panel',
            'wa_me_url' => $waMeUrl,
            'interakt_url' => $interaktUrl,
            'customer_label' => $customerLabel,
            'owner_label' => $ownerLabel,
            'last_inbound_at' => $lastInbound,
            'last_outbound_at' => $lastOutbound,
            'last_inbound_label' => $this->formatStamp($lastInbound),
            'last_outbound_label' => $this->formatStamp($lastOutbound),
            'status_label' => $snapshot?->conversationStatus->label() ?? ($waMeUrl ? 'Ready' : 'Unavailable'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emailChannel(
        Incident $incident,
        ?User $user,
        string $email,
        string $customerLabel,
        string $ownerLabel,
    ): array {
        $thread = $user instanceof User
            ? $this->emailConversationService->headerMetaForIncident($incident, $user)
            : [
                'last_customer_email_at' => null,
                'last_outgoing_email_at' => null,
                'unread_inbound_count' => 0,
                'can_reply' => false,
            ];

        $lastInbound = $thread['last_customer_email_at'] ?? null;
        $lastOutbound = $thread['last_outgoing_email_at'] ?? null;

        return [
            'key' => 'email',
            'label' => 'Email',
            'icon' => 'bi-envelope',
            'available' => true,
            'unavailable_reason' => null,
            'open_mode' => 'email_workspace',
            'thread_url' => route('dashboard.service-cases.email-thread', $incident),
            'read_url' => route('dashboard.service-cases.email-thread.read', $incident),
            'customer_label' => $customerLabel !== '' ? $customerLabel : ($email !== '' ? $email : 'Customer'),
            'owner_label' => $ownerLabel,
            'last_inbound_at' => $lastInbound,
            'last_outbound_at' => $lastOutbound,
            'last_inbound_label' => $this->formatStamp($lastInbound),
            'last_outbound_label' => $this->formatStamp($lastOutbound),
            'unread_count' => (int) ($thread['unread_inbound_count'] ?? 0),
            'can_reply' => (bool) ($thread['can_reply'] ?? false),
            'status_label' => ((int) ($thread['unread_inbound_count'] ?? 0)) > 0 ? 'Unread' : 'Ready',
        ];
    }

    private function latestInteraktAt(string $phone, InteraktMessageDirection $direction): ?string
    {
        if ($phone === '') {
            return null;
        }

        $message = InteraktMessage::query()
            ->where('customer_phone', $phone)
            ->where('direction', $direction)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->first(['sent_at', 'delivered_at', 'read_at', 'created_at']);

        if ($message === null) {
            return null;
        }

        $at = $message->sent_at
            ?? $message->delivered_at
            ?? $message->read_at
            ?? $message->created_at;

        return $at?->toIso8601String();
    }

    private function formatStamp(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '—';
        }

        try {
            return AppDateFormatter::format(\Illuminate\Support\Carbon::parse($iso), 'd M, h:i A');
        } catch (\Throwable) {
            return '—';
        }
    }
}

<?php

namespace App\Services\Notifications;

use App\Enums\NotificationType;
use App\Models\Order;
use App\Services\Interakt\WhatsAppTemplateConfigurationResolver;
use App\Services\SystemSettingsService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

class NotificationChannelAvailabilityService
{
    public function __construct(
        private readonly SystemSettingsService $systemSettings,
        private readonly NotificationMailSender $mailSender,
        private readonly NotificationMailTemplateRegistry $mailTemplateRegistry,
        private readonly WhatsAppTemplateConfigurationResolver $whatsAppTemplateConfigurationResolver,
    ) {}

    /**
     * @return array{whatsapp: array<string, mixed>, email: array<string, mixed>}
     */
    public function forRequestSerialNumber(?Order $order): array
    {
        return [
            'whatsapp' => $this->assessWhatsApp($order),
            'email' => $this->assessEmail($order),
        ];
    }

    /**
     * @return array{available: bool, label: string, reason: ?string, fallback_note: ?string}
     */
    public function assessWhatsApp(?Order $order): array
    {
        if (! $this->systemSettings->getBool('notifications.whatsapp.enabled', false)) {
            return $this->unavailable('WhatsApp', 'WhatsApp notifications are disabled.', 'Email will still be used.');
        }

        if (! $this->systemSettings->getBool('whatsapp.api_enabled', false)) {
            return $this->unavailable('WhatsApp', 'WhatsApp API integration is disabled.', 'Email will still be used.');
        }

        if (! filled(Config::get('interakt.api_key'))) {
            return $this->unavailable('WhatsApp', 'Invalid Interakt token.', 'Email will still be used.');
        }

        try {
            $this->whatsAppTemplateConfigurationResolver->resolve(
                \App\Enums\WhatsAppTemplate::RequestSerialNumber,
            );
        } catch (\Throwable) {
            return $this->unavailable('WhatsApp', 'Request serial WhatsApp template is not configured.', 'Email will still be used.');
        }

        $phone = trim((string) ($order?->customer_phone ?? ''));

        if ($phone === '') {
            return $this->unavailable('WhatsApp', 'Customer phone number is not available.', 'Email will still be used.');
        }

        return $this->available('WhatsApp');
    }

    /**
     * @return array{available: bool, label: string, reason: ?string, fallback_note: ?string}
     */
    public function assessEmail(?Order $order): array
    {
        if (! $this->systemSettings->getBool('notifications.email.enabled', false)) {
            return $this->unavailable('Email', 'Email notifications disabled.', 'WhatsApp will still be used.');
        }

        if (! $this->systemSettings->getBool('email.api_enabled', false)) {
            return $this->unavailable('Email', 'Email API integration is disabled.', 'WhatsApp will still be used.');
        }

        if (! $this->mailSender->isEnabled()) {
            return $this->unavailable('Email', 'Email delivery is disabled.', 'WhatsApp will still be used.');
        }

        if ($this->mailTemplateRegistry->resolve(NotificationType::RequestSerialNumber) === null) {
            return $this->unavailable('Email', 'No email template is configured for this notification.', 'WhatsApp will still be used.');
        }

        $email = trim((string) ($order?->customer_email ?? ''));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->unavailable('Email', 'Customer email address is not available.', 'WhatsApp will still be used.');
        }

        return $this->available('Email');
    }

    /**
     * @return array{available: bool, label: string, reason: ?string, fallback_note: ?string}
     */
    private function available(string $label): array
    {
        return [
            'available' => true,
            'label' => $label,
            'reason' => null,
            'fallback_note' => null,
        ];
    }

    /**
     * @return array{available: bool, label: string, reason: ?string, fallback_note: ?string}
     */
    private function unavailable(string $label, string $reason, ?string $fallbackNote = null): array
    {
        return [
            'available' => false,
            'label' => $label,
            'reason' => $reason,
            'fallback_note' => $fallbackNote,
        ];
    }

    /**
     * @param  array{whatsapp: array<string, mixed>, email: array<string, mixed>}  $channels
     */
    public function hasDeliverableChannel(array $channels): bool
    {
        return ($channels['whatsapp']['available'] ?? false) === true
            || ($channels['email']['available'] ?? false) === true;
    }

    /**
     * @param  array{whatsapp: array<string, mixed>, email: array<string, mixed>}  $channels
     */
    public function unavailableReason(array $channels): ?string
    {
        if ($this->hasDeliverableChannel($channels)) {
            return null;
        }

        $reasons = collect($channels)
            ->map(fn (array $channel): ?string => $channel['reason'] ?? null)
            ->filter()
            ->unique()
            ->values();

        if ($reasons->isEmpty()) {
            return 'No notification channels are available.';
        }

        return $reasons->implode(' ');
    }

    /**
     * @param  array{whatsapp: array<string, mixed>, email: array<string, mixed>}  $channels
     */
    public function transportFailureMessage(array $channels, string $fallback = 'Transport unavailable'): string
    {
        foreach (['email', 'whatsapp'] as $key) {
            $reason = Str::lower((string) ($channels[$key]['reason'] ?? ''));

            if (Str::contains($reason, ['transport', 'disabled', 'delivery'])) {
                return (string) $channels[$key]['reason'];
            }
        }

        return $fallback;
    }
}

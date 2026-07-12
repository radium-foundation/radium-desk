<?php

namespace App\Services\CommunicationActions;

use App\Data\CommunicationActions\CommunicationActionDefinition;
use App\Enums\NotificationChannelType;
use App\Models\Order;
use App\Services\Notifications\NotificationChannelAvailabilityService;

class CommunicationActionAvailabilityService
{
    public function __construct(
        private readonly NotificationChannelAvailabilityService $channelAvailabilityService,
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function forDefinition(CommunicationActionDefinition $definition, ?Order $order): array
    {
        $channels = [];

        foreach ($definition->channels as $channel) {
            $channels[$channel->value] = match ($channel) {
                NotificationChannelType::WhatsApp => $definition->whatsappTemplate !== null
                    ? $this->channelAvailabilityService->assessWhatsApp(
                        $order,
                        $definition->whatsappTemplate,
                    )
                    : $this->unavailableChannel($channel),
                NotificationChannelType::Email => $this->channelAvailabilityService->assessEmailForNotificationType(
                    $order,
                    $definition->notificationType,
                ),
                default => $this->unavailableChannel($channel),
            };
        }

        return $channels;
    }

    /**
     * @param  array<string, array<string, mixed>>  $channels
     */
    public function hasDeliverableChannel(array $channels): bool
    {
        return $this->channelAvailabilityService->hasAnyAvailableChannel($channels);
    }

    /**
     * @param  array<string, array<string, mixed>>  $channels
     */
    public function unavailableReason(array $channels): ?string
    {
        return $this->channelAvailabilityService->unavailableReasonForChannels($channels);
    }

    /**
     * @return array{available: bool, label: string, reason: ?string, fallback_note: ?string}
     */
    private function unavailableChannel(NotificationChannelType $channel): array
    {
        return [
            'available' => false,
            'label' => $channel->label(),
            'reason' => "{$channel->label()} is not supported yet.",
            'fallback_note' => null,
        ];
    }
}

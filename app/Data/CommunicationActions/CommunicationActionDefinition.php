<?php

namespace App\Data\CommunicationActions;

use App\Enums\CommunicationActionExecutionMode;
use App\Enums\CommunicationActionKey;
use App\Enums\NotificationChannelType;
use App\Enums\NotificationType;
use App\Enums\WhatsAppTemplate;
use InvalidArgumentException;

readonly class CommunicationActionDefinition
{
    /**
     * @param  list<NotificationChannelType>  $channels
     * @param  list<string>  $allowedRoles
     * @param  array<string, CommunicationActionVariableDefinition>  $variables
     */
    public function __construct(
        public CommunicationActionKey $key,
        public string $name,
        public string $description,
        public string $icon,
        public array $channels,
        public array $allowedRoles,
        public NotificationType $notificationType,
        public ?WhatsAppTemplate $whatsappTemplate,
        public string $timelineLabel,
        public CommunicationActionExecutionMode $executionMode,
        public array $variables,
        public CommunicationActionAutomationMetadata $automation,
        public bool $allowedOnClosedIncident = false,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $key = CommunicationActionKey::tryFrom((string) ($config['key'] ?? ''));

        if ($key === null) {
            throw new InvalidArgumentException('Communication action key is invalid.');
        }

        $notificationType = NotificationType::tryFrom((string) ($config['notification_type'] ?? ''));

        if ($notificationType === null) {
            throw new InvalidArgumentException("Notification type is invalid for [{$key->value}].");
        }

        $whatsappTemplate = filled($config['whatsapp_template'] ?? null)
            ? WhatsAppTemplate::tryFrom((string) $config['whatsapp_template'])
            : null;

        $channels = collect($config['channels'] ?? [])
            ->map(fn (mixed $channel): ?NotificationChannelType => NotificationChannelType::tryFrom((string) $channel))
            ->filter()
            ->values()
            ->all();

        if ($channels === []) {
            throw new InvalidArgumentException("At least one channel is required for [{$key->value}].");
        }

        $variables = [];

        foreach ($config['variables'] ?? [] as $variableKey => $variableConfig) {
            if (! is_array($variableConfig)) {
                continue;
            }

            $variables[(string) $variableKey] = CommunicationActionVariableDefinition::fromConfig(
                (string) $variableKey,
                $variableConfig,
            );
        }

        $executionMode = CommunicationActionExecutionMode::tryFrom((string) ($config['execution_mode'] ?? 'manual'))
            ?? CommunicationActionExecutionMode::Manual;

        return new self(
            key: $key,
            name: (string) ($config['name'] ?? $key->label()),
            description: (string) ($config['description'] ?? ''),
            icon: (string) ($config['icon'] ?? 'bi-chat-dots'),
            channels: $channels,
            allowedRoles: array_values(array_map('strval', $config['roles'] ?? [])),
            notificationType: $notificationType,
            whatsappTemplate: $whatsappTemplate,
            timelineLabel: (string) ($config['timeline_label'] ?? $key->label()),
            executionMode: $executionMode,
            variables: $variables,
            automation: CommunicationActionAutomationMetadata::fromConfig($config['automation'] ?? []),
            allowedOnClosedIncident: filter_var(
                $config['allowed_on_closed_incident'] ?? false,
                FILTER_VALIDATE_BOOL,
            ),
        );
    }

    public function supportsChannel(NotificationChannelType $channel): bool
    {
        return in_array($channel, $this->channels, true);
    }
}

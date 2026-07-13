<?php

namespace App\Data\CommunicationActions;

use App\Enums\CommunicationActionExecutionMode;
use App\Enums\CommunicationActionTriggerSource;
use App\Enums\NotificationChannelType;
use App\Enums\WhatsAppTemplateTriggerSource;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Carbon;

readonly class CommunicationActionExecutionContext
{
    /**
     * @param  list<NotificationChannelType>  $eligibleChannels
     * @param  list<NotificationChannelType>  $selectedChannels
     * @param  array<string, mixed>  $resolvedVariables
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public CommunicationActionDefinition $action,
        public Incident $incident,
        public ?Order $customer,
        public ?User $operator,
        public CommunicationActionExecutionMode $executionMode,
        public array $eligibleChannels,
        public array $selectedChannels,
        public array $resolvedVariables,
        public DateTimeInterface $timestamp,
        public CommunicationActionTriggerSource $triggerSource,
        public array $metadata = [],
    ) {}

    public function actionKey(): string
    {
        return $this->action->key->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function operatorInput(): array
    {
        $input = $this->metadata['operator_input'] ?? [];

        return is_array($input) ? $input : [];
    }

    /**
     * @param  list<NotificationChannelType>  $eligibleChannels
     */
    public function withEligibleChannels(array $eligibleChannels): self
    {
        return new self(
            action: $this->action,
            incident: $this->incident,
            customer: $this->customer,
            operator: $this->operator,
            executionMode: $this->executionMode,
            eligibleChannels: $eligibleChannels,
            selectedChannels: $this->selectedChannels,
            resolvedVariables: $this->resolvedVariables,
            timestamp: $this->timestamp,
            triggerSource: $this->triggerSource,
            metadata: $this->metadata,
        );
    }

    /**
     * @param  list<NotificationChannelType>  $selectedChannels
     */
    public function withSelectedChannels(array $selectedChannels): self
    {
        return new self(
            action: $this->action,
            incident: $this->incident,
            customer: $this->customer,
            operator: $this->operator,
            executionMode: $this->executionMode,
            eligibleChannels: $this->eligibleChannels,
            selectedChannels: $selectedChannels,
            resolvedVariables: $this->resolvedVariables,
            timestamp: $this->timestamp,
            triggerSource: $this->triggerSource,
            metadata: $this->metadata,
        );
    }

    /**
     * @param  array<string, mixed>  $resolvedVariables
     */
    public function withResolvedVariables(array $resolvedVariables): self
    {
        return new self(
            action: $this->action,
            incident: $this->incident,
            customer: $this->customer,
            operator: $this->operator,
            executionMode: $this->executionMode,
            eligibleChannels: $this->eligibleChannels,
            selectedChannels: $this->selectedChannels,
            resolvedVariables: $resolvedVariables,
            timestamp: $this->timestamp,
            triggerSource: $this->triggerSource,
            metadata: $this->metadata,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return new self(
            action: $this->action,
            incident: $this->incident,
            customer: $this->customer,
            operator: $this->operator,
            executionMode: $this->executionMode,
            eligibleChannels: $this->eligibleChannels,
            selectedChannels: $this->selectedChannels,
            resolvedVariables: $this->resolvedVariables,
            timestamp: $this->timestamp,
            triggerSource: $this->triggerSource,
            metadata: array_merge($this->metadata, $metadata),
        );
    }

    /**
     * Metadata passed to NotificationDispatcher for communication actions.
     *
     * @return array<string, mixed>
     */
    public function toNotificationMetadata(): array
    {
        return [
            'source' => $this->notificationSource(),
            'communication_action_key' => $this->action->key->value,
            'communication_action_label' => $this->action->timelineLabel,
            'communication_action_trigger_source' => $this->triggerSource->value,
            'communication_action_execution_mode' => $this->executionMode->value,
            'trigger_source' => $this->whatsappTriggerSource()->value,
        ];
    }

    private function notificationSource(): string
    {
        return match ($this->triggerSource) {
            CommunicationActionTriggerSource::Customer360 => 'customer360',
            CommunicationActionTriggerSource::Workspace => 'workspace',
            CommunicationActionTriggerSource::Automation => 'automation',
            CommunicationActionTriggerSource::Ira => 'ira',
            CommunicationActionTriggerSource::Api => 'api',
            CommunicationActionTriggerSource::Manual => 'manual',
        };
    }

    private function whatsappTriggerSource(): WhatsAppTemplateTriggerSource
    {
        return match ($this->executionMode) {
            CommunicationActionExecutionMode::Automatic,
            CommunicationActionExecutionMode::SemiAutomatic => WhatsAppTemplateTriggerSource::Automation,
            CommunicationActionExecutionMode::Manual => match ($this->triggerSource) {
                CommunicationActionTriggerSource::Automation => WhatsAppTemplateTriggerSource::Automation,
                CommunicationActionTriggerSource::Ira => WhatsAppTemplateTriggerSource::Ira,
                default => WhatsAppTemplateTriggerSource::Manual,
            },
        };
    }

    /**
     * @param  array<string, mixed>  $operatorInput
     * @param  list<string>|null  $selectedChannelValues
     */
    public static function initial(
        CommunicationActionDefinition $action,
        Incident $incident,
        ?User $operator,
        CommunicationActionExecutionMode $executionMode,
        CommunicationActionTriggerSource $triggerSource,
        array $operatorInput = [],
        ?array $selectedChannelValues = null,
        ?DateTimeInterface $timestamp = null,
        array $metadata = [],
    ): self {
        $incident->loadMissing('order');

        $selectedChannels = collect($selectedChannelValues ?? [])
            ->map(fn (mixed $channel): ?NotificationChannelType => NotificationChannelType::tryFrom((string) $channel))
            ->filter()
            ->values()
            ->all();

        return new self(
            action: $action,
            incident: $incident,
            customer: $incident->order,
            operator: $operator,
            executionMode: $executionMode,
            eligibleChannels: [],
            selectedChannels: $selectedChannels,
            resolvedVariables: [],
            timestamp: $timestamp ?? Carbon::now(),
            triggerSource: $triggerSource,
            metadata: array_merge($metadata, [
                'operator_input' => $operatorInput,
            ]),
        );
    }
}

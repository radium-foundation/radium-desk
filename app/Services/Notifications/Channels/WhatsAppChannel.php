<?php

namespace App\Services\Notifications\Channels;

use App\Contracts\Notifications\NotificationChannel;
use App\Data\NotificationMessage;
use App\Data\NotificationResult;
use App\Enums\NotificationChannelType;
use App\Enums\NotificationType;
use App\Enums\WhatsAppTemplate;
use App\Enums\WhatsAppTemplateTriggerSource;
use App\Services\Interakt\WhatsAppAutomationDispatcher;
use App\Services\Interakt\WhatsAppTemplateConfigurationResolver;

class WhatsAppChannel implements NotificationChannel
{
    private const SKIPPED_TEMPLATE_MESSAGE = 'Skipped - Template not configured';

    public function __construct(
        private readonly WhatsAppAutomationDispatcher $automationDispatcher,
        private readonly WhatsAppTemplateConfigurationResolver $templateConfigurationResolver,
    ) {}

    public function supports(NotificationType $type): bool
    {
        return match ($type) {
            NotificationType::RequestSerialNumber,
            NotificationType::SupportAppointmentBooked => true,
        };
    }

    public function send(NotificationMessage $message): NotificationResult
    {
        $template = $this->resolveTemplate($message->type);

        if (! $this->isTemplateConfigured($template)) {
            return $this->skippedTemplateResult($message, $template);
        }

        $triggerSource = $this->resolveTriggerSource($message);

        $result = $this->automationDispatcher->dispatch(
            template: $template,
            incident: $message->incident,
            triggerSource: $triggerSource,
            actor: $message->actor,
            context: $message->metadata,
            request: $message->httpRequest,
        );

        if ($result->success) {
            return NotificationResult::success(
                channel: NotificationChannelType::WhatsApp,
                externalId: $result->dispatch?->interakt_message_id,
                message: $result->message,
                metadata: [
                    'dispatch_id' => $result->dispatch?->id,
                    'template_key' => $result->dispatch?->template_key,
                ],
            );
        }

        return NotificationResult::failure(
            channel: NotificationChannelType::WhatsApp,
            message: $result->message ?? 'Unable to send WhatsApp template.',
            retryable: true,
            metadata: [
                'dispatch_id' => $result->dispatch?->id,
                'template_key' => $result->dispatch?->template_key,
            ],
        );
    }

    private function resolveTemplate(NotificationType $type): WhatsAppTemplate
    {
        return match ($type) {
            NotificationType::RequestSerialNumber => WhatsAppTemplate::RequestSerialNumber,
            NotificationType::SupportAppointmentBooked => WhatsAppTemplate::SupportAppointmentBooked,
        };
    }

    private function resolveTriggerSource(NotificationMessage $message): WhatsAppTemplateTriggerSource
    {
        $raw = $message->metadata['trigger_source'] ?? null;

        if ($raw instanceof WhatsAppTemplateTriggerSource) {
            return $raw;
        }

        if (is_string($raw)) {
            return WhatsAppTemplateTriggerSource::tryFrom($raw)
                ?? WhatsAppTemplateTriggerSource::Manual;
        }

        return WhatsAppTemplateTriggerSource::Manual;
    }

    private function isTemplateConfigured(WhatsAppTemplate $template): bool
    {
        try {
            $this->templateConfigurationResolver->resolve($template);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    private function skippedTemplateResult(
        NotificationMessage $message,
        WhatsAppTemplate $template,
    ): NotificationResult {
        return NotificationResult::success(
            channel: NotificationChannelType::WhatsApp,
            message: self::SKIPPED_TEMPLATE_MESSAGE,
            metadata: [
                'notification_type' => $message->type->value,
                'incident_id' => $message->incident->id,
                'template_key' => $template->value,
                'status' => 'not_yet_configured',
            ],
        );
    }
}

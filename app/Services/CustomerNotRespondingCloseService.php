<?php

namespace App\Services;

use App\Data\NotificationDispatchResult;
use App\Data\NotificationMessage;
use App\Enums\NotificationChannelType;
use App\Enums\NotificationType;
use App\Enums\ServiceCaseCloseNotificationPreference;
use App\Enums\WhatsAppTemplate;
use App\Enums\WhatsAppTemplateTriggerSource;
use App\Models\Incident;
use App\Models\User;
use App\Services\Notifications\CloseCaseSmartDeliveryService;
use App\Services\Notifications\NotificationDeliverySummaryFormatter;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CustomerNotRespondingCloseService
{
    public const TEMPLATE_KEY = 'final_reminder_before_closure';

    public const TEMPLATE_LABEL = 'Final Reminder Before Closure';

    public function __construct(
        private readonly NotificationDispatcher $notificationDispatcher,
        private readonly CloseCaseSmartDeliveryService $smartDeliveryService,
        private readonly NotificationDeliverySummaryFormatter $deliverySummaryFormatter,
    ) {}

    public function dispatchFinalReminder(
        Incident $incident,
        User $actor,
        ServiceCaseCloseNotificationPreference $preference,
        ?Request $request = null,
    ): NotificationDispatchResult {
        $incident->loadMissing('order');

        $message = new NotificationMessage(
            type: NotificationType::FinalReminderBeforeClosure,
            customer: $incident->order,
            incident: $incident,
            template: WhatsAppTemplate::FinalReminderBeforeClosure->value,
            metadata: [
                'source' => 'cnr_close',
                'trigger_source' => WhatsAppTemplateTriggerSource::Manual->value,
            ],
            actor: $actor,
            httpRequest: $request,
        );

        if ($preference->usesSmartDelivery()) {
            return $this->smartDeliveryService->dispatch(
                NotificationType::FinalReminderBeforeClosure,
                $message,
            );
        }

        return $this->notificationDispatcher->send(
            NotificationType::FinalReminderBeforeClosure,
            $message,
            allowedChannels: $this->allowedChannels($preference),
        );
    }

    /**
     * @throws ValidationException
     */
    public function assertDispatchSucceeded(
        NotificationDispatchResult $result,
        ServiceCaseCloseNotificationPreference $preference,
    ): void {
        if ($preference->usesSmartDelivery()) {
            if (! $result->success) {
                throw ValidationException::withMessages([
                    'cnr_communication_preference' => $this->smartDeliveryService->formatOperatorResult($result)
                        ?: 'Unable to send the final reminder before closure.',
                ]);
            }

            return;
        }

        $failures = [];

        foreach ($this->allowedChannels($preference) as $channel) {
            $channelResult = collect($result->results)->first(
                fn ($entry) => $entry->channel === $channel,
            );

            if ($channelResult === null || $channelResult->isSkipped() || ! $channelResult->success) {
                $failures[] = $channel->value;
            }
        }

        if ($failures !== []) {
            throw ValidationException::withMessages([
                'cnr_communication_preference' => $this->deliverySummaryFormatter->formatOperatorResult($result)
                    ?: 'Unable to send the final reminder before closure.',
            ]);
        }
    }

    /**
     * @return list<NotificationChannelType>
     */
    private function allowedChannels(ServiceCaseCloseNotificationPreference $preference): array
    {
        return match ($preference) {
            ServiceCaseCloseNotificationPreference::WhatsApp => [NotificationChannelType::WhatsApp],
            ServiceCaseCloseNotificationPreference::Email => [NotificationChannelType::Email],
            ServiceCaseCloseNotificationPreference::Both => [
                NotificationChannelType::WhatsApp,
                NotificationChannelType::Email,
            ],
            ServiceCaseCloseNotificationPreference::SmartDelivery,
            ServiceCaseCloseNotificationPreference::No => [],
        };
    }
}

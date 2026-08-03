<?php

namespace App\Services\Notifications\Channels;

use App\Contracts\Notifications\NotificationChannel;
use App\Data\NotificationMessage;
use App\Data\NotificationResult;
use App\Enums\NotificationChannelType;
use App\Enums\NotificationType;
use App\Mail\NotificationMail;
use App\Services\CommunicationTemplates\CommunicationTemplateRuntimeService;
use App\Services\Notifications\NotificationCustomerContactResolver;
use App\Services\Notifications\NotificationMailSender;
use App\Services\Notifications\NotificationMailTemplateRegistry;
use App\Services\Operations\TeamMemberActivityService;

class EmailChannel implements NotificationChannel
{
    public function __construct(
        private readonly NotificationMailTemplateRegistry $templateRegistry,
        private readonly NotificationCustomerContactResolver $contactResolver,
        private readonly NotificationMailSender $mailSender,
        private readonly TeamMemberActivityService $activityService,
        private readonly CommunicationTemplateRuntimeService $runtime,
    ) {}

    public function supports(NotificationType $type): bool
    {
        return match ($type) {
            NotificationType::RequestSerialNumber,
            NotificationType::RequestCorrectSerial,
            NotificationType::CustomerWaitingFollowup,
            NotificationType::CallbackSchedule,
            NotificationType::FinalReminderBeforeClosure,
            NotificationType::SupportAppointmentBooked,
            NotificationType::ServiceCaseClosed,
            NotificationType::DriverInstallationGuide,
            NotificationType::ReviewRequest,
            NotificationType::RefundConfirmation,
            NotificationType::BuyRdService,
            NotificationType::BuyProduct => true,
            default => false,
        };
    }

    public function send(NotificationMessage $message): NotificationResult
    {
        $started = microtime(true);
        $metadata = [
            'notification_type' => $message->type->value,
            'incident_id' => $message->incident->id,
        ];

        if (! $this->mailSender->isEnabled()) {
            return NotificationResult::failure(
                channel: NotificationChannelType::Email,
                message: 'Email delivery is disabled. Enable MAIL_ENABLED and notifications.email.enabled.',
                retryable: false,
                metadata: array_merge($metadata, [
                    'status' => 'mail_disabled',
                ]),
            );
        }

        $recipientEmail = $this->contactResolver->resolveEmail($message->customer);

        if ($recipientEmail === null) {
            return NotificationResult::failure(
                channel: NotificationChannelType::Email,
                message: 'Customer email address is not available.',
                retryable: false,
                metadata: array_merge($metadata, [
                    'status' => 'missing_customer_email',
                ]),
            );
        }

        $definition = $this->templateRegistry->resolve($message->type);

        if ($definition === null) {
            return NotificationResult::failure(
                channel: NotificationChannelType::Email,
                message: 'No email template is configured for this notification.',
                retryable: false,
                metadata: array_merge($metadata, [
                    'status' => 'missing_template',
                ]),
            );
        }

        $rendered = $this->runtime->renderNotificationMessage($message);
        $subject = $rendered['subject'] !== ''
            ? $rendered['subject']
            : ($message->subject ?? $this->templateRegistry->subjectFor($message->type, $message));

        // Prefer pre-rendered HTML (store or blade fallback string) so both paths share one send.
        $sendResult = $this->mailSender->send(
            recipientEmail: $recipientEmail,
            mail: new NotificationMail(
                mailSubject: $subject,
                viewName: $definition->view,
                variables: $this->templateRegistry->variablesFor($message),
                htmlBody: $rendered['html'],
            ),
        );

        if (! $sendResult['success']) {
            $error = trim((string) ($sendResult['error'] ?? ''));
            $failureMessage = $error === ''
                ? 'Unable to send email notification.'
                : 'Unable to send email notification: '.$error;

            return NotificationResult::failure(
                channel: NotificationChannelType::Email,
                message: $failureMessage,
                retryable: true,
                metadata: array_merge($metadata, [
                    'status' => 'transport_failure',
                    'recipient_email' => $recipientEmail,
                    'template_view' => $definition->view,
                    'runtime_source' => $rendered['runtime_source'],
                    'used_fallback' => $rendered['used_fallback'],
                    'error' => $sendResult['error'],
                ]),
            );
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $this->runtime->recordSuccessfulSend(
            template: $rendered['template'],
            runtimeSource: $rendered['runtime_source'],
            actor: $message->actor,
            communicationType: $message->type->value,
            usedFallback: $rendered['used_fallback'],
            sendDurationMs: $durationMs,
        );

        if ($message->actor !== null) {
            $this->activityService->recordCustomerCommunication($message->actor);
        }

        return NotificationResult::success(
            channel: NotificationChannelType::Email,
            externalId: $sendResult['message_id'],
            message: 'Email notification sent successfully.',
            metadata: array_merge($metadata, [
                'status' => 'sent',
                'recipient_email' => $recipientEmail,
                'template_view' => $definition->view,
                'runtime_source' => $rendered['runtime_source'],
                'used_fallback' => $rendered['used_fallback'],
                'template_id' => $rendered['template']?->id,
                'template_version' => $rendered['version']?->version,
            ]),
        );
    }
}

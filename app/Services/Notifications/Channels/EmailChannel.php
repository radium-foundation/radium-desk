<?php

namespace App\Services\Notifications\Channels;

use App\Contracts\Notifications\NotificationChannel;
use App\Data\NotificationMessage;
use App\Data\NotificationResult;
use App\Enums\NotificationChannelType;
use App\Enums\NotificationType;
use App\Mail\NotificationMail;
use App\Services\Notifications\NotificationCustomerContactResolver;
use App\Services\Notifications\NotificationMailSender;
use App\Services\Notifications\NotificationMailTemplateRegistry;

class EmailChannel implements NotificationChannel
{
    public function __construct(
        private readonly NotificationMailTemplateRegistry $templateRegistry,
        private readonly NotificationCustomerContactResolver $contactResolver,
        private readonly NotificationMailSender $mailSender,
        private readonly \App\Services\Operations\TeamMemberActivityService $activityService,
    ) {}

    public function supports(NotificationType $type): bool
    {
        return match ($type) {
            NotificationType::RequestSerialNumber,
            NotificationType::CustomerWaitingFollowup,
            NotificationType::SupportAppointmentBooked => true,
        };
    }

    public function send(NotificationMessage $message): NotificationResult
    {
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

        $template = $this->templateRegistry->resolve($message->type);

        if ($template === null) {
            return NotificationResult::failure(
                channel: NotificationChannelType::Email,
                message: 'No email template is configured for this notification.',
                retryable: false,
                metadata: array_merge($metadata, [
                    'status' => 'missing_template',
                ]),
            );
        }

        $variables = $this->templateRegistry->variablesFor($message);
        $subject = $message->subject ?? $template->subject;

        $sendResult = $this->mailSender->send(
            recipientEmail: $recipientEmail,
            mail: new NotificationMail(
                mailSubject: $subject,
                viewName: $template->view,
                variables: $variables,
            ),
        );

        if (! $sendResult['success']) {
            $error = trim((string) ($sendResult['error'] ?? ''));
            $message = $error === ''
                ? 'Unable to send email notification.'
                : 'Unable to send email notification: '.$error;

            return NotificationResult::failure(
                channel: NotificationChannelType::Email,
                message: $message,
                retryable: true,
                metadata: array_merge($metadata, [
                    'status' => 'transport_failure',
                    'recipient_email' => $recipientEmail,
                    'template_view' => $template->view,
                    'error' => $sendResult['error'],
                ]),
            );
        }

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
                'template_view' => $template->view,
            ]),
        );
    }
}

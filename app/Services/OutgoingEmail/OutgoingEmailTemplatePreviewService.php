<?php

namespace App\Services\OutgoingEmail;

use App\Data\NotificationMessage;
use App\Enums\NotificationType;
use App\Models\Incident;
use App\Models\IncomingEmailMessage;
use App\Models\Order;
use App\Models\User;
use App\Services\Notifications\NotificationMailTemplateRegistry;
use InvalidArgumentException;

class OutgoingEmailTemplatePreviewService
{
    /**
     * @return list<array{key: string, label: string}>
     */
    public function availableTemplates(?User $actor = null): array
    {
        return [
            ['key' => 'blank', 'label' => 'Blank (free text)'],
            ['key' => NotificationType::RequestSerialNumber->value, 'label' => 'Request serial number'],
            ['key' => NotificationType::CustomerWaitingFollowup->value, 'label' => 'Customer waiting follow-up'],
            ['key' => NotificationType::CallbackSchedule->value, 'label' => 'Callback schedule'],
            ['key' => NotificationType::SupportAppointmentBooked->value, 'label' => 'Support appointment booked'],
            ['key' => NotificationType::ServiceCaseClosed->value, 'label' => 'Service case closed'],
            ['key' => NotificationType::DriverInstallationGuide->value, 'label' => 'Driver installation guide'],
            ['key' => NotificationType::RefundConfirmation->value, 'label' => 'Refund confirmation'],
        ];
    }

    /**
     * @return array{subject: string, body_html: string, template_key: string}
     */
    public function preview(
        IncomingEmailMessage $message,
        string $templateKey,
        User $actor,
        array $extraVariables = [],
    ): array {
        $templateKey = trim($templateKey);

        if ($templateKey === '' || $templateKey === 'blank') {
            $subject = $this->defaultReplySubject($message);

            return [
                'subject' => $subject,
                'body_html' => '<p></p>',
                'template_key' => 'blank',
            ];
        }

        $type = NotificationType::tryFrom($templateKey);

        if ($type === null) {
            throw new InvalidArgumentException('Unknown reply template: '.$templateKey);
        }

        $incident = $this->resolveIncident($message);
        $order = $this->resolveOrder($message, $incident);

        if ($incident === null || $order === null) {
            throw new InvalidArgumentException('Template replies require a linked incident and order.');
        }

        $registry = app(NotificationMailTemplateRegistry::class);
        $definition = $registry->resolve($type);

        if ($definition === null) {
            throw new InvalidArgumentException('Template is not registered: '.$templateKey);
        }

        $notification = new NotificationMessage(
            type: $type,
            customer: $order,
            incident: $incident,
            variables: $extraVariables,
            actor: $actor,
        );

        $variables = $registry->variablesFor($notification);
        $subject = $registry->subjectFor($type, $notification);
        $bodyHtml = view($definition->view, $variables)->render();

        return [
            'subject' => $subject !== '' ? $subject : $this->defaultReplySubject($message),
            'body_html' => $bodyHtml,
            'template_key' => $type->value,
        ];
    }

    public function defaultReplySubject(IncomingEmailMessage $message): string
    {
        $subject = trim((string) $message->subject);

        if ($subject === '') {
            return 'Re: Your support request';
        }

        if (preg_match('/^re:\s*/i', $subject) === 1) {
            return $subject;
        }

        return 'Re: '.$subject;
    }

    private function resolveIncident(IncomingEmailMessage $message): ?Incident
    {
        $message->loadMissing('incident');

        return $message->incident;
    }

    private function resolveOrder(IncomingEmailMessage $message, ?Incident $incident): ?Order
    {
        $message->loadMissing('order');

        if ($message->order instanceof Order) {
            return $message->order;
        }

        $incident?->loadMissing('order');

        return $incident?->order;
    }
}

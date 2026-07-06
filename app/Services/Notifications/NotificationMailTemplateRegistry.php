<?php

namespace App\Services\Notifications;

use App\Data\NotificationMailTemplateDefinition;
use App\Data\NotificationMessage;
use App\Enums\NotificationType;
use App\Models\Order;
use App\Services\SupportAppointmentUrlService;

class NotificationMailTemplateRegistry
{
    public function __construct(
        private readonly SupportAppointmentUrlService $supportAppointmentUrlService,
    ) {}
    public function resolve(NotificationType $type): ?NotificationMailTemplateDefinition
    {
        return match ($type) {
            NotificationType::RequestSerialNumber => new NotificationMailTemplateDefinition(
                subject: 'Help Us Complete Your Device Setup',
                view: 'emails.notifications.request-serial-number',
                requiredVariables: ['customer_name', 'booking_url'],
            ),
            NotificationType::CustomerWaitingFollowup => new NotificationMailTemplateDefinition(
                subject: 'Support Reminder: Request {reference} waiting for your response',
                view: 'emails.notifications.customer-waiting-followup',
                requiredVariables: ['customer_name', 'reference', 'booking_url'],
            ),
            NotificationType::SupportAppointmentBooked => new NotificationMailTemplateDefinition(
                subject: 'Your Support Appointment Is Confirmed',
                view: 'emails.notifications.support-appointment-booked',
                requiredVariables: ['customer_name', 'order_id', 'preferred_date', 'preferred_time_slot'],
            ),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function variablesFor(NotificationMessage $message): array
    {
        $defaults = match ($message->type) {
            NotificationType::RequestSerialNumber => [
                'customer_name' => $this->resolveCustomerName($message),
                'booking_url' => $this->supportAppointmentUrlService->bookingUrl($message->incident),
            ],
            NotificationType::CustomerWaitingFollowup => [
                'customer_name' => $this->resolveCustomerName($message),
                'reference' => trim((string) ($message->incident->reference_no ?? '')),
                'booking_url' => $this->supportAppointmentUrlService->bookingUrl($message->incident),
            ],
            NotificationType::SupportAppointmentBooked => [
                'customer_name' => $this->resolveCustomerName($message),
                'order_id' => (string) ($message->variables['order_id'] ?? ''),
                'preferred_date' => (string) ($message->variables['preferred_date'] ?? ''),
                'preferred_time_slot' => (string) ($message->variables['preferred_time_slot'] ?? ''),
            ],
        };

        return array_merge($defaults, $message->variables);
    }

    public function subjectFor(NotificationType $type, NotificationMessage $message): string
    {
        $template = $this->resolve($type);

        if ($template === null) {
            return '';
        }

        $subject = $template->subject;

        foreach ($this->variablesFor($message) as $key => $value) {
            $subject = str_replace('{'.$key.'}', (string) $value, $subject);
        }

        return $subject;
    }

    private function resolveCustomerName(NotificationMessage $message): string
    {
        $customer = $message->customer;

        if ($customer instanceof Order) {
            $name = trim((string) ($customer->customer_name ?? ''));

            if ($name !== '') {
                return $name;
            }
        }

        return 'Customer';
    }
}

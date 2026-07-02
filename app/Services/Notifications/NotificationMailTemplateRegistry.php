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
        };

        return array_merge($defaults, $message->variables);
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

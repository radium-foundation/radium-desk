<?php

namespace App\Services\Notifications;

use App\Data\NotificationMailTemplateDefinition;
use App\Data\NotificationMessage;
use App\Enums\NotificationType;
use App\Models\Order;

class NotificationMailTemplateRegistry
{
    public function resolve(NotificationType $type): ?NotificationMailTemplateDefinition
    {
        return match ($type) {
            NotificationType::RequestSerialNumber => new NotificationMailTemplateDefinition(
                subject: 'Please provide your device serial number',
                view: 'emails.notifications.request-serial-number',
                requiredVariables: ['customer_name'],
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

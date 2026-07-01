<?php

namespace App\Services\Notifications;

use App\Models\Order;

class NotificationCustomerContactResolver
{
    public function resolveEmail(mixed $customer): ?string
    {
        if ($customer instanceof Order) {
            $email = trim((string) ($customer->customer_email ?? ''));

            return $email !== '' ? $email : null;
        }

        if (is_object($customer) && isset($customer->customer_email)) {
            $email = trim((string) $customer->customer_email);

            return $email !== '' ? $email : null;
        }

        return null;
    }
}

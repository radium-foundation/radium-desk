<?php

namespace App\Support\Telegram;

use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;

class TelegramOperationalLinkFormatter
{
    public function incidentLink(User $user, ?Incident $incident): ?string
    {
        if ($incident === null || ! $user->can('view', $incident)) {
            return null;
        }

        return route('incidents.show', $incident, absolute: true);
    }

    public function refundLink(User $user, ?RefundRequest $refund): ?string
    {
        if ($refund === null || ! $user->can('view', $refund)) {
            return null;
        }

        return route('refunds.show', $refund, absolute: true);
    }

    public function orderLink(User $user, ?Order $order): ?string
    {
        if ($order === null) {
            return null;
        }

        if (! $user->can('incidents.view')) {
            return null;
        }

        return route('dashboard.orders.customer-360', $order, absolute: true);
    }

    public function linkLine(string $label, ?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        return "[{$label}]({$url})";
    }
}

<?php

namespace App\Support\Telegram;

use App\Data\Telegram\TelegramOutboundMessage;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;

class TelegramOperationalLinkFormatter
{
    public function __construct(
        private readonly TelegramTextLinkEntityBuilder $textLinkEntityBuilder,
    ) {}

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

        $normalizedUrl = trim($url);

        if (! preg_match('#^https?://#', $normalizedUrl)) {
            return null;
        }

        return "{$label}: {$normalizedUrl}";
    }

    public function orderIdentifierLine(?Order $order): ?string
    {
        if ($order === null) {
            return null;
        }

        $orderId = trim((string) ($order->order_id ?? ''));

        if ($orderId === '') {
            return null;
        }

        return "Order: {$orderId}";
    }

    /**
     * @param  list<array{text: string, url: string}>  $links
     */
    public function outboundMessageWithTextLinks(string $text, array $links): TelegramOutboundMessage
    {
        return $this->textLinkEntityBuilder->messageWithTextLinks($text, $links);
    }

    /**
     * @return list<array{text: string, url: string}>
     */
    public function authorizedOperationalLinks(User $user, ?Incident $incident = null, ?RefundRequest $refund = null, ?Order $order = null): array
    {
        $links = [];

        if ($incident !== null) {
            $caseReference = trim((string) ($incident->reference_no ?? ''));

            if ($caseReference !== '') {
                $url = $this->incidentLink($user, $incident);

                if ($url !== null) {
                    $links[] = ['text' => $caseReference, 'url' => $url];
                }
            }
        }

        if ($refund !== null) {
            $refundReference = trim((string) ($refund->reference_no ?? ''));

            if ($refundReference !== '') {
                $url = $this->refundLink($user, $refund);

                if ($url !== null) {
                    $links[] = ['text' => $refundReference, 'url' => $url];
                }
            }
        }

        if ($order !== null) {
            $orderId = trim((string) ($order->order_id ?? ''));

            if ($orderId !== '') {
                $url = $this->orderLink($user, $order);

                if ($url !== null) {
                    $links[] = ['text' => $orderId, 'url' => $url];
                }
            }
        }

        return $links;
    }
}

<?php

namespace App\Support\Bonvoice;

use App\Models\BonvoiceCallAlert;
use App\Support\BonvoiceCallStatuses;

class BonvoiceIncomingCallInteractionBuilder
{
    /**
     * @return array{
     *     channel: string,
     *     status: string,
     *     call_id: string,
     *     incident_id: ?int,
     *     customer_phone: ?string,
     *     customer_name: ?string,
     *     direction: string,
     *     reference_label: string,
     * }
     */
    public static function fromAlert(BonvoiceCallAlert $alert, ?string $status = null): array
    {
        $alert->loadMissing(['order', 'incident', 'callEvent']);

        $resolvedStatus = $status ?? self::resolveStatus($alert->callEvent?->status);

        return [
            'channel' => 'phone',
            'status' => $resolvedStatus,
            'call_id' => $alert->call_id,
            'incident_id' => $alert->incident_id,
            'customer_phone' => $alert->customer_phone,
            'customer_name' => $alert->order?->customer_name,
            'direction' => 'inbound',
            'reference_label' => $alert->incident?->reference_no
                ?? $alert->order?->order_id
                ?? '',
        ];
    }

    private static function resolveStatus(?string $callStatus): string
    {
        return BonvoiceCallStatuses::isAnsweredStatus($callStatus) ? 'answered' : 'ringing';
    }
}

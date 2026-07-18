<?php

namespace App\Services\IncomingEmail;

use App\Enums\IncomingEmailMessageStatus;
use App\Models\IncomingEmailMessage;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

class IncomingEmailOrderVisibilityQuery
{
    /**
     * @return Builder<IncomingEmailMessage>
     */
    public function forOrder(Order $order): Builder
    {
        $order->loadMissing('incidents');

        $incidentIds = $order->incidents->pluck('id')->filter()->values();
        $customerEmail = strtolower(trim((string) $order->customer_email));

        return IncomingEmailMessage::query()
            ->whereIn('status', [
                IncomingEmailMessageStatus::Linked,
                IncomingEmailMessageStatus::HistoricalCustomer,
            ])
            ->where(function (Builder $builder) use ($order, $incidentIds, $customerEmail): void {
                $builder->where('order_id', $order->id);

                if ($incidentIds->isNotEmpty()) {
                    $builder->orWhereIn('incident_id', $incidentIds);
                }

                if ($customerEmail !== '') {
                    $builder->orWhere('from_email', $customerEmail);
                }
            });
    }
}

<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\Remark;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class RemarkTimelineService
{
    public function forRemarkable(Model $remarkable): Collection
    {
        return Remark::query()
            ->with(['user', 'remarkable'])
            ->where('remarkable_type', $remarkable->getMorphClass())
            ->where('remarkable_id', $remarkable->getKey())
            ->latest()
            ->get();
    }

    public function forOrder(Order $order): Collection
    {
        $incidentIds = $order->incidents()->pluck('id');
        $refundIds = $order->refundRequests()->pluck('id');

        return Remark::query()
            ->with(['user', 'remarkable'])
            ->where(function ($query) use ($order, $incidentIds, $refundIds) {
                $query->where(function ($orderQuery) use ($order) {
                    $orderQuery->where('remarkable_type', $order->getMorphClass())
                        ->where('remarkable_id', $order->getKey());
                });

                if ($incidentIds->isNotEmpty()) {
                    $query->orWhere(function ($incidentQuery) use ($incidentIds) {
                        $incidentQuery->where('remarkable_type', (new Incident)->getMorphClass())
                            ->whereIn('remarkable_id', $incidentIds);
                    });
                }

                if ($refundIds->isNotEmpty()) {
                    $query->orWhere(function ($refundQuery) use ($refundIds) {
                        $refundQuery->where('remarkable_type', (new RefundRequest)->getMorphClass())
                            ->whereIn('remarkable_id', $refundIds);
                    });
                }
            })
            ->latest()
            ->get();
    }

    public function contextLabel(Remark $remark): string
    {
        $remarkable = $remark->remarkable;

        if ($remarkable instanceof Order) {
            return 'Order';
        }

        if ($remarkable instanceof Incident) {
            return 'Incident '.$remarkable->reference_no;
        }

        if ($remarkable instanceof RefundRequest) {
            return 'Refund '.$remarkable->reference_no;
        }

        return class_basename($remark->remarkable_type);
    }
}

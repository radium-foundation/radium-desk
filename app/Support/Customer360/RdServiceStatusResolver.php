<?php

namespace App\Support\Customer360;

use App\Models\Incident;
use App\Models\Order;

class RdServiceStatusResolver
{
    /**
     * @return array{status: string, variant: string}
     */
    public function resolve(Incident $incident, Order $order): array
    {
        if (! $incident->isActive()) {
            return [
                'status' => 'Completed',
                'variant' => 'neutral',
            ];
        }

        if ($incident->hasActiveSupportAppointment()) {
            return [
                'status' => 'Scheduled',
                'variant' => 'info',
            ];
        }

        if ($this->isWaitingForCustomer($incident)) {
            return [
                'status' => 'Waiting Customer',
                'variant' => 'warning',
            ];
        }

        return [
            'status' => $order->isTransactionLocked() ? 'Active' : 'Pending',
            'variant' => $order->isTransactionLocked() ? 'success' : 'warning',
        ];
    }

    private function isWaitingForCustomer(Incident $incident): bool
    {
        $incident->loadMissing('activeWaitingState');

        return $incident->activeWaitingState !== null;
    }
}

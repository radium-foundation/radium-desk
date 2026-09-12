<?php

namespace App\Services\HistoricalSearch;

class HistoricalTenurePresenter
{
    /**
     * Tenure from canonical historical rows only — no invented end dates.
     *
     * @return array{display: string, end_date: ?string}
     */
    public function present(?string $orderLineage, ?string $orderStatus, ?string $orderDate): array
    {
        if ($orderLineage !== 'rd_service' || $orderStatus === null || trim($orderStatus) === '') {
            return [
                'display' => 'unknown',
                'end_date' => null,
            ];
        }

        $normalized = strtolower(trim($orderStatus));

        if ($this->isEndedStatus($normalized)) {
            return [
                'display' => 'ended',
                'end_date' => null,
            ];
        }

        if ($this->isActiveStatus($normalized)) {
            return [
                'display' => 'active',
                'end_date' => null,
            ];
        }

        return [
            'display' => 'unknown',
            'end_date' => null,
        ];
    }

    private function isEndedStatus(string $normalized): bool
    {
        return in_array($normalized, [
            'expired',
            'inactive',
            'cancelled',
            'canceled',
            'ended',
            'closed',
            'deactivated',
            'completed',
            'complete',
        ], true)
            || str_contains($normalized, 'expir');
    }

    private function isActiveStatus(string $normalized): bool
    {
        return in_array($normalized, [
            'active',
            'running',
            'paid',
            'live',
        ], true);
    }
}

<?php

namespace App\Services\HistoricalSearch;

class HistoricalPaymentPresenter
{
    /**
     * @return array{display: string, date: ?string}
     */
    public function present(?string $paymentStatus, ?string $paymentDate = null): array
    {
        if ($paymentDate === null || trim($paymentDate) === '') {
            $paymentDate = null;
        }

        if ($paymentStatus === null || trim($paymentStatus) === '') {
            return [
                'display' => 'unknown',
                'date' => $paymentDate,
            ];
        }

        $normalized = strtolower(trim($paymentStatus));

        if ($this->isPartial($normalized)) {
            return [
                'display' => 'partial',
                'date' => $paymentDate,
            ];
        }

        if ($this->isVerifiedPaid($normalized)) {
            return [
                'display' => 'paid',
                'date' => $paymentDate,
            ];
        }

        if ($this->isUnpaid($normalized)) {
            return [
                'display' => 'unpaid',
                'date' => $paymentDate,
            ];
        }

        return [
            'display' => 'unknown',
            'date' => $paymentDate,
        ];
    }

    private function isVerifiedPaid(string $normalized): bool
    {
        return in_array($normalized, [
            'paid',
            'success',
            'successful',
            'completed',
            'complete',
            'captured',
            'payment received',
            'payment_received',
        ], true)
            || str_contains($normalized, 'paid')
            || str_contains($normalized, 'success');
    }

    private function isPartial(string $normalized): bool
    {
        return str_contains($normalized, 'partial')
            || in_array($normalized, ['part_paid', 'part paid'], true);
    }

    private function isUnpaid(string $normalized): bool
    {
        return in_array($normalized, [
            'unpaid',
            'pending',
            'failed',
            'failure',
            'cancelled',
            'canceled',
            'refunded',
        ], true);
    }
}

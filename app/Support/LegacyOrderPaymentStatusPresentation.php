<?php

namespace App\Support;

/**
 * Dashboard legacy-order preview payment status (display only).
 *
 * Source of truth: RadiumBox spoke {@see \App\Data\LegacyOrderPreview} payment_status
 * (mapped from rd_order / billing order payment_status). When that field is empty, RadiumBox
 * commerce documents unpaid checkout failure as billing {@code orders.status} {@code reject}
 * with null {@code payment_status} (Desk order lookup / ecom payment result). Shipment and
 * invoice fields are not used.
 */
final class LegacyOrderPaymentStatusPresentation
{
    /**
     * @return array{label: string, tone: string, icon: string}
     */
    public function present(?string $paymentStatus, ?string $orderStatus = null): array
    {
        if ($paymentStatus === null || trim($paymentStatus) === '') {
            if ($this->isAuthoritativeCommercePaymentFailureOrderStatus($orderStatus)) {
                return $this->failed();
            }

            return $this->unknown();
        }

        $normalized = strtolower(trim($paymentStatus));
        $category = $this->categorize($normalized);

        if ($category === 'paid' && (
            $this->orderStatusContradictsPaid($orderStatus)
            || $this->isAuthoritativeCommercePaymentFailureOrderStatus($orderStatus)
        )) {
            return $this->unknown();
        }

        return match ($category) {
            'paid' => $this->paid(),
            'pending' => $this->pending($normalized),
            'failed' => $this->failed(),
            'cancelled' => $this->cancelled(),
            default => $this->unknown(),
        };
    }

    /**
     * @return array{label: string, tone: string, icon: string}
     */
    private function paid(): array
    {
        return [
            'label' => 'PAID',
            'tone' => 'paid',
            'icon' => 'check',
        ];
    }

    /**
     * @return array{label: string, tone: string, icon: string}
     */
    private function pending(string $normalized): array
    {
        $label = match (true) {
            str_contains($normalized, 'partial') => 'PARTIALLY PAID',
            str_contains($normalized, 'process') => 'PROCESSING',
            str_contains($normalized, 'initiat') => 'INITIATED',
            default => 'PENDING',
        };

        return [
            'label' => $label,
            'tone' => 'pending',
            'icon' => 'clock',
        ];
    }

    /**
     * @return array{label: string, tone: string, icon: string}
     */
    private function failed(): array
    {
        return [
            'label' => 'FAILED',
            'tone' => 'failed',
            'icon' => 'x',
        ];
    }

    /**
     * @return array{label: string, tone: string, icon: string}
     */
    private function cancelled(): array
    {
        return [
            'label' => 'CANCELLED',
            'tone' => 'cancelled',
            'icon' => 'dash',
        ];
    }

    /**
     * @return array{label: string, tone: string, icon: string}
     */
    private function unknown(): array
    {
        return [
            'label' => 'STATUS UNKNOWN',
            'tone' => 'unknown',
            'icon' => 'question',
        ];
    }

    private function categorize(string $normalized): string
    {
        if ($this->isCancelled($normalized)) {
            return 'cancelled';
        }

        if ($this->isFailed($normalized)) {
            return 'failed';
        }

        if ($this->isPending($normalized)) {
            return 'pending';
        }

        if ($this->isVerifiedPaid($normalized)) {
            return 'paid';
        }

        return 'unknown';
    }

    private function isVerifiedPaid(string $normalized): bool
    {
        if ($this->isExplicitlyUnpaid($normalized)) {
            return false;
        }

        return in_array($normalized, [
            'paid',
            'success',
            'successful',
            'completed',
            'complete',
            'captured',
            'payment received',
            'payment_received',
        ], true);
    }

    private function isExplicitlyUnpaid(string $normalized): bool
    {
        return in_array($normalized, ['unpaid', 'not paid', 'not_paid'], true)
            || str_contains($normalized, 'unpaid')
            || str_starts_with($normalized, 'not paid');
    }

    private function isPending(string $normalized): bool
    {
        return in_array($normalized, [
            'pending',
            'unpaid',
            'not paid',
            'not_paid',
            'processing',
            'initiated',
            'awaiting_payment',
            'payment_pending',
            'awaiting payment',
            'authorized',
            'in_progress',
            'in progress',
        ], true)
            || str_contains($normalized, 'pending')
            || str_contains($normalized, 'partial')
            || str_contains($normalized, 'process');
    }

    private function isFailed(string $normalized): bool
    {
        return in_array($normalized, [
            'failed',
            'failure',
            'declined',
            'rejected',
            'error',
        ], true)
            || str_contains($normalized, 'fail')
            || str_contains($normalized, 'declin')
            || str_contains($normalized, 'reject');
    }

    private function isCancelled(string $normalized): bool
    {
        return in_array($normalized, [
            'cancelled',
            'canceled',
            'expired',
            'void',
            'voided',
        ], true)
            || str_contains($normalized, 'cancel')
            || str_contains($normalized, 'expired');
    }

    private function orderStatusContradictsPaid(?string $orderStatus): bool
    {
        if ($orderStatus === null || trim($orderStatus) === '') {
            return false;
        }

        $normalized = strtolower(trim($orderStatus));

        foreach ([
            'pending',
            'unpaid',
            'payment_pending',
            'awaiting_payment',
            'failed',
            'failure',
            'declined',
            'cancelled',
            'canceled',
            'expired',
        ] as $contradiction) {
            if ($normalized === $contradiction || str_contains($normalized, $contradiction)) {
                return true;
            }
        }

        return false;
    }

    /**
     * RadiumBox commerce terminal unpaid / payment-failure order statuses when payment_status is absent.
     */
    private function isAuthoritativeCommercePaymentFailureOrderStatus(?string $orderStatus): bool
    {
        if ($orderStatus === null || trim($orderStatus) === '') {
            return false;
        }

        $normalized = strtolower(trim($orderStatus));

        return in_array($normalized, [
            'reject',
            'rejected',
            'failed',
            'failure',
        ], true);
    }
}

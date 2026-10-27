<?php

namespace App\Services\CustomerOrderHistory;

use App\Models\Order;

class CustomerOrderHistoryValidityPresenter
{
    /**
     * @param  array<string, mixed>|null  $enrichmentMetadata
     * @return array{status: string, label: string, warranty: ?string, amc: ?string}
     */
    public function present(Order $order, ?array $enrichmentMetadata): array
    {
        $warranty = $this->nullableString($enrichmentMetadata['warranty'] ?? null);

        $amc = $this->nullableString($order->amc_status)
            ?? $this->nullableString($enrichmentMetadata['amc'] ?? null)
            ?? $this->nullableString($enrichmentMetadata['amc_status'] ?? null);

        $status = 'unknown';
        $label = 'Unknown';

        if ($this->containsExpired($warranty) || $this->containsExpired($amc)) {
            $status = 'expired';
            $label = 'Expired (enrichment)';
        } elseif ($this->containsActive($warranty) || $this->containsActive($amc)) {
            $status = 'active';
            $label = 'Active (enrichment)';
        } elseif ($warranty === null && $amc === null) {
            $status = 'unknown';
            $label = 'Unknown';
        } elseif ($this->isNotAvailable($warranty) && $this->isNotAvailable($amc)) {
            $status = 'not_available';
            $label = 'Not available';
        }

        return [
            'status' => $status,
            'label' => $label,
            'warranty' => $warranty,
            'amc' => $amc,
        ];
    }

    private function containsExpired(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        return str_contains(strtolower($value), 'expired');
    }

    private function containsActive(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        $lower = strtolower($value);

        return str_contains($lower, 'active')
            || str_contains($lower, 'valid')
            || str_contains($lower, 'under warranty');
    }

    private function isNotAvailable(?string $value): bool
    {
        if ($value === null) {
            return true;
        }

        return str_contains(strtolower($value), 'not available');
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}

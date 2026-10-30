<?php

namespace App\Services\StatutoryCoverage;

use App\Data\StatutoryCoverage\StatutoryCoverageWindow;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\Order;
use App\Support\BusinessOrderId;
use Illuminate\Support\Collection;

final class StatutoryCoverageDeskReferenceBuilder
{
    /**
     * @return array{ids: list<string>, complete: bool, total: int, reason: ?string}
     */
    public function collectIds(
        StatutoryInvoiceChannel $channel,
        StatutoryCoverageWindow $window,
        int $page,
        int $perPage,
    ): array {
        $config = config('statutory_coverage.channels.'.$channel->value, []);
        if (! is_array($config)) {
            return ['ids' => [], 'complete' => true, 'total' => 0, 'reason' => 'Unknown channel configuration.'];
        }

        if ((bool) ($config['observe_only'] ?? false)) {
            return ['ids' => [], 'complete' => true, 'total' => 0, 'reason' => 'Channel is observe-only (no Desk commerce observed).'];
        }

        $sourceType = (string) config('statutory_coverage.default_source_type', 'commerce_order');

        $commerceIds = CommerceOrder::query()
            ->where('channel', $channel->value)
            ->where('source_type', $sourceType)
            ->where(function ($query) use ($window) {
                $query->whereBetween('created_at', [$window->from, $window->to])
                    ->orWhereBetween('paid_at', [$window->from, $window->to]);
            })
            ->orderBy('id')
            ->pluck('source_id');

        $supportIds = collect();
        if ((bool) ($config['desk_paid_support_orders'] ?? false)) {
            $supportIds = $this->paidSupportOrderIds($channel, $window);
        }

        $merged = $commerceIds
            ->merge($supportIds)
            ->map(fn ($id) => strtoupper(trim((string) $id)))
            ->filter(fn ($id) => $this->matchesChannel($channel, $id))
            ->unique()
            ->sort()
            ->values();

        $offset = max(0, ($page - 1) * $perPage);
        $slice = $merged->slice($offset, $perPage)->values()->all();

        return [
            'ids' => $slice,
            'complete' => ($offset + count($slice)) >= $merged->count(),
            'total' => $merged->count(),
            'reason' => null,
        ];
    }

    /**
     * @return Collection<int, string>
     */
    private function paidSupportOrderIds(StatutoryInvoiceChannel $channel, StatutoryCoverageWindow $window): Collection
    {
        return Order::query()
            ->whereNull('deleted_at')
            ->whereBetween('payment_date', [$window->from, $window->to])
            ->whereNotNull('transaction_id')
            ->where('transaction_id', '!=', '')
            ->orderBy('id')
            ->pluck('order_id')
            ->filter(fn ($id) => $this->matchesChannel($channel, (string) $id));
    }

    private function matchesChannel(StatutoryInvoiceChannel $channel, string $sourceId): bool
    {
        $parsed = BusinessOrderId::parse($sourceId);
        if ($parsed === null) {
            return false;
        }

        return match ($channel) {
            StatutoryInvoiceChannel::RdServiceIn => $parsed['prefix'] === 'RD'
                && $parsed['kind'] === 'service'
                && $parsed['owner'] === 'rdservice.in',
            StatutoryInvoiceChannel::RdServiceNet => in_array($parsed['prefix'], ['RN', 'RA'], true)
                && $parsed['owner'] === 'rdservice.net',
            StatutoryInvoiceChannel::RadiumBoxCom => $parsed['owner'] === 'radiumbox.com',
            StatutoryInvoiceChannel::RadiumSignCom => in_array($parsed['prefix'], ['RS', 'RC', 'RDS'], true),
            default => false,
        };
    }
}

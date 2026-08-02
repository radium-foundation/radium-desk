<?php

namespace App\Services\Platform;

use App\Enums\IntegrationHealthStatus;
use App\Enums\PlatformHealthStatus;
use App\Enums\RefundStatus;
use App\Models\RefundRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class PlatformFinanceOverviewService
{
    public const CACHE_KEY = 'platform:finance:overview';

    public const CACHE_TTL_SECONDS = 60;

    public function __construct(
        private readonly PlatformIntegrationHealthOverviewService $integrations,
    ) {}

    /**
     * @return array{items: list<array<string, mixed>>, overall_status: string, generated_at: ?string, available: bool, links: list<array{label: string, url: string}>}
     */
    public function cachedOverview(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached) && isset($cached['items'])) {
            return $cached + ['available' => true];
        }

        return [
            'items' => [],
            'overall_status' => PlatformHealthStatus::Disabled->value,
            'generated_at' => null,
            'available' => false,
            'links' => $this->links(),
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, overall_status: string, generated_at: string, available: bool, links: list<array{label: string, url: string}>}
     */
    public function overview(bool $useCache = true): array
    {
        if ($useCache) {
            $cached = $this->cachedOverview();
            if ($cached['available']) {
                return $cached;
            }
        }

        $pendingRefunds = 0;
        if (Schema::hasTable('refund_requests')) {
            $pendingRefunds = (int) RefundRequest::query()
                ->where('status', RefundStatus::Pending)
                ->count();
        }

        // Reuse Integration Health item cache only — never live Cashfree probes here.
        $cashfree = $this->integrations->cachedItem('cashfree') ?? [
            'status' => 'unavailable',
            'platform_status' => PlatformHealthStatus::Disabled->value,
            'summary' => 'Waiting for Integration Health refresh.',
            'status_label' => 'Unavailable',
            'badge_class' => 'secondary',
        ];

        $refundStatus = $pendingRefunds > 10
            ? PlatformHealthStatus::Warning
            : PlatformHealthStatus::Healthy;

        $cashfreeIntegration = IntegrationHealthStatus::tryFrom((string) ($cashfree['status'] ?? ''))
            ?? IntegrationHealthStatus::Unavailable;
        $cashfreeStatus = $cashfreeIntegration->toPlatform();
        if (isset($cashfree['platform_status']) && is_string($cashfree['platform_status'])) {
            $cashfreeStatus = PlatformHealthStatus::tryFrom($cashfree['platform_status']) ?? $cashfreeStatus;
        }

        $items = [
            [
                'key' => 'refunds',
                'label' => 'Refund Queue',
                'status' => $refundStatus->value,
                'status_label' => $refundStatus->label(),
                'badge_class' => $refundStatus->badgeClass(),
                'summary' => sprintf('%d pending refunds', $pendingRefunds),
                'updated_at' => now()->toIso8601String(),
            ],
            [
                'key' => 'cashfree',
                'label' => 'Cashfree',
                'status' => $cashfreeStatus->value,
                'status_label' => (string) ($cashfree['status_label'] ?? $cashfreeStatus->label()),
                'badge_class' => (string) ($cashfree['badge_class'] ?? $cashfreeStatus->badgeClass()),
                'summary' => (string) ($cashfree['summary'] ?? $cashfree['detail'] ?? 'Payment webhooks'),
                'updated_at' => $cashfree['updated_at'] ?? now()->toIso8601String(),
            ],
        ];

        $overall = PlatformHealthStatus::worst($refundStatus, $cashfreeStatus);
        $payload = [
            'items' => $items,
            'overall_status' => $overall->value,
            'generated_at' => now()->toIso8601String(),
            'available' => true,
            'links' => $this->links(),
        ];

        Cache::put(self::CACHE_KEY, $payload, now()->addSeconds(self::CACHE_TTL_SECONDS));

        return $payload;
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    public function links(): array
    {
        $links = [
            [
                'label' => 'Refund Queue',
                'url' => route('refunds.index', ['status' => 'pending']),
            ],
            [
                'label' => 'Webhook Explorer',
                'url' => route('cashfree.webhook-explorer.index'),
            ],
        ];

        if (Route::has('finance.dashboard')) {
            $links[] = ['label' => 'Finance Dashboard', 'url' => route('finance.dashboard')];
        }
        if (Route::has('finance.expenses.index')) {
            $links[] = ['label' => 'Expenses', 'url' => route('finance.expenses.index')];
        }
        if (Route::has('finance.payments.index')) {
            $links[] = ['label' => 'Payments', 'url' => route('finance.payments.index')];
        }
        if (Route::has('finance.cash.index')) {
            $links[] = ['label' => 'Cash Ledger', 'url' => route('finance.cash.index')];
        }

        $links[] = [
            'label' => 'Cashfree Diagnostics',
            'url' => route('admin.platform.index').'#platform-zone-integration_health',
        ];

        return $links;
    }
}

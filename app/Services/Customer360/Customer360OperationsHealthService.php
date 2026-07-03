<?php

namespace App\Services\Customer360;

use App\Enums\OperationsHealthStatus;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\Order;
use App\Services\Operations\OperationsRadiumBoxHealthService;
use App\Services\Operations\OperationsIntegrationHealthService;
use Illuminate\Support\Facades\Cache;

class Customer360OperationsHealthService
{
    private const CACHE_TTL_SECONDS = 60;

    public function __construct(
        private readonly OperationsRadiumBoxHealthService $radiumBoxHealthService,
        private readonly OperationsIntegrationHealthService $integrationHealthService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forOrder(Order $order): array
    {
        $cacheKey = 'customer360:operations-health:order:'.$order->id;
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $widget = $this->build($order);
        Cache::put($cacheKey, $widget, now()->addSeconds(self::CACHE_TTL_SECONDS));

        return $widget;
    }

    /**
     * @return array<string, mixed>
     */
    private function build(Order $order): array
    {
        $radiumBox = $this->radiumBoxHealthService->widget();
        $integrations = collect($this->integrationHealthService->cards());

        return [
            'radiumbox' => [
                'status' => $this->radiumBoxStatus($order),
                'pending' => $order->radiumbox_sync_status === RadiumBoxEnrichmentSyncStatus::Pending,
                'failed' => $order->radiumbox_sync_status === RadiumBoxEnrichmentSyncStatus::Failed,
                'recovery_running' => $order->radiumbox_sync_status === RadiumBoxEnrichmentSyncStatus::Pending,
                'platform_pending' => $radiumBox['pending_syncs'] ?? 0,
                'platform_failed' => $radiumBox['failed_syncs'] ?? 0,
            ],
            'email' => $this->channelHealth($integrations->firstWhere('key', 'zeptomail')),
            'whatsapp' => $this->channelHealth($integrations->firstWhere('key', 'interakt')),
            'appointments' => [
                'status' => 'healthy',
                'status_label' => 'Healthy',
                'detail' => 'Booking links available for support appointments.',
            ],
        ];
    }

    private function radiumBoxStatus(Order $order): string
    {
        return match ($order->radiumbox_sync_status) {
            RadiumBoxEnrichmentSyncStatus::Failed => 'failed',
            RadiumBoxEnrichmentSyncStatus::Pending => 'pending',
            RadiumBoxEnrichmentSyncStatus::Synced => 'healthy',
            default => 'idle',
        };
    }

    /**
     * @param  array<string, mixed>|null  $card
     * @return array{status: string, status_label: string, detail: string}
     */
    private function channelHealth(?array $card): array
    {
        if ($card === null) {
            return [
                'status' => 'unknown',
                'status_label' => 'Unavailable',
                'detail' => 'Monitoring data unavailable.',
            ];
        }

        $status = OperationsHealthStatus::tryFrom((string) ($card['status'] ?? ''))
            ?? OperationsHealthStatus::Warning;

        return [
            'status' => $status->value,
            'status_label' => (string) ($card['status_label'] ?? $status->label()),
            'detail' => (string) ($card['detail'] ?? ''),
        ];
    }
}

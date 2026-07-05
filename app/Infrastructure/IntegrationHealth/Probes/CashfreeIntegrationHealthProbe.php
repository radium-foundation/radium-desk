<?php

namespace App\Infrastructure\IntegrationHealth\Probes;

use App\Infrastructure\IntegrationHealth\Contracts\IntegrationHealthProbe;
use App\Infrastructure\IntegrationHealth\IntegrationHealthSnapshot;
use App\Models\CashfreeWebhookLog;
use App\Services\Cashfree\CashfreePaymentIntegrityService;
use App\Services\Cashfree\CashfreeWebhookProcessorService;
use Illuminate\Support\Facades\Schema;

class CashfreeIntegrationHealthProbe implements IntegrationHealthProbe
{
    public function __construct(
        private readonly CashfreePaymentIntegrityService $integrityService,
    ) {}

    public function key(): string
    {
        return 'cashfree';
    }

    public function label(): string
    {
        return 'Cashfree';
    }

    public function probe(): IntegrationHealthSnapshot
    {
        if (! Schema::hasTable('cashfree_webhook_logs')) {
            return $this->unknownSnapshot('Webhook log table unavailable.');
        }

        $lastSuccess = CashfreeWebhookLog::query()
            ->where('processing_status', CashfreeWebhookProcessorService::STATUS_PROCESSED)
            ->latest('processed_at')
            ->value('processed_at');

        $activeFailure = CashfreeWebhookLog::query()
            ->where('processing_status', CashfreeWebhookProcessorService::STATUS_FAILED)
            ->get()
            ->first(fn (CashfreeWebhookLog $log): bool => $this->integrityService
                ->classifyFailedLog($log)
                ->category
                ->isActionable());

        $lastFailure = $activeFailure?->processed_at;

        $retryCount = CashfreeWebhookLog::query()
            ->where('processing_status', CashfreeWebhookProcessorService::STATUS_FAILED)
            ->where('processed_at', '>=', now()->subDay())
            ->get()
            ->filter(fn (CashfreeWebhookLog $log): bool => $this->integrityService
                ->classifyFailedLog($log)
                ->category
                ->isActionable())
            ->count();

        $lastError = $activeFailure?->processing_error;

        $connectionStatus = $this->resolveConnectionStatus($lastSuccess, $lastFailure);

        return new IntegrationHealthSnapshot(
            key: $this->key(),
            label: $this->label(),
            connectionStatus: $connectionStatus,
            lastSuccessAt: $lastSuccess,
            lastFailureAt: $lastFailure,
            lastSyncAt: $lastSuccess,
            retryCount: $retryCount,
            averageResponseTimeMs: null,
            lastErrorMessage: is_string($lastError) ? $lastError : null,
        );
    }

    private function resolveConnectionStatus(mixed $lastSuccess, mixed $lastFailure): string
    {
        if ($lastSuccess === null && $lastFailure === null) {
            return 'idle';
        }

        if ($lastFailure !== null && ($lastSuccess === null || $lastFailure > $lastSuccess)) {
            return 'degraded';
        }

        return 'healthy';
    }

    private function unknownSnapshot(string $message): IntegrationHealthSnapshot
    {
        return new IntegrationHealthSnapshot(
            key: $this->key(),
            label: $this->label(),
            connectionStatus: 'unknown',
            lastSuccessAt: null,
            lastFailureAt: null,
            lastSyncAt: null,
            retryCount: 0,
            averageResponseTimeMs: null,
            lastErrorMessage: $message,
        );
    }
}

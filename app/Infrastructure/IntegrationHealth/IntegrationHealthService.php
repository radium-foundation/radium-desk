<?php

namespace App\Infrastructure\IntegrationHealth;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Infrastructure\IntegrationHealth\Probes\RadiumBoxIntegrationHealthProbe;
use App\Infrastructure\Queue\QueueMetricsService;
use App\Models\CashfreeWebhookLog;
use App\Models\Order;
use App\Services\Cashfree\CashfreeWebhookProcessorService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Illuminate\Support\Facades\Schema;

/**
 * Structured integration health metrics for monitoring and future dashboards.
 */
class IntegrationHealthService
{
    public function __construct(
        private readonly RadiumBoxIntegrationHealthProbe $radiumBoxProbe,
        private readonly QueueMetricsService $queueMetricsService,
        private readonly RadiumBoxOrderEnrichmentSyncStore $syncStore,
    ) {}

    public function cashfree(): CashfreeHealthDetails
    {
        if (! Schema::hasTable('cashfree_webhook_logs')) {
            return new CashfreeHealthDetails(
                lastWebhookAt: null,
                lastSuccessfulWebhookAt: null,
                failedWebhooks: 0,
            );
        }

        $lastWebhook = CashfreeWebhookLog::query()
            ->latest('received_at')
            ->value('received_at');

        $lastSuccessful = CashfreeWebhookLog::query()
            ->where('processing_status', CashfreeWebhookProcessorService::STATUS_PROCESSED)
            ->latest('processed_at')
            ->value('processed_at');

        $failedWebhooks = (int) CashfreeWebhookLog::query()
            ->where('processing_status', CashfreeWebhookProcessorService::STATUS_FAILED)
            ->count();

        return new CashfreeHealthDetails(
            lastWebhookAt: $lastWebhook,
            lastSuccessfulWebhookAt: $lastSuccessful,
            failedWebhooks: $failedWebhooks,
        );
    }

    public function radiumbox(): RadiumBoxHealthDetails
    {
        $snapshot = $this->radiumBoxProbe->probe();
        $pendingSyncs = 0;
        $failedSyncs = 0;

        foreach (Order::query()->pluck('id') as $orderId) {
            $status = $this->syncStore->status((int) $orderId);

            if ($status === RadiumBoxEnrichmentSyncStatus::Pending) {
                $pendingSyncs++;
            } elseif ($status === RadiumBoxEnrichmentSyncStatus::Failed) {
                $failedSyncs++;
            }
        }

        return new RadiumBoxHealthDetails(
            lastSuccessfulSyncAt: $snapshot->lastSuccessAt,
            failedSyncs: $failedSyncs,
            pendingSyncs: $pendingSyncs,
            averageResponseTimeMs: $snapshot->averageResponseTimeMs,
        );
    }

    public function queue(): QueueHealthDetails
    {
        $snapshot = $this->queueMetricsService->capture();

        return new QueueHealthDetails(
            pendingJobs: $snapshot->pendingJobs,
            failedJobs: $snapshot->failedJobs,
            oldestPendingJobAt: $snapshot->oldestPendingJobAt,
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return [
            'cashfree' => $this->cashfree()->toArray(),
            'radiumbox' => $this->radiumbox()->toArray(),
            'queue' => $this->queue()->toArray(),
        ];
    }
}

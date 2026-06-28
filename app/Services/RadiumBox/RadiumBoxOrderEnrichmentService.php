<?php

namespace App\Services\RadiumBox;

use App\Infrastructure\IntegrationHealth\Probes\RadiumBoxIntegrationHealthProbe;
use App\Jobs\RadiumBoxOrderEnrichmentJob;
use App\Models\Order;
use App\Services\RadiumBox\Exceptions\RadiumBoxEnrichmentRetryException;
use Illuminate\Support\Facades\Log;

class RadiumBoxOrderEnrichmentService
{
    public function __construct(
        private readonly RadiumBoxService $radiumBoxService,
        private readonly RadiumBoxOrderEnrichmentSyncStore $syncStore,
    ) {}

    public function dispatch(Order $order): void
    {
        $this->syncStore->markPending($order->id);

        RadiumBoxOrderEnrichmentJob::dispatch($order->id);
    }

    public function retryOrderEnrichment(Order $order): void
    {
        $this->syncStore->forget($order->id);
        $this->dispatch($order->fresh());
    }

    public function process(int $orderId, int $attempt): void
    {
        $startedAt = microtime(true);

        $order = Order::query()->find($orderId);

        if ($order === null) {
            $this->logAttempt(
                orderId: $orderId,
                attempt: $attempt,
                durationMs: $this->durationMs($startedAt),
                result: 'skipped',
                errorMessage: 'Order record not found.',
            );

            return;
        }

        try {
            $outcome = $this->radiumBoxService->enrichOrderFromBackgroundSync($order);
            $fetchResult = $outcome['fetch_result'];

            if ($fetchResult->retriable) {
                throw new RadiumBoxEnrichmentRetryException(
                    $fetchResult->errorMessage ?? 'RadiumBox enrichment lookup failed.',
                );
            }

            $enrichment = $outcome['enrichment'];
            $metadata = $enrichment?->supplementalMetadata() ?? [];

            if ($outcome['applied']) {
                $metadata['fields_applied'] = ['serial_number', 'device_model'];
            }

            if ($fetchResult->isNotFound()) {
                $metadata['lookup_result'] = 'order_not_found';
            } elseif ($fetchResult->errorType === 'disabled') {
                $metadata['lookup_result'] = 'disabled';
            } elseif ($enrichment !== null && $enrichment->hasData()) {
                $metadata['lookup_result'] = 'data_received';
            } else {
                $metadata['lookup_result'] = 'no_data';
            }

            $this->syncStore->markSynced($order->id, $metadata);

            $this->logAttempt(
                orderId: $order->id,
                attempt: $attempt,
                durationMs: $this->durationMs($startedAt),
                result: $outcome['applied'] ? 'synced_with_updates' : 'synced',
                errorMessage: $fetchResult->errorMessage,
                metadata: $metadata,
            );
        } catch (RadiumBoxEnrichmentRetryException $exception) {
            $this->logAttempt(
                orderId: $order->id,
                attempt: $attempt,
                durationMs: $this->durationMs($startedAt),
                result: 'retry_scheduled',
                errorMessage: $exception->getMessage(),
            );

            throw $exception;
        }
    }

    public function markFailed(int $orderId, ?string $errorMessage = null): void
    {
        RadiumBoxIntegrationHealthProbe::recordAttempt(
            result: 'failed',
            durationMs: 0,
            errorMessage: $errorMessage,
        );

        $this->syncStore->markFailed($orderId, $errorMessage);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function logAttempt(
        int $orderId,
        int $attempt,
        float $durationMs,
        string $result,
        ?string $errorMessage = null,
        array $metadata = [],
    ): void {
        RadiumBoxIntegrationHealthProbe::recordAttempt(
            result: $result,
            durationMs: $durationMs,
            errorMessage: $errorMessage,
            metadata: $metadata,
        );

        Log::info('RadiumBox order enrichment attempt completed.', [
            'order_id' => $orderId,
            'attempt' => $attempt,
            'duration_ms' => round($durationMs, 2),
            'result' => $result,
            'error_message' => $errorMessage,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    private function durationMs(float $startedAt): float
    {
        return (microtime(true) - $startedAt) * 1000;
    }
}

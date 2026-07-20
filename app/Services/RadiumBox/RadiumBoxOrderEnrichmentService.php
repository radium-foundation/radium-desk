<?php

namespace App\Services\RadiumBox;

use App\Data\RadiumBox\RadiumBoxManualSyncResult;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\RadiumBoxSyncSource;
use App\Enums\WaitingReason;
use App\Infrastructure\IntegrationHealth\Probes\RadiumBoxIntegrationHealthProbe;
use App\Jobs\RadiumBoxOrderEnrichmentJob;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderIdentityLifecycleService;
use App\Services\RadiumBox\Exceptions\RadiumBoxEnrichmentRetryException;
use App\Services\ServiceCaseAssignmentEligibilityService;
use App\Services\ServiceCaseAutomationMonitorService;
use App\Support\RadiumBox\RadiumBoxSyncErrorFormatter;
use Illuminate\Support\Facades\Log;

class RadiumBoxOrderEnrichmentService
{
    public function __construct(
        private readonly RadiumBoxService $radiumBoxService,
        private readonly RadiumBoxOrderEnrichmentSyncStore $syncStore,
        private readonly RadiumBoxSyncErrorFormatter $syncErrorFormatter,
        private readonly RadiumBoxSyncAuditService $syncAuditService,
        private readonly OrderIdentityLifecycleService $identityLifecycle,
        private readonly ServiceCaseAutomationMonitorService $automationMonitor,
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

    public function manualSync(Order $order, User $actor): RadiumBoxManualSyncResult
    {
        $startedAt = microtime(true);
        $order = $order->fresh();
        $previousStatus = $this->syncStore->status($order->id);
        $attemptNumber = $this->syncStore->attemptCount($order->id) + 1;

        Log::info('RadiumBox manual sync initiated.', [
            'order_id' => $order->order_id,
            'order_db_id' => $order->id,
            'user_id' => $actor->id,
            'previous_sync_status' => $previousStatus->value,
            'attempt' => $attemptNumber,
            'sync_source' => RadiumBoxSyncSource::Manual->value,
        ]);

        try {
            $result = $this->runSyncAttempt($order, $attemptNumber, RadiumBoxSyncSource::Manual);
            $fetchResult = $result['fetch_result'];
            $durationMs = $this->durationMs($startedAt);
            $newStatus = $this->syncStore->status($order->id);

            if ($fetchResult->retriable) {
                $errorMessage = $fetchResult->errorMessage ?? 'RadiumBox enrichment lookup failed.';
                $failureMetadata = array_merge($result['metadata'], array_filter([
                    'error_type' => $fetchResult->errorType,
                ]));

                $this->syncStore->markFailed($order->id, $errorMessage, $failureMetadata);
                $newStatus = RadiumBoxEnrichmentSyncStatus::Failed;

                $this->syncAuditService->recordEnrichmentFailed(
                    $order,
                    RadiumBoxSyncSource::Manual->value,
                    $attemptNumber,
                    $errorMessage,
                    $failureMetadata,
                );

                $friendlyMessage = $this->syncErrorFormatter->friendlyMessage(
                    $errorMessage,
                    $fetchResult->errorType,
                    $failureMetadata,
                ) ?? 'Synchronization failed.';

                Log::warning('RadiumBox manual sync failed.', [
                    'order_id' => $order->order_id,
                    'order_db_id' => $order->id,
                    'user_id' => $actor->id,
                    'previous_sync_status' => $previousStatus->value,
                    'new_sync_status' => $newStatus->value,
                    'attempt' => $attemptNumber,
                    'duration_ms' => round($durationMs, 2),
                    'error_message' => $errorMessage,
                    'sync_source' => RadiumBoxSyncSource::Manual->value,
                ]);

                $this->syncAuditService->recordManualSync(
                    $order,
                    $actor,
                    success: false,
                    durationMs: $durationMs,
                    previousStatus: $previousStatus->value,
                    newStatus: $newStatus->value,
                    errorMessage: $errorMessage,
                );

                return new RadiumBoxManualSyncResult(
                    success: false,
                    message: $friendlyMessage,
                    durationMs: $durationMs,
                );
            }

            $this->syncStore->markSynced($order->id, $result['metadata']);
            $this->runPostSyncLifecycleIfNeeded($order->fresh(), $result);
            $newStatus = RadiumBoxEnrichmentSyncStatus::Synced;

            $this->recordEnrichmentOutcome(
                $order,
                RadiumBoxSyncSource::Manual,
                $attemptNumber,
                $result,
                $fetchResult,
            );

            $freshOrder = $order->fresh();
            $serialApplied = $result['outcome']['persistence']->serialApplied;

            $this->logAttempt(
                orderId: $order->id,
                attempt: $attemptNumber,
                durationMs: $durationMs,
                result: $result['outcome']['applied'] ? 'synced_with_updates' : 'synced',
                errorMessage: $fetchResult->errorMessage,
                metadata: $result['metadata'],
                syncSource: RadiumBoxSyncSource::Manual,
                previousStatus: $previousStatus->value,
                newStatus: $newStatus->value,
                userId: $actor->id,
            );

            Log::info('RadiumBox manual sync succeeded.', [
                'order_id' => $order->order_id,
                'order_db_id' => $order->id,
                'user_id' => $actor->id,
                'previous_sync_status' => $previousStatus->value,
                'new_sync_status' => $newStatus->value,
                'attempt' => $attemptNumber,
                'duration_ms' => round($durationMs, 2),
                'serial_applied' => $serialApplied,
                'serial_number' => $freshOrder->serial_number,
                'sync_source' => RadiumBoxSyncSource::Manual->value,
            ]);

            $this->syncAuditService->recordManualSync(
                $order,
                $actor,
                success: true,
                durationMs: $durationMs,
                previousStatus: $previousStatus->value,
                newStatus: $newStatus->value,
            );

            $message = filled($freshOrder->serial_number)
                ? '✓ Device information synchronized successfully.'
                : 'Synchronization completed. Serial number is not yet available from RadiumBox.';

            return new RadiumBoxManualSyncResult(
                success: true,
                message: $message,
                durationMs: $durationMs,
                serialApplied: $serialApplied,
            );
        } catch (\Throwable $exception) {
            $durationMs = $this->durationMs($startedAt);
            $errorMessage = $exception->getMessage();

            $this->syncStore->markFailed($order->id, $errorMessage);

            $this->syncAuditService->recordEnrichmentFailed(
                $order,
                RadiumBoxSyncSource::Manual->value,
                $attemptNumber,
                $errorMessage,
            );

            $this->syncAuditService->recordManualSync(
                $order,
                $actor,
                success: false,
                durationMs: $durationMs,
                previousStatus: $previousStatus->value,
                newStatus: RadiumBoxEnrichmentSyncStatus::Failed->value,
                errorMessage: $errorMessage,
            );

            Log::warning('RadiumBox manual sync failed.', [
                'order_id' => $order->order_id,
                'order_db_id' => $order->id,
                'user_id' => $actor->id,
                'previous_sync_status' => $previousStatus->value,
                'new_sync_status' => RadiumBoxEnrichmentSyncStatus::Failed->value,
                'attempt' => $attemptNumber,
                'duration_ms' => round($durationMs, 2),
                'error_message' => $errorMessage,
                'sync_source' => RadiumBoxSyncSource::Manual->value,
            ]);

            return new RadiumBoxManualSyncResult(
                success: false,
                message: $this->syncErrorFormatter->friendlyMessage($errorMessage) ?? 'Synchronization failed.',
                durationMs: $durationMs,
            );
        }
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
                syncSource: RadiumBoxSyncSource::Background,
            );

            return;
        }

        $previousStatus = $this->syncStore->status($order->id)->value;

        try {
            $result = $this->runSyncAttempt($order, $attempt);
            $fetchResult = $result['fetch_result'];

            if ($fetchResult->retriable) {
                throw new RadiumBoxEnrichmentRetryException(
                    $fetchResult->errorMessage ?? 'RadiumBox enrichment lookup failed.',
                );
            }

            $this->syncStore->markSynced($order->id, $result['metadata']);
            $this->runPostSyncLifecycleIfNeeded($order->fresh(), $result);

            $this->recordEnrichmentOutcome(
                $order,
                RadiumBoxSyncSource::Background,
                $attempt,
                $result,
                $fetchResult,
            );

            $this->logAttempt(
                orderId: $order->id,
                attempt: $attempt,
                durationMs: $this->durationMs($startedAt),
                result: $result['outcome']['applied'] ? 'synced_with_updates' : 'synced',
                errorMessage: $fetchResult->errorMessage,
                metadata: $result['metadata'],
                syncSource: RadiumBoxSyncSource::Background,
                previousStatus: $previousStatus,
                newStatus: RadiumBoxEnrichmentSyncStatus::Synced->value,
            );

            if ($result['outcome']['applied']) {
                Log::info('RadiumBox enrichment retry succeeded.', [
                    'order_id' => $order->order_id,
                    'order_db_id' => $order->id,
                    'fields_applied' => $result['outcome']['persistence']->fieldsApplied,
                ]);
            }
        } catch (RadiumBoxEnrichmentRetryException $exception) {
            $this->logAttempt(
                orderId: $order->id,
                attempt: $attempt,
                durationMs: $this->durationMs($startedAt),
                result: 'retry_scheduled',
                errorMessage: $exception->getMessage(),
                syncSource: RadiumBoxSyncSource::Background,
                previousStatus: $previousStatus,
                newStatus: $this->syncStore->status($order->id)->value,
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

        $order = Order::query()->find($orderId);

        if ($order !== null) {
            $this->syncAuditService->recordEnrichmentFailed(
                $order,
                RadiumBoxSyncSource::Background->value,
                $this->syncStore->attemptCount($orderId),
                $errorMessage,
            );
        }

        Log::warning('RadiumBox enrichment retry failed.', [
            'order_id' => $order?->order_id,
            'order_db_id' => $orderId,
            'phase' => 'processing',
            'reason' => $errorMessage,
        ]);
    }

    /**
     * @return array{
     *     outcome: array{
     *         applied: bool,
     *         enrichment: ?RadiumBoxOrderEnrichment,
     *         fetch_result: RadiumBoxOrderEnrichmentFetchResult,
     *         persistence: \App\Data\EnrichmentPersistenceResult,
     *     },
     *     fetch_result: RadiumBoxOrderEnrichmentFetchResult,
     *     metadata: array<string, mixed>,
     * }
     */
    private function runSyncAttempt(Order $order, int $attempt, RadiumBoxSyncSource $syncSource = RadiumBoxSyncSource::Background): array
    {
        $this->syncStore->recordProcessingAttempt($order->id);

        $this->syncAuditService->recordEnrichmentStarted(
            $order,
            $syncSource->value,
            $attempt,
            [
                'radiumbox_sync_status' => $this->syncStore->status($order->id)->value,
            ],
        );

        $outcome = $this->radiumBoxService->enrichOrderFromBackgroundSync($order);
        $fetchResult = $outcome['fetch_result'];
        $enrichment = $outcome['enrichment'];
        $metadata = $enrichment?->supplementalMetadata() ?? [];

        if ($outcome['persistence']->updated) {
            $metadata['fields_applied'] = $outcome['persistence']->fieldsApplied;
        }

        if (! $outcome['persistence']->serialApplied
            && filled($enrichment?->serialNumber)
            && ! $order->fresh()->isSerialLocked()) {
            $metadata['duplicate_serial'] = true;
        }

        if ($fetchResult->isNotFound()) {
            $metadata['lookup_result'] = 'order_not_found';
            $metadata['error_type'] = 'order_not_found';
        } elseif ($fetchResult->errorType === 'disabled') {
            $metadata['lookup_result'] = 'disabled';
            $metadata['error_type'] = 'disabled';
        } elseif ($enrichment !== null && $enrichment->hasData()) {
            $metadata['lookup_result'] = 'data_received';
        } else {
            $metadata['lookup_result'] = 'no_data';
        }

        if ($fetchResult->errorType !== null) {
            $metadata['error_type'] = $fetchResult->errorType;
        }

        return [
            'outcome' => $outcome,
            'fetch_result' => $fetchResult,
            'metadata' => $metadata,
        ];
    }

    /**
     * @param  array{
     *     outcome: array{
     *         applied: bool,
     *         enrichment: ?RadiumBoxOrderEnrichment,
     *         fetch_result: RadiumBoxOrderEnrichmentFetchResult,
     *         persistence: \App\Data\EnrichmentPersistenceResult,
     *     },
     *     fetch_result: RadiumBoxOrderEnrichmentFetchResult,
     *     metadata: array<string, mixed>,
     * }  $result
     */
    private function recordEnrichmentOutcome(
        Order $order,
        RadiumBoxSyncSource $syncSource,
        int $attempt,
        array $result,
        RadiumBoxOrderEnrichmentFetchResult $fetchResult,
    ): void {
        if ($result['outcome']['applied']) {
            $this->syncAuditService->recordEnrichmentCompleted(
                $order,
                $syncSource->value,
                $attempt,
                $result['outcome']['persistence']->fieldsApplied,
                $result['metadata'],
            );

            return;
        }

        if ($fetchResult->isNotFound()) {
            $this->syncAuditService->recordEnrichmentFailed(
                $order,
                $syncSource->value,
                $attempt,
                $fetchResult->errorMessage,
                $result['metadata'],
            );

            return;
        }

        if (($result['outcome']['enrichment'] ?? null)?->hasData() === true) {
            $this->syncAuditService->recordEnrichmentCompleted(
                $order,
                $syncSource->value,
                $attempt,
                $result['outcome']['persistence']->fieldsApplied,
                $result['metadata'],
            );

            return;
        }

        $this->syncAuditService->recordEnrichmentCompleted(
            $order,
            $syncSource->value,
            $attempt,
            $result['outcome']['persistence']->fieldsApplied,
            $result['metadata'],
        );
    }

    private function runPostSyncLifecycleIfNeeded(Order $order, array $result): void
    {
        // Serial may have been applied while sync was still PENDING, so the first
        // identity lifecycle pass could not clear serial waiting / enter Ready.
        // After markSynced(), recover that path when conditions still hold.
        if ($this->shouldRecoverSerialWaitingAfterSync($order)) {
            $this->runIdentityLifecycle($order, serialChanged: true);

            return;
        }

        $persistence = $result['outcome']['persistence'];

        if ($persistence->updated && $this->identityLifecycle->hasIdentityFields($persistence->fieldsApplied)) {
            return;
        }

        $this->runIdentityLifecycle($order);
    }

    /**
     * Idempotent gate: only re-run lifecycle when serial waiting is still active
     * and validation now passes under SYNCED status.
     */
    private function shouldRecoverSerialWaitingAfterSync(Order $order): bool
    {
        $order = $order->fresh(['incidents.activeWaitingState']);

        if ($order === null || ! filled(trim((string) $order->serial_number))) {
            return false;
        }

        $hasSerialWaiting = $order->incidents->contains(function (Incident $incident): bool {
            if (! $incident->isActive()) {
                return false;
            }

            $waiting = $incident->activeWaitingState;

            return $waiting !== null
                && $waiting->isActive()
                && $waiting->waiting_reason === WaitingReason::SerialNumber;
        });

        if (! $hasSerialWaiting) {
            return false;
        }

        return app(ServiceCaseAssignmentEligibilityService::class)->passesValidationForOrder($order);
    }

    private function runIdentityLifecycle(Order $order, bool $serialChanged = false): void
    {
        $order->loadMissing('incidents.creator');
        $actor = $order->incidents->first()?->creator;

        $this->identityLifecycle->afterIdentityChanged(
            order: $order,
            actor: $this->automationMonitor->resolveAutomationActor($actor),
            source: 'radiumbox_enrichment',
            serialChanged: $serialChanged,
        );
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
        ?RadiumBoxSyncSource $syncSource = null,
        ?string $previousStatus = null,
        ?string $newStatus = null,
        ?int $userId = null,
    ): void {
        if ($syncSource !== null) {
            $metadata['sync_source'] = $syncSource->value;
        }

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
            'sync_source' => $syncSource?->value,
            'previous_sync_status' => $previousStatus,
            'new_sync_status' => $newStatus,
            'user_id' => $userId,
        ]);
    }

    private function durationMs(float $startedAt): float
    {
        return (microtime(true) - $startedAt) * 1000;
    }
}

<?php

namespace App\Services;

use App\Data\OrderIdentityRepairBatchOptions;
use App\Data\OrderIdentityRepairFailure;
use App\Data\OrderIdentityRepairProgress;
use App\Data\OrderIdentityRepairStatistics;
use App\Data\OrderIdentityRepairSummary;
use App\Data\OrderIdentityValidationAnalysisBatchResult;
use App\Enums\IncidentStatus;
use App\Enums\OrderIdentityRepairFailureCategory;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\ServiceCaseAutomationStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\RadiumBox\RadiumBoxClient;
use App\Services\RadiumBox\RadiumBoxOrderEnrichment;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentFetchResult;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\SerialValidation\SerialPlaceholderService;
use App\Services\SerialValidation\SerialValidationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

class OrderIdentityRepairService
{
    public const AUDIT_EVENT = 'order.identity.repaired';

    public const RESUME_CACHE_KEY = 'orders:repair-identity:last-processed-order-id';

    private const MAX_FETCH_ATTEMPTS = 3;

    private const MAX_RATE_LIMIT_PAUSES = 3;

    private const BACKOFF_BASE_SECONDS = 1;

    private const DEFAULT_RATE_LIMIT_PAUSE_SECONDS = 60;

    private const PROGRESS_INTERVAL = 25;

    public function __construct(
        private readonly RadiumBoxClient $radiumBoxClient,
        private readonly RadiumBoxOrderEnrichmentSyncStore $syncStore,
        private readonly SerialValidationService $serialValidationService,
        private readonly SerialPlaceholderService $placeholderService,
        private readonly ServiceCaseAssignmentEligibilityService $eligibilityService,
        private readonly ServiceCaseAutomationStatusService $automationStatusService,
        private readonly ServiceCaseAutomationMonitorService $automationMonitor,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function countPendingRepairs(bool $activeOnly = false): int
    {
        return $this->ordersQuery($activeOnly)
            ->get()
            ->filter(fn (Order $order): bool => $this->needsRadiumBoxFetch($order))
            ->count();
    }

    public function statistics(OrderIdentityValidationAnalysisBatchResult $analysis): OrderIdentityRepairStatistics
    {
        $groups = $analysis->failuresByGroup;

        $validationFailures = ($groups['Validator rule'] ?? 0)
            + ($groups['Product mapping mismatch'] ?? 0);

        $lastRepairRun = AuditLog::query()
            ->where('event', self::AUDIT_EVENT)
            ->latest('created_at')
            ->value('created_at');

        return new OrderIdentityRepairStatistics(
            totalRepaired: AuditLog::query()->where('event', self::AUDIT_EVENT)->count(),
            duplicateConflicts: $groups['Duplicate serial'] ?? 0,
            waitingCustomerSerial: $groups['Waiting for customer serial'] ?? 0,
            validationFailures: $validationFailures,
            notFound: $groups['RadiumBox not found'] ?? 0,
            lastRepairRun: $lastRepairRun,
        );
    }

    public function repair(
        ?int $limit = null,
        bool $dryRun = false,
        bool $activeOnly = false,
        int $offset = 0,
        bool $resume = false,
        ?callable $onProgress = null,
    ): OrderIdentityRepairSummary {
        return $this->repairWithOptions(
            new OrderIdentityRepairBatchOptions(
                limit: $limit,
                offset: $offset,
                dryRun: $dryRun,
                activeOnly: $activeOnly,
                resume: $resume,
            ),
            $onProgress,
        );
    }

    public function repairWithOptions(
        OrderIdentityRepairBatchOptions $options,
        ?callable $onProgress = null,
    ): OrderIdentityRepairSummary {
        $startedAt = microtime(true);

        $summary = [
            'ordersScanned' => 0,
            'ordersProcessed' => 0,
            'ordersRepaired' => 0,
            'ordersSkipped' => 0,
            'ordersAlreadyValid' => 0,
            'ordersFailed' => 0,
            'rateLimited' => 0,
            'duplicateSerials' => 0,
            'notFound' => 0,
            'validationFailed' => 0,
            'unexpectedFailures' => 0,
            'assignmentsEscalated' => 0,
            'assignmentsToAgent' => 0,
            'assignmentsUnchanged' => 0,
            'repairedOrderIds' => [],
            'failedOrders' => [],
        ];

        $processed = 0;
        $candidateIndex = 0;
        $batchTotal = $this->countBatchTotal($options);

        $query = $this->ordersQuery($options->activeOnly);

        if ($options->resume) {
            $lastProcessedId = Cache::get(self::RESUME_CACHE_KEY);

            if (is_numeric($lastProcessedId)) {
                $query->where('id', '>', (int) $lastProcessedId);
            }
        }

        $query->chunkById(100, function (Collection $orders) use (
            &$summary,
            &$processed,
            &$candidateIndex,
            $options,
            $onProgress,
            $batchTotal,
        ): bool {
            foreach ($orders as $order) {
                $summary['ordersScanned']++;

                if ($this->eligibilityService->passesValidationForOrder($order)) {
                    $summary['ordersAlreadyValid']++;

                    continue;
                }

                if (! $this->needsRadiumBoxFetch($order)) {
                    $summary['ordersSkipped']++;

                    continue;
                }

                if (! $options->resume && $candidateIndex < $options->offset) {
                    $candidateIndex++;

                    continue;
                }

                if ($options->limit !== null && $processed >= $options->limit) {
                    return false;
                }

                $candidateIndex++;
                $processed++;
                $summary['ordersProcessed']++;

                try {
                    $fetchResult = $this->fetchOrderEnrichmentForRepair($order->order_id);
                    $result = $this->repairOrderFromFetch($order, $fetchResult, $options->dryRun);
                } catch (\Throwable $exception) {
                    $failure = new OrderIdentityRepairFailure(
                        orderId: (string) $order->order_id,
                        message: $exception->getMessage(),
                        category: OrderIdentityRepairFailureCategory::UnexpectedException,
                    );
                    $this->recordFailure($summary, $failure);

                    Log::warning('Legacy identity repair failed with unexpected exception.', [
                        'order_id' => $order->order_id,
                        'message' => $exception->getMessage(),
                    ]);

                    $this->rememberResumePosition($order, $options);

                    $this->maybeReportProgress($summary, $processed, $batchTotal, $onProgress);

                    continue;
                }

                if ($result['outcome'] === 'repaired') {
                    $summary['ordersRepaired']++;
                    $summary['repairedOrderIds'][] = $order->order_id;
                } elseif ($result['outcome'] === 'failed') {
                    $summary['ordersFailed']++;
                } else {
                    $summary['ordersSkipped']++;
                }

                if ($result['failure'] !== null) {
                    $this->recordFailure($summary, $result['failure']);
                }

                $summary['assignmentsEscalated'] += $result['assignmentsEscalated'];
                $summary['assignmentsToAgent'] += $result['assignmentsToAgent'];
                $summary['assignmentsUnchanged'] += $result['assignmentsUnchanged'];

                $this->rememberResumePosition($order, $options);
                $this->maybeReportProgress($summary, $processed, $batchTotal, $onProgress);
            }

            if ($options->limit !== null && $processed >= $options->limit) {
                return false;
            }

            return true;
        });

        $this->maybeReportProgress($summary, $processed, $batchTotal, $onProgress, force: true);

        return new OrderIdentityRepairSummary(
            ...$summary,
            elapsedSeconds: round(microtime(true) - $startedAt, 2),
        );
    }

    public function countBatchTotal(OrderIdentityRepairBatchOptions $options): int
    {
        $candidateCount = 0;

        $query = $this->ordersQuery($options->activeOnly);

        if ($options->resume) {
            $lastProcessedId = Cache::get(self::RESUME_CACHE_KEY);

            if (is_numeric($lastProcessedId)) {
                $query->where('id', '>', (int) $lastProcessedId);
            }
        }

        $query->chunkById(100, function (Collection $orders) use (&$candidateCount, $options): bool {
            foreach ($orders as $order) {
                if ($this->eligibilityService->passesValidationForOrder($order)) {
                    continue;
                }

                if (! $this->needsRadiumBoxFetch($order)) {
                    continue;
                }

                $candidateCount++;
            }

            return true;
        });

        $available = max(0, $candidateCount - ($options->resume ? 0 : $options->offset));

        if ($options->limit === null) {
            return $available;
        }

        return min($options->limit, $available);
    }

    public function clearResumePosition(): void
    {
        Cache::forget(self::RESUME_CACHE_KEY);
    }

    public function lastResumePosition(): ?int
    {
        $lastProcessedId = Cache::get(self::RESUME_CACHE_KEY);

        return is_numeric($lastProcessedId) ? (int) $lastProcessedId : null;
    }

    /**
     * @return Collection<int, Order>
     */
    public function repairCandidates(bool $activeOnly = false): Collection
    {
        return $this->ordersQuery($activeOnly)
            ->get()
            ->filter(fn (Order $order): bool => $this->needsRadiumBoxFetch($order))
            ->values();
    }

    public function needsRadiumBoxFetch(Order $order): bool
    {
        if ($this->eligibilityService->passesValidationForOrder($order)) {
            return false;
        }

        if ($this->isValueMissing($order->serial_number) || $this->isPlaceholderValue($order->serial_number)) {
            return true;
        }

        if ($this->isSerialInvalid($order)) {
            return true;
        }

        if ($this->isDeviceModelMissing($order) || $this->isPlaceholderValue($order->device_model)) {
            return true;
        }

        if ($this->isProductMissing($order) || $this->isPlaceholderValue($order->product_name)) {
            return true;
        }

        if ($this->hasAutomationValidationFailed($order)) {
            return true;
        }

        $syncStatus = $this->syncStore->status($order->id);

        if ($syncStatus !== RadiumBoxEnrichmentSyncStatus::Synced) {
            return true;
        }

        return false;
    }

    /**
     * @return Builder<Order>
     */
    private function ordersQuery(bool $activeOnly): Builder
    {
        $query = Order::query()
            ->whereNotNull('order_id')
            ->where('order_id', '!=', '')
            ->orderBy('id');

        if ($activeOnly) {
            $query->whereHas('incidents', function (Builder $incidentQuery): void {
                $incidentQuery->whereIn('status', IncidentStatus::operationallyActive());
            });
        }

        return $query;
    }

    /**
     * @return array{
     *     outcome: string,
     *     assignmentsEscalated: int,
     *     assignmentsToAgent: int,
     *     assignmentsUnchanged: int,
     *     failure: ?OrderIdentityRepairFailure,
     * }
     */
    private function repairOrderFromFetch(Order $order, RadiumBoxOrderEnrichmentFetchResult $fetchResult, bool $dryRun): array
    {
        $emptyStats = [
            'outcome' => 'skipped',
            'assignmentsEscalated' => 0,
            'assignmentsToAgent' => 0,
            'assignmentsUnchanged' => 0,
            'failure' => null,
        ];

        if (! filled($order->order_id)) {
            return $emptyStats;
        }

        if ($fetchResult->errorType === 'disabled' || ($fetchResult->retriable && ! $fetchResult->isNotFound())) {
            return [
                ...$emptyStats,
                'outcome' => 'failed',
                'failure' => $this->failureFromFetchResult($order, $fetchResult),
            ];
        }

        if ($fetchResult->isNotFound()) {
            if (! $dryRun) {
                $this->syncStore->markFailed($order->id, $fetchResult->errorMessage, [
                    'lookup_result' => 'order_not_found',
                ]);
            }

            return [
                ...$emptyStats,
                'outcome' => 'failed',
                'failure' => new OrderIdentityRepairFailure(
                    orderId: (string) $order->order_id,
                    message: $fetchResult->errorMessage ?? 'Order was not found in RadiumBox.',
                    category: OrderIdentityRepairFailureCategory::RadiumBoxNotFound,
                ),
            ];
        }

        $enrichment = $fetchResult->enrichment;

        if ($enrichment === null || ! $enrichment->hasData()) {
            if (! $dryRun) {
                $this->syncStore->markSynced($order->id, [
                    'lookup_result' => 'no_data',
                ]);
            }

            return $emptyStats;
        }

        $updates = $this->buildRepairUpdates($order, $enrichment);

        if ($updates === []) {
            if (! $dryRun) {
                $this->syncStore->markSynced($order->id, [
                    'lookup_result' => 'no_applicable_changes',
                ]);
            }

            return $emptyStats;
        }

        if ($this->wouldDuplicateSerial($order, $updates)) {
            return [
                ...$emptyStats,
                'outcome' => 'failed',
                'failure' => $this->duplicateSerialFailure($order, $updates['serial_number']),
            ];
        }

        if ($dryRun) {
            return [
                ...$emptyStats,
                'outcome' => 'repaired',
            ];
        }

        $assignmentSnapshots = $this->snapshotAssignments($order);
        $actor = $this->resolveActor($order);

        return DB::transaction(function () use ($order, $updates, $enrichment, $actor, $assignmentSnapshots): array {
            $oldValues = $this->identitySnapshot($order);

            $order->update($updates);
            $freshOrder = $order->fresh();

            $this->applyValidationNormalization($freshOrder, $actor);

            $freshOrder = $freshOrder->fresh();

            $metadata = $enrichment->supplementalMetadata();
            $metadata['lookup_result'] = 'data_received';
            $metadata['fields_applied'] = array_keys($updates);
            $metadata['repair_source'] = 'orders:repair-identity';

            $this->syncStore->markSynced($freshOrder->id, $metadata);

            $this->auditLogService->log(
                userId: $this->automationMonitor->resolveAutomationActor($actor)->id,
                event: self::AUDIT_EVENT,
                auditable: $freshOrder,
                oldValues: $oldValues,
                newValues: [
                    ...$this->identitySnapshot($freshOrder),
                    'note' => 'Repaired by legacy identity command',
                ],
            );

            $this->eligibilityService->evaluateAssignmentEligibility($freshOrder, $actor);

            $passesValidation = $this->eligibilityService->passesValidationForOrder($freshOrder);

            if ($passesValidation) {
                $this->automationMonitor->recordValidationPassed($freshOrder, $actor);
            }

            $assignmentStats = $this->assignmentStatistics($order, $assignmentSnapshots);

            Log::info('Legacy identity repair completed.', [
                'order_id' => $freshOrder->order_id,
                'order_db_id' => $freshOrder->id,
                'fields_applied' => array_keys($updates),
                'passes_validation' => $passesValidation,
                ...$assignmentStats,
            ]);

            $failure = $passesValidation
                ? null
                : ($this->isWaitingForCustomerSerial($freshOrder)
                    ? new OrderIdentityRepairFailure(
                        orderId: (string) $freshOrder->order_id,
                        message: (string) config('serial_validation.placeholder_reason', 'Waiting for customer serial'),
                        category: OrderIdentityRepairFailureCategory::WaitingForCustomerSerial,
                    )
                    : new OrderIdentityRepairFailure(
                        orderId: (string) $freshOrder->order_id,
                        message: 'Identity still fails validation after repair.',
                        category: OrderIdentityRepairFailureCategory::ValidationFailed,
                    ));

            return [
                'outcome' => 'repaired',
                'failure' => $failure,
                ...$assignmentStats,
            ];
        });
    }

    private function failureFromFetchResult(Order $order, RadiumBoxOrderEnrichmentFetchResult $fetchResult): OrderIdentityRepairFailure
    {
        $message = $fetchResult->errorMessage ?? 'RadiumBox enrichment lookup failed.';

        if ($fetchResult->isRateLimited()) {
            return new OrderIdentityRepairFailure(
                orderId: (string) $order->order_id,
                message: $message,
                category: OrderIdentityRepairFailureCategory::RateLimited,
            );
        }

        if ($this->isApiTimeoutFailure($fetchResult)) {
            return new OrderIdentityRepairFailure(
                orderId: (string) $order->order_id,
                message: $message,
                category: OrderIdentityRepairFailureCategory::ApiTimeout,
            );
        }

        return new OrderIdentityRepairFailure(
            orderId: (string) $order->order_id,
            message: $message,
            category: OrderIdentityRepairFailureCategory::UnexpectedException,
        );
    }

    private function fetchOrderEnrichmentForRepair(string $orderId): RadiumBoxOrderEnrichmentFetchResult
    {
        $transientAttempts = 0;
        $rateLimitPauses = 0;

        while (true) {
            $result = $this->radiumBoxClient->fetchOrderEnrichmentForBackgroundSync($orderId);

            if ($result->isRateLimited()) {
                $rateLimitPauses++;

                if ($rateLimitPauses > self::MAX_RATE_LIMIT_PAUSES) {
                    return $result;
                }

                $this->pauseBatchForRateLimit($result, $orderId);

                continue;
            }

            if ($result->isTransientFailure()) {
                $transientAttempts++;

                if ($transientAttempts >= self::MAX_FETCH_ATTEMPTS) {
                    return $result;
                }

                $this->pauseSeconds(self::BACKOFF_BASE_SECONDS * (2 ** ($transientAttempts - 1)));

                continue;
            }

            return $result;
        }
    }

    private function pauseBatchForRateLimit(RadiumBoxOrderEnrichmentFetchResult $result, string $orderId): void
    {
        $seconds = $result->retryAfterSeconds;

        if ($seconds === null || $seconds <= 0) {
            $seconds = self::DEFAULT_RATE_LIMIT_PAUSE_SECONDS;
        }

        Log::info('Legacy identity repair batch paused for RadiumBox rate limit.', [
            'order_id' => $orderId,
            'sleep_seconds' => $seconds,
            'http_status' => $result->httpStatus,
            'retry_after_seconds' => $result->retryAfterSeconds,
        ]);

        $this->pauseSeconds($seconds);
    }

    protected function pauseSeconds(int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        Sleep::sleep($seconds);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function recordFailure(array &$summary, OrderIdentityRepairFailure $failure): void
    {
        $summary['failedOrders'][] = $failure;

        match ($failure->category) {
            OrderIdentityRepairFailureCategory::RateLimited => $summary['rateLimited']++,
            OrderIdentityRepairFailureCategory::DuplicateSerial => $summary['duplicateSerials']++,
            OrderIdentityRepairFailureCategory::RadiumBoxNotFound => $summary['notFound']++,
            OrderIdentityRepairFailureCategory::ValidationFailed => $summary['validationFailed']++,
            OrderIdentityRepairFailureCategory::WaitingForCustomerSerial => null,
            OrderIdentityRepairFailureCategory::ApiTimeout,
            OrderIdentityRepairFailureCategory::UnexpectedException => $summary['unexpectedFailures']++,
        };
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function maybeReportProgress(
        array $summary,
        int $processed,
        int $batchTotal,
        ?callable $onProgress,
        bool $force = false,
    ): void {
        if ($onProgress === null) {
            return;
        }

        if (! $force && ($processed === 0 || $processed % self::PROGRESS_INTERVAL !== 0)) {
            return;
        }

        $onProgress(new OrderIdentityRepairProgress(
            processed: $processed,
            batchTotal: $batchTotal,
            repaired: $summary['ordersRepaired'],
            alreadyValid: $summary['ordersAlreadyValid'],
            failed: $summary['ordersFailed'],
            rateLimited: $summary['rateLimited'],
            remaining: max(0, $batchTotal - $processed),
        ));
    }

    private function rememberResumePosition(Order $order, OrderIdentityRepairBatchOptions $options): void
    {
        if ($options->dryRun) {
            return;
        }

        Cache::put(self::RESUME_CACHE_KEY, $order->id, now()->addDays(30));
    }

    private function isApiTimeoutFailure(RadiumBoxOrderEnrichmentFetchResult $fetchResult): bool
    {
        if ($fetchResult->isRateLimited()) {
            return false;
        }

        if (in_array($fetchResult->errorType, ['connection_error', 'request_error'], true)) {
            return true;
        }

        $message = strtolower((string) $fetchResult->errorMessage);

        return str_contains($message, 'timeout')
            || str_contains($message, 'timed out');
    }

    /**
     * @return OrderIdentityRepairFailure
     */
    private function duplicateSerialFailure(Order $order, string $serialNumber): OrderIdentityRepairFailure
    {
        $existingOwner = Order::query()
            ->where('serial_number', $serialNumber)
            ->whereKeyNot($order->id)
            ->value('order_id');

        $message = $existingOwner !== null
            ? sprintf('Serial %s already belongs to order %s.', $serialNumber, $existingOwner)
            : sprintf('Serial %s already belongs to another order.', $serialNumber);

        return new OrderIdentityRepairFailure(
            orderId: (string) $order->order_id,
            message: $message,
            category: OrderIdentityRepairFailureCategory::DuplicateSerial,
        );
    }

    /**
     * @return array<int, int|null>
     */
    private function snapshotAssignments(Order $order): array
    {
        return Incident::query()
            ->where('order_id', $order->id)
            ->whereIn('status', IncidentStatus::operationallyActive())
            ->pluck('assigned_to_user_id', 'id')
            ->map(fn ($assigneeId): ?int => $assigneeId !== null ? (int) $assigneeId : null)
            ->all();
    }

    /**
     * @param  array<int, int|null>  $beforeSnapshots
     * @return array{
     *     assignmentsEscalated: int,
     *     assignmentsToAgent: int,
     *     assignmentsUnchanged: int,
     * }
     */
    private function assignmentStatistics(Order $order, array $beforeSnapshots): array
    {
        $stats = [
            'assignmentsEscalated' => 0,
            'assignmentsToAgent' => 0,
            'assignmentsUnchanged' => 0,
        ];

        $incidents = Incident::query()
            ->where('order_id', $order->id)
            ->whereIn('status', IncidentStatus::operationallyActive())
            ->with('assignee')
            ->get();

        foreach ($incidents as $incident) {
            $beforeId = $beforeSnapshots[$incident->id] ?? null;
            $freshIncident = $incident->fresh(['assignee']);
            $afterId = $freshIncident->assigned_to_user_id;

            if ($beforeId === $afterId) {
                $stats['assignmentsUnchanged']++;

                continue;
            }

            $beforeUser = $beforeId !== null ? User::query()->find($beforeId) : null;
            $afterUser = $freshIncident->assignee;

            if ($beforeUser !== null
                && $this->isAgentUser($beforeUser)
                && $afterUser !== null
                && $this->isAdminUser($afterUser)) {
                $stats['assignmentsEscalated']++;

                continue;
            }

            if ($afterUser !== null && $this->isAgentUser($afterUser)) {
                $stats['assignmentsToAgent']++;

                continue;
            }

            $stats['assignmentsUnchanged']++;
        }

        return $stats;
    }

    private function resolveActor(Order $order): User
    {
        $creatorId = Incident::query()
            ->where('order_id', $order->id)
            ->whereIn('status', IncidentStatus::operationallyActive())
            ->orderBy('id')
            ->value('created_by');

        if ($creatorId !== null) {
            $creator = User::query()->find($creatorId);

            if ($creator !== null) {
                return $creator;
            }
        }

        return $this->automationMonitor->resolveAutomationActor();
    }

    private function hasAutomationValidationFailed(Order $order): bool
    {
        return Incident::query()
            ->where('order_id', $order->id)
            ->whereIn('status', IncidentStatus::operationallyActive())
            ->with(['order', 'assignee'])
            ->get()
            ->contains(function (Incident $incident): bool {
                return $this->automationStatusService->statusFor($incident) === ServiceCaseAutomationStatus::ValidationFailed;
            });
    }

    private function isSerialInvalid(Order $order): bool
    {
        if ($this->isValueMissing($order->serial_number) || $this->isPlaceholderValue($order->serial_number)) {
            return false;
        }

        return $this->serialValidationService
            ->validateForOrder((string) $order->serial_number, $order)
            ->isFail();
    }

    private function isDeviceModelMissing(Order $order): bool
    {
        return ! $order->hasDeviceModelAssigned()
            && $this->isValueMissing($order->device_model);
    }

    private function isProductMissing(Order $order): bool
    {
        return $this->isValueMissing($order->product_name);
    }

    public function isPlaceholderValue(?string $value): bool
    {
        return $this->placeholderService->isPlaceholder($value);
    }

    private function isWaitingForCustomerSerial(Order $order): bool
    {
        return $this->placeholderService->isPlaceholder((string) $order->serial_number);
    }

    private function isValueMissing(?string $value): bool
    {
        return ! filled(trim((string) $value));
    }

    /**
     * @return array<string, mixed>
     */
    private function identitySnapshot(Order $order): array
    {
        return [
            'serial_number' => $order->serial_number,
            'device_model' => $order->device_model,
            'product_name' => $order->product_name,
        ];
    }

    /**
     * @param  array<string, string>  $updates
     */
    private function wouldDuplicateSerial(Order $order, array $updates): bool
    {
        if (! array_key_exists('serial_number', $updates)) {
            return false;
        }

        return Order::query()
            ->where('serial_number', $updates['serial_number'])
            ->whereKeyNot($order->id)
            ->exists();
    }

    /**
     * @return array<string, string>
     */
    private function buildRepairUpdates(Order $order, RadiumBoxOrderEnrichment $enrichment): array
    {
        $updates = [];

        if ($this->shouldReplaceSerial($order) && filled($enrichment->serialNumber)) {
            $serialNumber = strtoupper(trim($enrichment->serialNumber));

            if ($serialNumber !== '' && $serialNumber !== strtoupper(trim((string) $order->serial_number))) {
                $updates['serial_number'] = $serialNumber;
            }
        }

        if ($this->shouldReplaceDeviceModel($order) && filled($enrichment->deviceModel)) {
            if (! $order->hasDeviceModelAssigned() && $enrichment->deviceModel !== $order->device_model) {
                $updates['device_model'] = $enrichment->deviceModel;
            }
        }

        if ($this->shouldReplaceProductName($order) && filled($enrichment->deviceModel)) {
            $updates['product_name'] = $enrichment->deviceModel;
        }

        return $updates;
    }

    private function shouldReplaceSerial(Order $order): bool
    {
        if ($this->isValueMissing($order->serial_number) || $this->isPlaceholderValue($order->serial_number)) {
            return true;
        }

        return $this->isSerialInvalid($order);
    }

    private function shouldReplaceDeviceModel(Order $order): bool
    {
        if ($order->hasDeviceModelAssigned()) {
            return false;
        }

        if ($this->isValueMissing($order->device_model) || $this->isPlaceholderValue($order->device_model)) {
            return true;
        }

        return $this->isSerialInvalid($order);
    }

    private function shouldReplaceProductName(Order $order): bool
    {
        return $this->isValueMissing($order->product_name)
            || $this->isPlaceholderValue($order->product_name);
    }

    private function applyValidationNormalization(Order $order, User $actor): void
    {
        if ($this->isValueMissing($order->serial_number) || $this->isPlaceholderValue($order->serial_number)) {
            return;
        }

        $originalSerial = (string) $order->serial_number;
        $validation = $this->serialValidationService->validateForOrder($originalSerial, $order);

        if ($validation->isFail()) {
            return;
        }

        if ($validation->normalizedSerial === $originalSerial) {
            return;
        }

        $order->update([
            'serial_number' => $validation->normalizedSerial,
        ]);

        if ($validation->corrected) {
            $this->serialValidationService->recordIraCorrection(
                order: $order->fresh(),
                originalSerial: $originalSerial,
                correctedSerial: $validation->normalizedSerial,
                actor: $actor,
            );
        }
    }

    private function isAdminUser(User $user): bool
    {
        return $user->hasAnyRole([
            RolePermissionSeeder::ROLE_ADMIN,
            RolePermissionSeeder::ROLE_SUPERADMIN,
        ]);
    }

    private function isAgentUser(User $user): bool
    {
        return $user->hasRole(RolePermissionSeeder::ROLE_AGENT)
            && ! $this->isAdminUser($user);
    }
}

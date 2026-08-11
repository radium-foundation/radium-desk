<?php

namespace App\Services\Cashfree;

use App\Data\CashfreeWebhookDeferredContext;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OrderStatus;
use App\Events\Finance\OrderPaid;
use App\Models\CashfreeWebhookLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\IncidentReferenceService;
use App\Services\Inquiry\InquiryOrderLinkService;
use App\Services\OrderIdentityLifecycleService;
use App\Services\Outbox\OutboxProcessorService;
use App\Services\Assignment\UniversalAssignmentEngine;
use App\Services\RadiumBox\RadiumBoxOrderSearchResponseMapper;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class CashfreeWebhookProcessorService
{
    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    public const ERROR_SYSTEM_USER_PREFLIGHT = 'Cashfree system user pre-flight failed.';

    public const AUDIT_EVENT_SYSTEM_USER_MISSING = 'cashfree.system_user_missing';

    public const IDENTITY_SOURCE_ORDER_TAGS = 'cashfree_order_tags';

    public const AUDIT_EVENT_ORDER_TAGS_IMPORTED = 'cashfree.order_tags_imported';

    public const AUDIT_EVENT_INVALID_ORDER_TAG = 'cashfree.invalid_order_tag';

    public const AUDIT_EVENT_PAYMENT_LINKED_TO_EXISTING_ORDER = 'cashfree.payment_linked_to_existing_order';

    /** Matches Laravel default string columns for product_name / device_model. */
    private const ORDER_TAG_STRING_MAX_LENGTH = 255;

    public function __construct(
        private readonly CashfreeWebhookPayloadParser $payloadParser,
        private readonly IncidentReferenceService $incidentReferenceService,
        private readonly UniversalAssignmentEngine $assignmentEngine,
        private readonly CashfreeWebhookOutboxWriter $outboxWriter,
        private readonly OutboxProcessorService $outboxProcessorService,
        private readonly CashfreeWebhookReliabilityMetrics $reliabilityMetrics,
        private readonly InquiryOrderLinkService $inquiryOrderLinkService,
        private readonly CashfreeHealthService $cashfreeHealthService,
        private readonly AuditLogService $auditLogService,
        private readonly RadiumBoxOrderSearchResponseMapper $fieldNormalizer,
        private readonly OrderIdentityLifecycleService $identityLifecycle,
    ) {}

    public function process(CashfreeWebhookLog $webhookLog): CashfreeWebhookLog
    {
        $payload = $webhookLog->request_payload ?? [];

        $webhookLog->update([
            'cf_payment_id' => $this->payloadParser->cfPaymentId($payload),
        ]);

        if (! $this->payloadParser->isSuccessfulPayment($payload)) {
            return $webhookLog->fresh();
        }

        try {
            $this->assertSystemUserPreflight($webhookLog);
            $deferredContext = $this->persistSuccessfulPayment($webhookLog, $payload);
        } catch (Throwable $exception) {
            $this->markWebhookFailed($webhookLog, $exception);

            return $webhookLog->fresh();
        }

        if ($deferredContext !== null) {
            $this->dispatchDeferredOperationsSafely($webhookLog, $deferredContext);

            $order = Order::query()->find($deferredContext->orderId);
            if ($order !== null) {
                OrderPaid::dispatch($order);
            }
        }

        return $webhookLog->fresh(['incident']);
    }

    /**
     * Persist order, incident, webhook status, and pending outbox events.
     * Retries transient MySQL deadlock / lock-wait failures.
     * SC references are allocated outside the payment transaction so the
     * sequence row lock is not held across order/incident/outbox writes.
     */
    private function persistSuccessfulPayment(
        CashfreeWebhookLog $webhookLog,
        array $payload,
    ): ?CashfreeWebhookDeferredContext {
        $maxAttempts = max(1, (int) config('cashfree.persist_retry.max_attempts', 3));
        $sleepMilliseconds = max(0, (int) config('cashfree.persist_retry.sleep_milliseconds', 100));
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $this->attemptPersistSuccessfulPayment($webhookLog, $payload);
            } catch (QueryException $exception) {
                if ($this->isDuplicateOrderIdViolation($exception)) {
                    $resolved = $this->attemptRecoverFromDuplicateOrderId($webhookLog, $payload);

                    if ($resolved !== null) {
                        return $resolved;
                    }
                }

                if (! $this->isRetryableContention($exception) || $attempt >= $maxAttempts) {
                    throw $exception;
                }

                Log::warning('[Cashfree Webhook] Retrying payment persistence after DB contention.', [
                    'webhook_log_id' => $webhookLog->id,
                    'cf_payment_id' => $webhookLog->cf_payment_id,
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'error_code' => $exception->errorInfo[1] ?? null,
                    'message' => $exception->getMessage(),
                ]);

                if ($sleepMilliseconds > 0) {
                    usleep($sleepMilliseconds * 1000 * $attempt);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function attemptPersistSuccessfulPayment(
        CashfreeWebhookLog $webhookLog,
        array $payload,
    ): ?CashfreeWebhookDeferredContext {
        $cfPaymentId = $this->payloadParser->cfPaymentId($payload);

        if ($cfPaymentId === null) {
            throw new RuntimeException('Cashfree webhook payload is missing cf_payment_id.');
        }

        $existingIncident = $this->findExistingIncidentForPayment($cfPaymentId);

        if ($existingIncident !== null) {
            $this->markProcessed($webhookLog, $existingIncident);

            return null;
        }

        $businessOrderId = $this->payloadParser->orderId($payload);
        $existingOrder = $businessOrderId !== null
            ? $this->findExistingOrderByBusinessOrderId($businessOrderId)
            : null;

        if ($existingOrder !== null) {
            return $this->linkPaymentToExistingOrder($webhookLog, $payload, $cfPaymentId, $existingOrder);
        }

        // System user is pre-flighted in process(); resolve again for the unit of work.
        $systemUser = $this->cashfreeHealthService->assertSystemUserReady();

        // Allocate SC outside the payment unit-of-work so reference_sequences
        // FOR UPDATE is released before order/incident/outbox writes begin.
        $referenceNo = $this->incidentReferenceService->generate();

        return DB::transaction(function () use ($webhookLog, $payload, $cfPaymentId, $referenceNo, $systemUser): ?CashfreeWebhookDeferredContext {
            $existingIncident = $this->findExistingIncidentForPayment($cfPaymentId);

            if ($existingIncident !== null) {
                $this->markProcessed($webhookLog, $existingIncident);

                return null;
            }

            $businessOrderId = $this->payloadParser->orderId($payload);
            $existingOrder = $businessOrderId !== null
                ? $this->findExistingOrderByBusinessOrderId($businessOrderId)
                : null;

            if ($existingOrder !== null) {
                return $this->linkPaymentToExistingOrder($webhookLog, $payload, $cfPaymentId, $existingOrder);
            }

            $created = $this->createOrder($payload, $cfPaymentId, $systemUser);
            $order = $created['order'];
            $importedFields = $created['imported_fields'];
            $invalidTags = $created['invalid_tags'];
            $phone = $this->payloadParser->customerPhone($payload);
            $linkableIncident = $this->inquiryOrderLinkService->findLinkableInquiryIncident($order, $phone);

            if ($linkableIncident !== null) {
                $incident = $this->inquiryOrderLinkService->linkToOrder($linkableIncident, $order, $systemUser);
            } else {
                $incident = $this->createServiceRequest($order, $payload, $systemUser, $referenceNo);
            }

            $this->finalizeOrderTagIdentity(
                order: $order,
                systemUser: $systemUser,
                importedFields: $importedFields,
                invalidTags: $invalidTags,
                cfPaymentId: $cfPaymentId,
            );

            $this->markProcessed($webhookLog, $incident);
            $this->reliabilityMetrics->recordOrderCreated();

            $deferredContext = new CashfreeWebhookDeferredContext(
                orderId: $order->id,
                incidentId: $incident->id,
                actorId: $systemUser->id,
            );

            $this->outboxWriter->writeDeferredOperations($deferredContext);

            return $deferredContext;
        });
    }

    private function isRetryableContention(QueryException $exception): bool
    {
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        // MySQL / MariaDB: 1213 deadlock, 1205 lock wait timeout.
        if (in_array($driverCode, [1213, 1205], true)) {
            return true;
        }

        $message = $exception->getMessage();

        return str_contains($message, '1213')
            || str_contains($message, 'Deadlock')
            || str_contains($message, '1205')
            || str_contains($message, 'Lock wait timeout');
    }

    private function isDuplicateOrderIdViolation(QueryException $exception): bool
    {
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        if ($driverCode === 1062) {
            return true;
        }

        return str_contains($exception->getMessage(), '1062')
            && str_contains($exception->getMessage(), 'orders_order_id_unique');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function attemptRecoverFromDuplicateOrderId(
        CashfreeWebhookLog $webhookLog,
        array $payload,
    ): ?CashfreeWebhookDeferredContext {
        $businessOrderId = $this->payloadParser->orderId($payload);

        if ($businessOrderId === null) {
            return null;
        }

        $existingOrder = $this->findExistingOrderByBusinessOrderId($businessOrderId);

        if ($existingOrder === null) {
            return null;
        }

        $cfPaymentId = $this->payloadParser->cfPaymentId($payload);

        if ($cfPaymentId === null) {
            return null;
        }

        $systemUser = $this->cashfreeHealthService->assertSystemUserReady();

        return $this->linkPaymentToExistingOrder($webhookLog, $payload, $cfPaymentId, $existingOrder, $systemUser);
    }

    private function findExistingOrderByBusinessOrderId(string $businessOrderId): ?Order
    {
        $normalizedOrderId = strtoupper(trim($businessOrderId));

        if ($normalizedOrderId === '') {
            return null;
        }

        return Order::query()
            ->whereRaw('UPPER(order_id) = ?', [$normalizedOrderId])
            ->first();
    }

    /**
     * Link Cashfree payment metadata onto a pre-existing Desk order (e.g. legacy import)
     * without creating a duplicate order or service case.
     *
     * @param  array<string, mixed>  $payload
     */
    private function linkPaymentToExistingOrder(
        CashfreeWebhookLog $webhookLog,
        array $payload,
        string $cfPaymentId,
        Order $existingOrder,
        ?User $systemUser = null,
    ): ?CashfreeWebhookDeferredContext {
        return DB::transaction(function () use ($webhookLog, $payload, $cfPaymentId, $existingOrder, $systemUser): ?CashfreeWebhookDeferredContext {
            $existingIncident = $this->findExistingIncidentForPayment($cfPaymentId);

            if ($existingIncident !== null) {
                $this->markProcessed($webhookLog, $existingIncident);

                return null;
            }

            $order = Order::query()->whereKey($existingOrder->id)->lockForUpdate()->first();

            if ($order === null) {
                throw new RuntimeException('Existing Desk order disappeared during Cashfree payment link.');
            }

            $actor = $systemUser ?? $this->cashfreeHealthService->assertSystemUserReady();
            $linkedOrder = $this->applyCashfreePaymentFieldsToExistingOrder($order, $payload, $cfPaymentId, $actor);

            $incident = $linkedOrder->latestIncident();

            if ($incident === null) {
                throw new RuntimeException(
                    'Cashfree payment cannot be linked because Desk order '.$linkedOrder->order_id.' has no service case.',
                );
            }

            $this->auditLogService->log(
                userId: $actor->id,
                event: self::AUDIT_EVENT_PAYMENT_LINKED_TO_EXISTING_ORDER,
                auditable: $linkedOrder,
                oldValues: null,
                newValues: [
                    'webhook_log_id' => $webhookLog->id,
                    'cf_payment_id' => $cfPaymentId,
                    'incident_id' => $incident->id,
                    'order_id' => $linkedOrder->order_id,
                ],
            );

            $this->markProcessed($webhookLog, $incident);

            return null;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyCashfreePaymentFieldsToExistingOrder(
        Order $order,
        array $payload,
        string $cfPaymentId,
        User $systemUser,
    ): Order {
        $existingPaymentId = trim((string) ($order->cashfree_payment_id ?? ''));

        if ($existingPaymentId !== '' && $existingPaymentId !== $cfPaymentId) {
            throw new RuntimeException(sprintf(
                'Desk order %s is already linked to a different Cashfree payment.',
                $order->order_id,
            ));
        }

        $paymentDate = $this->payloadParser->paymentDate($payload);
        $updates = [
            'updated_by' => $systemUser->id,
        ];

        if ($existingPaymentId === '') {
            $updates['cashfree_payment_id'] = $cfPaymentId;
        }

        $nullablePaymentFields = [
            'payment_amount' => $this->payloadParser->paymentAmount($payload),
            'payment_method' => $this->payloadParser->paymentMethod($payload),
            'bank_reference' => $this->payloadParser->bankReference($payload),
            'gateway_order_id' => $this->payloadParser->gatewayOrderId($payload),
            'gateway_payment_id' => $this->payloadParser->gatewayPaymentId($payload),
        ];

        foreach ($nullablePaymentFields as $column => $value) {
            if ($value === null) {
                continue;
            }

            if (blank($order->{$column})) {
                $updates[$column] = $value;
            }
        }

        if ($paymentDate !== null && $order->payment_date === null) {
            $updates['payment_date'] = Carbon::parse($paymentDate);
        }

        if (count($updates) > 1) {
            $order->update($updates);
        }

        return $order->fresh() ?? $order;
    }

    private function dispatchDeferredOperationsSafely(
        CashfreeWebhookLog $webhookLog,
        CashfreeWebhookDeferredContext $deferredContext,
    ): void {
        try {
            // Cashfree writes exactly three deferred rows for this incident aggregate.
            // One processAggregate() claim-all drains only that aggregate — never global
            // FIFO (Interakt / BonVoice / email / WhatsApp / unrelated Cashfree rows)
            // and prevents cron outbox:process from stealing siblings mid-drain.
            $this->outboxProcessorService->processAggregate(
                CashfreeWebhookOutboxWriter::AGGREGATE_TYPE,
                $deferredContext->incidentId,
            );
        } catch (Throwable $exception) {
            Log::error('[Cashfree Webhook] Deferred operation dispatch failed after payment commit.', [
                'webhook_log_id' => $webhookLog->id,
                'cf_payment_id' => $webhookLog->cf_payment_id,
                'order_id' => $deferredContext->orderId,
                'incident_id' => $deferredContext->incidentId,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
        }
    }

    private function assertSystemUserPreflight(CashfreeWebhookLog $webhookLog): void
    {
        $check = $this->cashfreeHealthService->systemUserCheck();

        if ($check['status'] === CashfreeHealthService::SYSTEM_USER_STATUS_HEALTHY) {
            return;
        }

        $error = (string) ($check['failure'] ?? self::ERROR_SYSTEM_USER_PREFLIGHT);

        $this->auditLogService->log(
            userId: null,
            event: self::AUDIT_EVENT_SYSTEM_USER_MISSING,
            auditable: $webhookLog,
            oldValues: null,
            newValues: [
                'severity' => 'high',
                'configured_email' => $check['email'] ?: null,
                'system_user_status' => $check['status'],
                'processing_error' => $error,
                'cf_payment_id' => $webhookLog->cf_payment_id,
            ],
        );

        throw new RuntimeException($error);
    }

    private function markWebhookFailed(CashfreeWebhookLog $webhookLog, Throwable $exception): void
    {
        $webhookLog->update([
            'processing_status' => self::STATUS_FAILED,
            'processing_error' => $exception->getMessage(),
            'processed_at' => now(),
        ]);
    }

    private function findExistingIncidentForPayment(string $cfPaymentId): ?Incident
    {
        $existingOrder = Order::query()
            ->where('cashfree_payment_id', $cfPaymentId)
            ->first();

        if ($existingOrder !== null) {
            return $existingOrder->latestIncident();
        }

        $existingLog = CashfreeWebhookLog::query()
            ->where('cf_payment_id', $cfPaymentId)
            ->whereNotNull('incident_id')
            ->where('processing_status', self::STATUS_PROCESSED)
            ->latest('id')
            ->first();

        if ($existingLog?->incident_id !== null) {
            return Incident::query()->find($existingLog->incident_id);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     order: Order,
     *     imported_fields: array<string, mixed>,
     *     invalid_tags: list<array{tag: string, reason: string, raw: string|null, normalized: string|null}>
     * }
     */
    private function createOrder(array $payload, string $cfPaymentId, User $systemUser): array
    {
        $orderId = $this->payloadParser->orderId($payload);

        if ($orderId === null) {
            throw new RuntimeException('Cashfree webhook payload is missing order_id.');
        }

        $paymentDate = $this->payloadParser->paymentDate($payload);
        $identity = $this->resolveOrderTagIdentity($payload, $systemUser);

        $order = Order::query()->create([
            'order_id' => $orderId,
            'customer_name' => $this->payloadParser->customerName($payload),
            'customer_email' => $this->payloadParser->customerEmail($payload),
            'customer_phone' => $this->payloadParser->customerPhone($payload),
            'serial_number' => $identity['attributes']['serial_number'] ?? null,
            'serial_entered_at' => $identity['attributes']['serial_entered_at'] ?? null,
            'serial_entered_by_user_id' => $identity['attributes']['serial_entered_by_user_id'] ?? null,
            'product_name' => $identity['attributes']['product_name'] ?? null,
            'device_model' => $identity['attributes']['device_model'] ?? null,
            'service_history' => $identity['attributes']['service_history'] ?? null,
            'cashfree_payment_id' => $cfPaymentId,
            'payment_amount' => $this->payloadParser->paymentAmount($payload),
            'payment_method' => $this->payloadParser->paymentMethod($payload),
            'payment_date' => $paymentDate !== null ? Carbon::parse($paymentDate) : null,
            'bank_reference' => $this->payloadParser->bankReference($payload),
            'gateway_order_id' => $this->payloadParser->gatewayOrderId($payload),
            'gateway_payment_id' => $this->payloadParser->gatewayPaymentId($payload),
            'status' => OrderStatus::Active,
            'created_by' => $systemUser->id,
            'updated_by' => $systemUser->id,
        ]);

        return [
            'order' => $order,
            'imported_fields' => $identity['imported_fields'],
            'invalid_tags' => $identity['invalid_tags'],
        ];
    }

    /**
     * Order tags are optional metadata. Invalid / oversized / conflicting tags
     * must never abort payment persistence — store null and audit instead.
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *     attributes: array<string, mixed>,
     *     imported_fields: array<string, mixed>,
     *     invalid_tags: list<array{tag: string, reason: string, raw: string|null, normalized: string|null}>
     * }
     */
    private function resolveOrderTagIdentity(array $payload, User $systemUser): array
    {
        $attributes = [];
        $importedFields = [];
        $invalidTags = [];

        $rawProductName = $this->payloadParser->orderTagProductName($payload);

        if ($rawProductName !== null) {
            $productName = $this->fieldNormalizer->normalizeOptionalString($rawProductName);

            if ($productName === null) {
                $invalidTags[] = $this->invalidOrderTag(
                    tag: 'product_name',
                    reason: 'invalid_after_normalize',
                    raw: $rawProductName,
                );
            } elseif (strlen($productName) > self::ORDER_TAG_STRING_MAX_LENGTH) {
                $invalidTags[] = $this->invalidOrderTag(
                    tag: 'product_name',
                    reason: 'exceeds_max_length',
                    raw: $rawProductName,
                    normalized: $productName,
                );
            } else {
                $attributes['product_name'] = $productName;
                $attributes['device_model'] = $productName;
                $importedFields['product_name'] = $productName;
                $importedFields['device_model'] = $productName;
            }
        }

        $rawServiceName = $this->payloadParser->orderTagRdServiceName($payload);

        if ($rawServiceName !== null) {
            $serviceHistory = $this->fieldNormalizer->normalizeHistory($rawServiceName);
            $serviceEntry = is_array($serviceHistory) ? ($serviceHistory[0] ?? null) : null;

            if ($serviceHistory === null || ! is_string($serviceEntry) || $serviceEntry === '') {
                $invalidTags[] = $this->invalidOrderTag(
                    tag: 'rd_service_name',
                    reason: 'invalid_after_normalize',
                    raw: $rawServiceName,
                );
            } elseif (strlen($serviceEntry) > self::ORDER_TAG_STRING_MAX_LENGTH) {
                $invalidTags[] = $this->invalidOrderTag(
                    tag: 'rd_service_name',
                    reason: 'exceeds_max_length',
                    raw: $rawServiceName,
                    normalized: $serviceEntry,
                );
            } else {
                $attributes['service_history'] = $serviceHistory;
                $importedFields['service_history'] = $serviceHistory;
                $importedFields['rd_service_name'] = $serviceEntry;
            }
        }

        $rawSerial = $this->payloadParser->orderTagSerialNo($payload);

        if ($rawSerial !== null) {
            $serialNumber = $this->fieldNormalizer->normalizeSerialNumber($rawSerial);

            if ($serialNumber === null) {
                $invalidTags[] = $this->invalidOrderTag(
                    tag: 'serial_no',
                    reason: 'invalid_after_normalize',
                    raw: $rawSerial,
                );
            } else {
                $existingOwner = Order::query()
                    ->where('serial_number', $serialNumber)
                    ->first(['id', 'order_id', 'serial_number']);

                if ($existingOwner !== null) {
                    Log::warning('[Cashfree Webhook] Skipping order_tags serial_no; already owned by another order.', [
                        'serial_number' => $serialNumber,
                        'existing_owner_order_id' => $existingOwner->order_id,
                        'existing_owner_db_id' => $existingOwner->id,
                        'incoming_order_id' => $this->payloadParser->orderId($payload),
                    ]);

                    $invalidTags[] = $this->invalidOrderTag(
                        tag: 'serial_no',
                        reason: 'serial_already_owned',
                        raw: $rawSerial,
                        normalized: $serialNumber,
                    );
                } else {
                    $attributes['serial_number'] = $serialNumber;
                    $attributes['serial_entered_at'] = now();
                    $attributes['serial_entered_by_user_id'] = $systemUser->id;
                    $importedFields['serial_number'] = $serialNumber;
                }
            }
        }

        return [
            'attributes' => $attributes,
            'imported_fields' => $importedFields,
            'invalid_tags' => $invalidTags,
        ];
    }

    /**
     * @return array{tag: string, reason: string, raw: string|null, normalized: string|null}
     */
    private function invalidOrderTag(
        string $tag,
        string $reason,
        ?string $raw,
        ?string $normalized = null,
    ): array {
        return [
            'tag' => $tag,
            'reason' => $reason,
            'raw' => $this->truncateForAudit($raw),
            'normalized' => $this->truncateForAudit($normalized),
        ];
    }

    private function truncateForAudit(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return strlen($value) > 200 ? substr($value, 0, 200).'…' : $value;
    }

    /**
     * @param  array<string, mixed>  $importedFields
     * @param  list<array{tag: string, reason: string, raw: string|null, normalized: string|null}>  $invalidTags
     */
    private function finalizeOrderTagIdentity(
        Order $order,
        User $systemUser,
        array $importedFields,
        array $invalidTags = [],
        ?string $cfPaymentId = null,
    ): void {
        if ($importedFields !== []) {
            $this->auditLogService->log(
                userId: $systemUser->id,
                event: self::AUDIT_EVENT_ORDER_TAGS_IMPORTED,
                auditable: $order,
                oldValues: null,
                newValues: [
                    'source' => self::IDENTITY_SOURCE_ORDER_TAGS,
                    'fields' => array_keys($importedFields),
                    ...$importedFields,
                ],
            );

            $this->identityLifecycle->afterOrderCreatedWithIdentity(
                order: $order->fresh() ?? $order,
                actor: $systemUser,
                source: self::IDENTITY_SOURCE_ORDER_TAGS,
            );
        }

        foreach ($invalidTags as $invalidTag) {
            $this->auditLogService->log(
                userId: $systemUser->id,
                event: self::AUDIT_EVENT_INVALID_ORDER_TAG,
                auditable: $order,
                oldValues: null,
                newValues: [
                    'source' => self::IDENTITY_SOURCE_ORDER_TAGS,
                    'order_id' => $order->order_id,
                    'cf_payment_id' => $cfPaymentId,
                    ...$invalidTag,
                ],
            );

            Log::warning('[Cashfree Webhook] Ignoring invalid optional order_tag; payment continues.', [
                'order_id' => $order->order_id,
                'cf_payment_id' => $cfPaymentId,
                'tag' => $invalidTag['tag'],
                'reason' => $invalidTag['reason'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createServiceRequest(
        Order $order,
        array $payload,
        User $systemUser,
        string $referenceNo,
    ): Incident {
        $orderId = $this->payloadParser->orderId($payload) ?? $order->order_id;

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'order_record_id' => $order->id,
            'reference_no' => $referenceNo,
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Cashfree payment — '.$orderId,
            'description' => 'Automatically created from Cashfree payment webhook. Awaiting product details.',
            'status' => IncidentStatus::AwaitingProductDetails,
            'high_priority' => false,
            'created_by' => $systemUser->id,
            'updated_by' => $systemUser->id,
        ]);

        return $this->assignmentEngine->assignOnCreate($incident, $systemUser);
    }

    private function markProcessed(CashfreeWebhookLog $webhookLog, Incident $incident): CashfreeWebhookLog
    {
        $webhookLog->update([
            'incident_id' => $incident->id,
            'processing_status' => self::STATUS_PROCESSED,
            'processing_error' => null,
            'processed_at' => now(),
        ]);

        return $webhookLog->fresh(['incident']);
    }

}


<?php

namespace App\Services\Cashfree;

use App\Data\CashfreeWebhookDeferredContext;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OrderStatus;
use App\Models\CashfreeWebhookLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Outbox\OutboxProcessorService;
use App\Services\ServiceCaseAssignmentService;
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

    public function __construct(
        private readonly CashfreeWebhookPayloadParser $payloadParser,
        private readonly IncidentReferenceService $incidentReferenceService,
        private readonly ServiceCaseAssignmentService $serviceCaseAssignmentService,
        private readonly CashfreeWebhookOutboxWriter $outboxWriter,
        private readonly OutboxProcessorService $outboxProcessorService,
        private readonly CashfreeWebhookReliabilityMetrics $reliabilityMetrics,
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
            $deferredContext = $this->persistSuccessfulPayment($webhookLog, $payload);
        } catch (Throwable $exception) {
            $this->markWebhookFailed($webhookLog, $exception);

            return $webhookLog->fresh();
        }

        if ($deferredContext !== null) {
            $this->dispatchDeferredOperationsSafely($webhookLog, $deferredContext);
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

        // Allocate SC outside the payment unit-of-work so reference_sequences
        // FOR UPDATE is released before order/incident/outbox writes begin.
        $referenceNo = $this->incidentReferenceService->generate();
        $systemUser = $this->resolveSystemUser();

        return DB::transaction(function () use ($webhookLog, $payload, $cfPaymentId, $referenceNo, $systemUser): ?CashfreeWebhookDeferredContext {
            $existingIncident = $this->findExistingIncidentForPayment($cfPaymentId);

            if ($existingIncident !== null) {
                $this->markProcessed($webhookLog, $existingIncident);

                return null;
            }

            $order = $this->createOrder($payload, $cfPaymentId, $systemUser);
            $incident = $this->createServiceRequest($order, $payload, $systemUser, $referenceNo);

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

    private function dispatchDeferredOperationsSafely(
        CashfreeWebhookLog $webhookLog,
        CashfreeWebhookDeferredContext $deferredContext,
    ): void {
        try {
            $this->outboxProcessorService->process();
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
     */
    private function createOrder(array $payload, string $cfPaymentId, User $systemUser): Order
    {
        $orderId = $this->payloadParser->orderId($payload);

        if ($orderId === null) {
            throw new RuntimeException('Cashfree webhook payload is missing order_id.');
        }

        $paymentDate = $this->payloadParser->paymentDate($payload);

        return Order::query()->create([
            'order_id' => $orderId,
            'customer_name' => $this->payloadParser->customerName($payload),
            'customer_email' => $this->payloadParser->customerEmail($payload),
            'customer_phone' => $this->payloadParser->customerPhone($payload),
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
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

        return $this->serviceCaseAssignmentService->assignOnCreate($incident, $systemUser);
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

    private function resolveSystemUser(): User
    {
        $email = (string) config('cashfree.system_user_email');

        $user = User::query()->where('email', $email)->first();

        if ($user === null || $user->trashed() || ! $user->is_active) {
            throw new RuntimeException('Cashfree system user is not configured or inactive.');
        }

        return $user;
    }
}

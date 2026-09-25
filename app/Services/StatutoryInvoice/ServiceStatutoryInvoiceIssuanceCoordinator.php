<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\OutboxEventStatus;
use App\Enums\StatutoryInvoice\ServiceStatutoryInvoiceMintTrigger;
use App\Models\CommerceOrder;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Attempts immediate service statutory mint and enqueues durable outbox retries on failure.
 */
final class ServiceStatutoryInvoiceIssuanceCoordinator
{
    public function __construct(
        private readonly StatutoryInvoiceService $invoices,
        private readonly ServiceStatutoryInvoiceMintOutboxWriter $outboxWriter,
        private readonly ServiceStatutoryInvoiceMintFailureClassifier $classifier,
        private readonly ServiceStatutoryGstMismatchExceptionService $gstMismatchExceptions,
    ) {}

    public function attempt(
        Order $order,
        ?User $actor,
        ServiceStatutoryInvoiceMintTrigger $trigger,
    ): void {
        if (! (bool) config('service_statutory_invoice.mint_retry.enabled', true)) {
            $this->attemptImmediateOnly($order, $actor, $trigger);

            return;
        }

        try {
            $this->invoices->issueFromSupportOrder($order, $actor);
            $this->logResult($order, $trigger, 1, 'success', null);
            $this->completePendingOutbox($order);
        } catch (Throwable $exception) {
            $classified = $this->classifier->toRetryableException($exception);
            $this->logResult($order, $trigger, 1, 'failure', $classified);

            if ($classified instanceof ServiceStatutoryInvoicePermanentFailureException) {
                $this->outboxWriter->recordPermanentFailure(
                    $order,
                    $trigger,
                    $classified->getMessage(),
                    $actor?->id,
                );
                $this->openGstMismatchExceptionIfApplicable($order, $classified->getMessage());

                return;
            }

            $this->outboxWriter->enqueue($order, $trigger, $actor?->id);
        }
    }

    private function attemptImmediateOnly(
        Order $order,
        ?User $actor,
        ServiceStatutoryInvoiceMintTrigger $trigger,
    ): void {
        try {
            $this->invoices->issueFromSupportOrder($order, $actor);
            $this->logResult($order, $trigger, 1, 'success', null);
        } catch (Throwable $exception) {
            $classified = $this->classifier->toRetryableException($exception);
            $this->logResult($order, $trigger, 1, 'failure', $classified);
        }
    }

    private function completePendingOutbox(Order $order): void
    {
        $event = OutboxEvent::query()
            ->where('idempotency_key', ServiceStatutoryInvoiceMintOutboxWriter::idempotencyKeyForOrder($order->id))
            ->where('event_type', ServiceStatutoryInvoiceMintOutboxWriter::EVENT_TYPE)
            ->where('status', '!=', OutboxEventStatus::Completed)
            ->first();

        if ($event === null) {
            return;
        }

        $event->update([
            'status' => OutboxEventStatus::Completed,
            'processed_at' => now(),
            'last_error' => null,
        ]);
    }

    private function openGstMismatchExceptionIfApplicable(Order $order, string $errorMessage): void
    {
        $commerce = CommerceOrder::query()
            ->where('support_order_id', $order->id)
            ->orWhere('source_id', $order->order_id)
            ->orderByDesc('id')
            ->first();

        if ($commerce === null) {
            return;
        }

        $this->gstMismatchExceptions->openFromMintFailure($order, $commerce, $errorMessage);
    }

    private function logResult(
        Order $order,
        ServiceStatutoryInvoiceMintTrigger $trigger,
        int $attempt,
        string $result,
        ?Throwable $exception,
    ): void {
        $context = [
            'order_pk' => $order->id,
            'order_id' => $order->order_id,
            'trigger' => $trigger->value,
            'attempt' => $attempt,
            'result' => $result,
        ];

        if ($exception !== null) {
            $context['exception'] = $exception::class;
            if ($exception instanceof ServiceStatutoryInvoicePermanentFailureException) {
                $context['classification'] = $exception->classification;
            } elseif ($exception instanceof ServiceStatutoryInvoiceTemporaryFailureException) {
                $context['classification'] = $exception->classification;
            }
            $context['message'] = $exception->getMessage();
            Log::warning('service_statutory_invoice.workflow_issue_failed', $context);

            return;
        }

        Log::info('service_statutory_invoice.workflow_issue_succeeded', $context);
    }
}

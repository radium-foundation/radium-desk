<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoice\ServiceStatutoryInvoiceMintTrigger;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Support\BusinessOrderId;
use Illuminate\Support\Facades\Log;
use Throwable;

class ServiceStatutoryInvoiceMintProcessor
{
    public function __construct(
        private readonly StatutoryInvoiceService $invoices,
        private readonly ServiceStatutoryInvoiceMintFailureClassifier $classifier,
    ) {}

    public function process(OutboxEvent $event): void
    {
        $payload = $event->payload ?? [];
        $orderPk = (int) ($payload['order_pk'] ?? 0);
        $triggerValue = (string) ($payload['trigger'] ?? ServiceStatutoryInvoiceMintTrigger::InvoiceRetry->value);
        $actorId = isset($payload['actor_id']) ? (int) $payload['actor_id'] : null;
        $attempt = max(1, (int) $event->attempts);

        if ($orderPk <= 0) {
            throw new ServiceStatutoryInvoicePermanentFailureException(
                'Service mint outbox event is missing order_pk.',
                classification: 'invalid_payload',
            );
        }

        $order = Order::query()->find($orderPk);
        if ($order === null) {
            throw new ServiceStatutoryInvoicePermanentFailureException(
                'Support order not found: '.$orderPk,
                classification: 'missing_order',
            );
        }

        $trigger = ServiceStatutoryInvoiceMintTrigger::tryFrom($triggerValue)
            ?? ServiceStatutoryInvoiceMintTrigger::InvoiceRetry;
        $actor = $actorId !== null && $actorId > 0 ? User::query()->find($actorId) : null;

        if ($this->invoiceAlreadyExists($order)) {
            $this->logAttempt($order, $trigger, $attempt, 'already_invoiced', null);

            return;
        }

        try {
            $this->invoices->issueFromSupportOrder($order, $actor);
            $this->logAttempt($order, $trigger, $attempt, 'success', null);
        } catch (Throwable $exception) {
            $this->logAttempt($order, $trigger, $attempt, 'failure', $exception);
            throw $this->classifier->toRetryableException($exception);
        }
    }

    private function invoiceAlreadyExists(Order $order): bool
    {
        $sourceId = trim((string) $order->order_id);
        if ($sourceId === '') {
            return false;
        }

        return $this->invoices->findBySource(
            $this->resolveChannel($sourceId),
            StatutoryInvoiceSourceType::CommerceOrder,
            $sourceId,
        ) !== null;
    }

    private function logAttempt(
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
            $classified = $this->classifier->toRetryableException($exception);
            $context['exception'] = $classified::class;
            $context['classification'] = $classified instanceof ServiceStatutoryInvoicePermanentFailureException
                ? $classified->classification
                : ($classified instanceof ServiceStatutoryInvoiceTemporaryFailureException
                    ? $classified->classification
                    : 'unknown');
            $context['message'] = $classified->getMessage();

            Log::warning('service_statutory_invoice.mint_attempt', $context);

            return;
        }

        Log::info('service_statutory_invoice.mint_attempt', $context);
    }

    private function resolveChannel(string $sourceId): StatutoryInvoiceChannel
    {
        if (BusinessOrderId::isRadiumBoxService($sourceId)) {
            return StatutoryInvoiceChannel::RadiumBoxCom;
        }

        $parsed = BusinessOrderId::parse($sourceId);
        if ($parsed !== null && $parsed['owner'] === 'rdservice.net') {
            return StatutoryInvoiceChannel::RdServiceNet;
        }

        return StatutoryInvoiceChannel::RdServiceIn;
    }
}

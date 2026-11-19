<?php

namespace App\Services\Customer360;

use App\Enums\OutboxEventStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Services\StatutoryInvoice\ServiceStatutoryInvoiceMintOutboxErrorSanitizer;
use App\Services\StatutoryInvoice\ServiceStatutoryInvoiceMintOutboxWriter;
use App\Services\StatutoryInvoice\StatutoryInvoiceForIncidentResolver;
use App\Support\AppDateFormatter;

class Customer360StatutoryMintOutboxPresenter
{
    public function __construct(
        private readonly StatutoryInvoiceForIncidentResolver $resolver,
        private readonly ServiceStatutoryInvoiceMintOutboxErrorSanitizer $errorSanitizer,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function forIncident(Incident $incident, ?User $user): ?array
    {
        if ($user === null || $user->cannot('view', $incident)) {
            return null;
        }

        $incident->loadMissing('order');
        $order = $incident->order;
        if (! $order instanceof Order) {
            return null;
        }

        $hasInvoice = $this->resolver->forIncident($incident)->isNotEmpty();

        $outbox = OutboxEvent::query()
            ->where('idempotency_key', ServiceStatutoryInvoiceMintOutboxWriter::idempotencyKeyForOrder($order->id))
            ->where('event_type', ServiceStatutoryInvoiceMintOutboxWriter::EVENT_TYPE)
            ->first();

        if ($hasInvoice && ($outbox === null || $outbox->status === OutboxEventStatus::Completed)) {
            return null;
        }

        return $this->present($incident, $order, $outbox, $hasInvoice);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Incident $incident, Order $order, ?OutboxEvent $outbox, bool $hasInvoice): array
    {
        $status = $outbox?->status;
        $statusValue = $status?->value;
        $failureReason = $status === OutboxEventStatus::Failed
            ? $this->errorSanitizer->sanitize($outbox?->last_error)
            : null;

        return [
            'outbox_record_present' => $outbox !== null,
            'statutory_invoice_exists' => $hasInvoice,
            'order_reference' => $order->order_id,
            'service_case_reference' => $incident->reference_no,
            'outbox_status' => $statusValue,
            'status_label' => $this->statusLabel($status, $outbox !== null),
            'updated_at_label' => AppDateFormatter::datetime($outbox?->updated_at ?? $outbox?->created_at),
            'failure_reason' => $failureReason,
            'empty_summary' => $this->emptySummary($hasInvoice, $outbox, $status),
        ];
    }

    private function statusLabel(?OutboxEventStatus $status, bool $recordPresent): string
    {
        if (! $recordPresent) {
            return 'No mint outbox record';
        }

        return match ($status) {
            OutboxEventStatus::Pending => 'Mint queued',
            OutboxEventStatus::Processing => 'Mint in progress',
            OutboxEventStatus::Failed => 'Mint failed',
            OutboxEventStatus::Completed => 'Mint completed',
            default => 'Mint status unknown',
        };
    }

    private function emptySummary(bool $hasInvoice, ?OutboxEvent $outbox, ?OutboxEventStatus $status): string
    {
        if ($hasInvoice) {
            return 'A statutory invoice is on file for this order.';
        }

        if ($outbox === null) {
            return 'No invoice has been generated and no statutory mint outbox record exists for this order.';
        }

        return match ($status) {
            OutboxEventStatus::Failed => 'No invoice has been generated. Statutory invoice mint failed.',
            OutboxEventStatus::Pending => 'No invoice yet. Statutory invoice mint is queued.',
            OutboxEventStatus::Processing => 'No invoice yet. Statutory invoice mint is in progress.',
            OutboxEventStatus::Completed => 'Mint completed but no statutory invoice is linked to this order.',
            default => 'No invoice has been generated for this order.',
        };
    }
}

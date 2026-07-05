<?php

namespace App\Services\Interakt;

use App\Enums\NotificationChannelType;
use App\Enums\NotificationType;
use App\Enums\WhatsAppTemplate;
use App\Enums\WhatsAppTemplateDispatchStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\WhatsAppTemplateDispatch;
use App\Services\Notifications\NotificationAuditTrailService;
use App\Support\AppDateFormatter;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class RequestSerialCommunicationHistoryService
{
    public const LAST_SENT_DISPLAY_FORMAT = 'd M, h:i A';

    /**
     * @return array{
     *     whatsapp: array<string, mixed>,
     *     email: array<string, mixed>,
     * }
     */
    public function forOrder(Order $order): array
    {
        return $this->forOrderIds(collect([$order->id]));
    }

    /**
     * @return array{
     *     whatsapp: array<string, mixed>,
     *     email: array<string, mixed>,
     * }
     */
    public function forCustomerPhone(?string $customerPhone): array
    {
        if (! filled($customerPhone)) {
            return $this->emptyHistory();
        }

        $orderIds = Order::query()
            ->where('customer_phone', $customerPhone)
            ->pluck('id');

        if ($orderIds->isEmpty()) {
            return $this->emptyHistory();
        }

        return $this->forOrderIds($orderIds);
    }

    /**
     * @param  Collection<int, int>  $orderIds
     * @return array{
     *     whatsapp: array<string, mixed>,
     *     email: array<string, mixed>,
     * }
     */
    private function forOrderIds(Collection $orderIds): array
    {
        return [
            'whatsapp' => $this->whatsappHistory($orderIds),
            'email' => $this->emailHistory($orderIds),
        ];
    }

    /**
     * @return array{
     *     whatsapp: array<string, mixed>,
     *     email: array<string, mixed>,
     * }
     */
    private function emptyHistory(): array
    {
        return [
            'whatsapp' => $this->notSentState(),
            'email' => $this->notSentState(),
        ];
    }

    /**
     * @param  Collection<int, int>  $orderIds
     * @return array<string, mixed>
     */
    private function whatsappHistory(Collection $orderIds): array
    {
        $lastSent = WhatsAppTemplateDispatch::query()
            ->whereIn('order_id', $orderIds)
            ->where('template_key', WhatsAppTemplate::RequestSerialNumber->value)
            ->where('status', WhatsAppTemplateDispatchStatus::Sent)
            ->orderByDesc('dispatched_at')
            ->orderByDesc('id')
            ->first();

        if ($lastSent !== null) {
            return $this->sentState($lastSent->dispatched_at ?? $lastSent->created_at);
        }

        $lastFailed = WhatsAppTemplateDispatch::query()
            ->whereIn('order_id', $orderIds)
            ->where('template_key', WhatsAppTemplate::RequestSerialNumber->value)
            ->where('status', WhatsAppTemplateDispatchStatus::Failed)
            ->orderByDesc('id')
            ->first();

        if ($lastFailed !== null) {
            return $this->failedState($lastFailed->error_message);
        }

        return $this->notSentState();
    }

    /**
     * @param  Collection<int, int>  $orderIds
     * @return array<string, mixed>
     */
    private function emailHistory(Collection $orderIds): array
    {
        $incidentIds = Incident::query()
            ->whereIn('order_id', $orderIds)
            ->pluck('id');

        if ($incidentIds->isEmpty()) {
            return $this->notSentState();
        }

        $logs = AuditLog::query()
            ->where('auditable_type', (new Incident)->getMorphClass())
            ->whereIn('auditable_id', $incidentIds)
            ->where('event', NotificationAuditTrailService::EVENT_DISPATCHED)
            ->orderByDesc('created_at')
            ->get(['created_at', 'new_values']);

        $lastSuccessAt = null;
        $lastFailureReason = null;

        foreach ($logs as $log) {
            if (($log->new_values['notification_type'] ?? null) !== NotificationType::RequestSerialNumber->value) {
                continue;
            }

            foreach ($log->new_values['channel_results'] ?? [] as $record) {
                if (! is_array($record)) {
                    continue;
                }

                if (($record['channel'] ?? null) !== NotificationChannelType::Email->value) {
                    continue;
                }

                if (($record['status'] ?? '') === 'not_yet_configured') {
                    continue;
                }

                if (($record['success'] ?? false) === true) {
                    if ($lastSuccessAt === null) {
                        $lastSuccessAt = $this->resolveChannelTimestamp($record, $log->created_at);
                    }

                    continue;
                }

                if ($lastFailureReason === null) {
                    $message = $record['message'] ?? null;
                    $lastFailureReason = filled($message) ? (string) $message : null;
                }
            }
        }

        if ($lastSuccessAt !== null) {
            return $this->sentState($lastSuccessAt);
        }

        if ($lastFailureReason !== null) {
            return $this->failedState($lastFailureReason);
        }

        return $this->notSentState();
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function resolveChannelTimestamp(array $record, ?CarbonInterface $fallback): ?CarbonInterface
    {
        $raw = $record['timestamp'] ?? null;

        if (is_string($raw) && $raw !== '') {
            try {
                return now()->parse($raw);
            } catch (\Throwable) {
                // Fall back to audit log timestamp.
            }
        }

        return $fallback;
    }

    /**
     * @return array<string, mixed>
     */
    private function sentState(?CarbonInterface $sentAt): array
    {
        return [
            'status' => 'sent',
            'status_label' => 'SENT',
            'last_sent_at' => $sentAt,
            'last_sent_label' => $this->formatLastSentLabel($sentAt),
            'failure_reason' => null,
        ];
    }

    private function formatLastSentLabel(?CarbonInterface $sentAt): ?string
    {
        return AppDateFormatter::format($sentAt, self::LAST_SENT_DISPLAY_FORMAT);
    }

    /**
     * @return array<string, mixed>
     */
    private function failedState(?string $reason): array
    {
        return [
            'status' => 'failed',
            'status_label' => 'FAILED',
            'last_sent_at' => null,
            'last_sent_label' => null,
            'failure_reason' => filled($reason) ? $reason : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function notSentState(): array
    {
        return [
            'status' => 'not_sent',
            'status_label' => 'NOT SENT',
            'last_sent_at' => null,
            'last_sent_label' => null,
            'failure_reason' => null,
        ];
    }
}

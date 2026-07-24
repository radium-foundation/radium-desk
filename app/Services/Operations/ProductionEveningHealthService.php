<?php

namespace App\Services\Operations;

use App\Enums\AutomationExecutionStatus;
use App\Enums\InteraktDeliveryStatus;
use App\Enums\InteraktMessageDirection;
use App\Models\AutomationExecution;
use App\Models\BonvoiceCallEvent;
use App\Models\BonvoiceWebhookLog;
use App\Models\CashfreeWebhookLog;
use App\Models\InteraktMessage;
use App\Models\InteraktWebhookLog;
use App\Services\Bonvoice\BonvoiceClickToCallMetrics;
use App\Services\Cashfree\CashfreePaymentIntegrityService;
use App\Support\BonvoiceCallStatuses;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class ProductionEveningHealthService
{
    public function __construct(
        private readonly ProductionWatchdogService $watchdogService,
        private readonly CashfreePaymentIntegrityService $cashfreeIntegrityService,
        private readonly OperationsIntegrationHealthService $integrationHealthService,
        private readonly OperationsSystemHealthService $systemHealthService,
        private readonly OperationsRadiumBoxHealthService $radiumBoxHealthService,
        private readonly BonvoiceClickToCallMetrics $clickToCallMetrics,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(?Carbon $at = null): array
    {
        $at ??= now();
        $rangeStart = $at->copy()->startOfDay();
        $rangeEnd = $at->copy()->endOfDay();
        $uptime = $this->watchdogService->todayUptimeSummary($at);
        $cashfreeReconciliation = $this->cashfreeIntegrityService->reconcile();
        $radiumBox = $this->radiumBoxHealthService->widget();

        return [
            'uptime_percent' => $uptime['uptime_percent'],
            'downtime_incidents' => $uptime['downtime_incidents'],
            'watchdog_checks' => $uptime['total_checks'],
            'webhook_health' => $this->webhookHealthSummary($rangeStart, $rangeEnd),
            'api_health' => $this->apiHealthSummary(),
            'queue_health' => $this->queueHealthSummary(),
            'automation_executions' => $this->automationExecutionSummary($rangeStart, $rangeEnd),
            'cashfree_reconciliation' => [
                'successful_payments' => $cashfreeReconciliation->successfulCashfreePayments,
                'desk_orders' => $cashfreeReconciliation->deskOrders,
                'missing_orders' => $cashfreeReconciliation->missingOrdersCount,
                'failed_processing' => $cashfreeReconciliation->failedProcessing,
            ],
            'bonvoice_calls' => $this->bonvoiceCallSummary($rangeStart, $rangeEnd),
            'bonvoice_click_to_call' => $this->clickToCallMetrics->todaySummary(),
            'whatsapp_delivery' => $this->whatsappDeliverySummary($rangeStart, $rangeEnd),
            'radiumbox' => [
                'failed_syncs' => (int) ($radiumBox['failed_syncs'] ?? 0),
                'pending_syncs' => (int) ($radiumBox['pending_syncs'] ?? 0),
                'success_rate_24h' => (float) ($radiumBox['success_rate_24h'] ?? 0),
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function webhookHealthSummary(Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $summary = [
            'cashfree_failed' => 0,
            'cashfree_processed' => 0,
            'bonvoice_failed' => 0,
            'bonvoice_processed' => 0,
            'interakt_failed' => 0,
            'interakt_processed' => 0,
        ];

        if (Schema::hasTable('cashfree_webhook_logs')) {
            $summary['cashfree_failed'] = CashfreeWebhookLog::query()
                ->where('processing_status', CashfreeWebhookLog::STATUS_FAILED)
                ->whereBetween('processed_at', [$rangeStart, $rangeEnd])
                ->count();
            $summary['cashfree_processed'] = CashfreeWebhookLog::query()
                ->where('processing_status', CashfreeWebhookLog::STATUS_PROCESSED)
                ->whereBetween('processed_at', [$rangeStart, $rangeEnd])
                ->count();
        }

        if (Schema::hasTable('bonvoice_webhook_logs')) {
            $summary['bonvoice_failed'] = BonvoiceWebhookLog::query()
                ->where('processing_status', BonvoiceWebhookLog::STATUS_FAILED)
                ->whereBetween('received_at', [$rangeStart, $rangeEnd])
                ->count();
            $summary['bonvoice_processed'] = BonvoiceWebhookLog::query()
                ->where('processing_status', BonvoiceWebhookLog::STATUS_PROCESSED)
                ->whereBetween('received_at', [$rangeStart, $rangeEnd])
                ->count();
        }

        if (Schema::hasTable('interakt_webhook_logs')) {
            $summary['interakt_failed'] = InteraktWebhookLog::query()
                ->where('processing_status', InteraktWebhookLog::STATUS_FAILED)
                ->whereBetween('received_at', [$rangeStart, $rangeEnd])
                ->count();
            $summary['interakt_processed'] = InteraktWebhookLog::query()
                ->where('processing_status', InteraktWebhookLog::STATUS_PROCESSED)
                ->whereBetween('received_at', [$rangeStart, $rangeEnd])
                ->count();
        }

        return $summary;
    }

    /**
     * @return list<array{label: string, status: string, detail: string}>
     */
    private function apiHealthSummary(): array
    {
        return array_map(
            fn (array $card): array => [
                'label' => (string) ($card['label'] ?? 'Integration'),
                'status' => (string) ($card['status_label'] ?? ($card['status'] ?? 'unknown')),
                'detail' => (string) ($card['detail'] ?? ''),
            ],
            $this->integrationHealthService->cards(),
        );
    }

    /**
     * @return array{status: string, detail: string}
     */
    private function queueHealthSummary(): array
    {
        foreach ($this->systemHealthService->components() as $component) {
            if (($component['key'] ?? '') === 'queue_worker') {
                return [
                    'status' => (string) ($component['status_label'] ?? ($component['status'] ?? 'unknown')),
                    'detail' => (string) ($component['detail'] ?? ''),
                ];
            }
        }

        return [
            'status' => 'Unknown',
            'detail' => 'Queue health unavailable.',
        ];
    }

    /**
     * @return array{total: int, success: int, failed: int}
     */
    private function automationExecutionSummary(Carbon $rangeStart, Carbon $rangeEnd): array
    {
        if (! Schema::hasTable('automation_executions')) {
            return ['total' => 0, 'success' => 0, 'failed' => 0];
        }

        $total = AutomationExecution::query()
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->count();
        $success = AutomationExecution::query()
            ->where('status', AutomationExecutionStatus::Success)
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->count();
        $failed = AutomationExecution::query()
            ->where('status', AutomationExecutionStatus::Failed)
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->count();

        return [
            'total' => $total,
            'success' => $success,
            'failed' => $failed,
        ];
    }

    /**
     * @return array{total: int, inbound: int, outbound: int, missed: int}
     */
    private function bonvoiceCallSummary(Carbon $rangeStart, Carbon $rangeEnd): array
    {
        if (! Schema::hasTable('bonvoice_call_events')) {
            return ['total' => 0, 'inbound' => 0, 'outbound' => 0, 'missed' => 0];
        }

        $events = BonvoiceCallEvent::query()
            ->whereBetween('started_at', [$rangeStart, $rangeEnd])
            ->get(['direction', 'status']);

        $inbound = $events
            ->filter(fn (BonvoiceCallEvent $event): bool => BonvoiceCallStatuses::isInbound($event->direction))
            ->count();
        $outbound = $events
            ->filter(fn (BonvoiceCallEvent $event): bool => BonvoiceCallStatuses::isOutbound($event->direction))
            ->count();
        $missed = $events
            ->filter(fn (BonvoiceCallEvent $event): bool => BonvoiceCallStatuses::isInbound($event->direction)
                && BonvoiceCallStatuses::isMissedStatus($event->status))
            ->count();

        return [
            'total' => $events->count(),
            'inbound' => $inbound,
            'outbound' => $outbound,
            'missed' => $missed,
        ];
    }

    /**
     * @return array{sent: int, delivered: int, failed: int, read: int}
     */
    private function whatsappDeliverySummary(Carbon $rangeStart, Carbon $rangeEnd): array
    {
        if (! Schema::hasTable('interakt_messages')) {
            return ['sent' => 0, 'delivered' => 0, 'failed' => 0, 'read' => 0];
        }

        $messages = InteraktMessage::query()
            ->where('direction', InteraktMessageDirection::Outgoing)
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->get(['delivery_status', 'channel_failure_reason']);

        return [
            'sent' => $messages->count(),
            'delivered' => $messages->where('delivery_status', InteraktDeliveryStatus::Delivered)->count(),
            'failed' => $messages->filter(fn (InteraktMessage $message): bool => filled($message->channel_failure_reason))->count(),
            'read' => $messages->where('delivery_status', InteraktDeliveryStatus::Read)->count(),
        ];
    }
}

<?php

namespace App\Services\Cashfree;

use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardBroadcastService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentService;
use App\Services\ServiceCaseAutomationMonitorService;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CashfreeWebhookDeferredOperationsService
{
    public const OPERATION_AUTOMATION_MONITOR = 'automation_monitor';

    public const OPERATION_DASHBOARD_BROADCAST = 'dashboard_broadcast';

    public const OPERATION_RADIUMBOX_ENRICHMENT = 'radiumbox_enrichment';

    /**
     * @var list<string>
     */
    private const OPERATIONS = [
        self::OPERATION_AUTOMATION_MONITOR,
        self::OPERATION_DASHBOARD_BROADCAST,
        self::OPERATION_RADIUMBOX_ENRICHMENT,
    ];

    public function __construct(
        private readonly ServiceCaseAutomationMonitorService $automationMonitor,
        private readonly DashboardBroadcastService $dashboardBroadcastService,
        private readonly RadiumBoxOrderEnrichmentService $radiumBoxOrderEnrichmentService,
    ) {}

    /**
     * Emergency CPU mitigation: when false, Cashfree deferred dashboard_broadcast
     * is neither written nor executed. Other deferred ops and operator broadcasts stay on.
     */
    public static function isDashboardBroadcastEnabled(): bool
    {
        return (bool) config('cashfree.deferred_dashboard_broadcast_enabled', false);
    }

    public function executeOperation(
        string $operation,
        int $orderId,
        int $incidentId,
        int $actorId,
    ): void {
        if (! in_array($operation, self::OPERATIONS, true)) {
            throw new RuntimeException('Unknown Cashfree deferred operation: '.$operation);
        }

        if ($operation === self::OPERATION_DASHBOARD_BROADCAST && ! self::isDashboardBroadcastEnabled()) {
            Log::info('[Cashfree] Deferred dashboard_broadcast skipped (emergency mitigation).', [
                'order_id' => $orderId,
                'incident_id' => $incidentId,
                'actor_id' => $actorId,
            ]);

            return;
        }

        $this->performOperation(
            operation: $operation,
            orderId: $orderId,
            incidentId: $incidentId,
            actorId: $actorId,
        );
    }

    private function performOperation(
        string $operation,
        int $orderId,
        int $incidentId,
        int $actorId,
    ): void {
        $order = Order::query()->find($orderId);
        $incident = Incident::query()->find($incidentId);
        $actor = User::query()->find($actorId);

        if ($order === null || $incident === null || $actor === null) {
            throw new RuntimeException(sprintf(
                'Deferred operation prerequisites missing for %s (order=%s, incident=%s, actor=%s).',
                $operation,
                $orderId,
                $incidentId,
                $actorId,
            ));
        }

        match ($operation) {
            self::OPERATION_AUTOMATION_MONITOR => $this->automationMonitor->recordPaymentReceived($incident, $actor),
            self::OPERATION_DASHBOARD_BROADCAST => $this->dashboardBroadcastService->serviceCaseCreated($incident, $actor),
            self::OPERATION_RADIUMBOX_ENRICHMENT => $this->radiumBoxOrderEnrichmentService->dispatchAfterCashfreePayment($order),
            default => throw new RuntimeException('Unknown Cashfree deferred operation: '.$operation),
        };
    }
}

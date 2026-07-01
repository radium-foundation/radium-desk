<?php

namespace App\Services\Cashfree;

use App\Data\CashfreeWebhookDeferredContext;
use App\Jobs\ProcessCashfreeWebhookDeferredOperationJob;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardBroadcastService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentService;
use App\Services\ServiceCaseAutomationMonitorService;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

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
        private readonly CashfreeWebhookReliabilityMetrics $reliabilityMetrics,
    ) {}

    public function run(CashfreeWebhookDeferredContext $context): void
    {
        foreach (self::OPERATIONS as $operation) {
            $this->execute(
                operation: $operation,
                orderId: $context->orderId,
                incidentId: $context->incidentId,
                actorId: $context->actorId,
                dispatchRetryOnFailure: true,
            );
        }
    }

    public function retryOperation(
        string $operation,
        int $orderId,
        int $incidentId,
        int $actorId,
    ): void {
        if (! in_array($operation, self::OPERATIONS, true)) {
            throw new RuntimeException('Unknown Cashfree deferred operation: '.$operation);
        }

        $this->execute(
            operation: $operation,
            orderId: $orderId,
            incidentId: $incidentId,
            actorId: $actorId,
            dispatchRetryOnFailure: false,
        );
    }

    private function execute(
        string $operation,
        int $orderId,
        int $incidentId,
        int $actorId,
        bool $dispatchRetryOnFailure,
    ): void {
        try {
            $this->performOperation(
                operation: $operation,
                orderId: $orderId,
                incidentId: $incidentId,
                actorId: $actorId,
            );
        } catch (Throwable $exception) {
            $this->reliabilityMetrics->recordDeferredFailure($operation);

            Log::error('[Cashfree Webhook] Deferred operation failed.', [
                'operation' => $operation,
                'order_id' => $orderId,
                'incident_id' => $incidentId,
                'actor_id' => $actorId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            if ($dispatchRetryOnFailure) {
                ProcessCashfreeWebhookDeferredOperationJob::dispatch(
                    operation: $operation,
                    orderId: $orderId,
                    incidentId: $incidentId,
                    actorId: $actorId,
                );
            } else {
                throw $exception;
            }
        }
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
            self::OPERATION_RADIUMBOX_ENRICHMENT => $this->radiumBoxOrderEnrichmentService->dispatch($order),
            default => throw new RuntimeException('Unknown Cashfree deferred operation: '.$operation),
        };
    }
}

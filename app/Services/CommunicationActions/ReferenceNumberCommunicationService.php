<?php

namespace App\Services\CommunicationActions;

use App\Enums\CommunicationActionKey;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReferenceNumberCommunicationService
{
    public const TRIGGER = 'reference_number_added';

    public const IDEMPOTENCY_AUDIT_EVENT = 'service_reference.driver_installation_guide_triggered';

    public function __construct(
        private readonly CommunicationActionRegistry $registry,
        private readonly CommunicationActionExecutorService $executorService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function handleServiceReferenceAssigned(Order $order, string $serviceReference, User $actor): void
    {
        try {
            $this->sendDriverInstallationGuide($order, $serviceReference, $actor);
        } catch (Throwable $exception) {
            Log::error('service_reference.assigned.driver_installation_guide.failed', [
                'order_id' => $order->id,
                'service_reference' => $serviceReference,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function sendDriverInstallationGuide(Order $order, string $serviceReference, User $actor): void
    {
        $definition = $this->registry->get(CommunicationActionKey::DriverInstallationGuide->value);

        if (! $definition->automation->enabled || $definition->automation->futureTrigger !== self::TRIGGER) {
            return;
        }

        $idempotencyKey = $this->idempotencyKey($order->id, $serviceReference);

        if ($this->hasAlreadySent($idempotencyKey)) {
            return;
        }

        $incident = $this->resolveIncident($order);

        if ($incident === null) {
            return;
        }

        $result = $this->executorService->executeAutomated(
            actionKey: $definition->key->value,
            incident: $incident,
            operator: $actor,
            metadata: [
                'automation_trigger' => self::TRIGGER,
                'idempotency_key' => $idempotencyKey,
                'service_reference_order_id' => $order->id,
                'service_reference' => $serviceReference,
            ],
        );

        if ($result === true) {
            $this->recordSuccessfulSend($order, $actor, $idempotencyKey, $serviceReference, $incident);
        }
    }

    private function idempotencyKey(int $orderId, string $serviceReference): string
    {
        return sprintf('service_reference.assigned:%d:%s', $orderId, trim($serviceReference));
    }

    private function hasAlreadySent(string $idempotencyKey): bool
    {
        return AuditLog::query()
            ->where('event', self::IDEMPOTENCY_AUDIT_EVENT)
            ->where('new_values->idempotency_key', $idempotencyKey)
            ->exists();
    }

    private function resolveIncident(Order $order): ?Incident
    {
        return Incident::query()
            ->where('order_id', $order->id)
            ->orderByDesc('id')
            ->first();
    }

    private function recordSuccessfulSend(
        Order $order,
        User $actor,
        string $idempotencyKey,
        string $serviceReference,
        Incident $incident,
    ): void {
        $this->auditLogService->log(
            userId: $actor->id,
            event: self::IDEMPOTENCY_AUDIT_EVENT,
            auditable: $order,
            newValues: [
                'idempotency_key' => $idempotencyKey,
                'service_reference' => $serviceReference,
                'incident_id' => $incident->id,
                'communication_action_key' => CommunicationActionKey::DriverInstallationGuide->value,
                'automation_trigger' => self::TRIGGER,
            ],
        );
    }
}

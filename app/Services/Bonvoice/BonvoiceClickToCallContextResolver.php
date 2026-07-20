<?php

namespace App\Services\Bonvoice;

use App\Data\Bonvoice\BonvoiceClickToCallContext;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class BonvoiceClickToCallContextResolver
{
    public function __construct(
        private readonly BonvoiceClickToCallService $clickToCallService,
    ) {}

    public function resolve(User $user, ?int $orderId, ?int $incidentId): BonvoiceClickToCallContext
    {
        if ($incidentId !== null) {
            return $this->resolveFromIncident($user, $incidentId);
        }

        if ($orderId !== null) {
            return $this->resolveFromOrder($user, $orderId);
        }

        throw new ModelNotFoundException('A valid order_id or incident_id is required.');
    }

    private function resolveFromIncident(User $user, int $incidentId): BonvoiceClickToCallContext
    {
        $incident = Incident::query()
            ->with('order')
            ->findOrFail($incidentId);

        $this->authorizeIncidentView($user, $incident);

        $order = $incident->order;

        if ($order === null) {
            throw new ModelNotFoundException('Service case is not linked to an order.');
        }

        return $this->buildContext($order, $incident);
    }

    private function resolveFromOrder(User $user, int $orderId): BonvoiceClickToCallContext
    {
        $order = Order::query()->findOrFail($orderId);

        $this->authorizeOrderView($user, $order);

        $incident = $order->latestIncident();

        return $this->buildContext($order, $incident);
    }

    private function buildContext(Order $order, ?Incident $incident): BonvoiceClickToCallContext
    {
        $customerPhone = trim((string) ($order->customer_phone ?? ''));
        $customerDialable = $this->clickToCallService->normalizeDialablePhone($customerPhone) ?? '';

        return new BonvoiceClickToCallContext(
            order: $order,
            incident: $incident,
            customerPhone: $customerPhone,
            customerDialable: $customerDialable,
        );
    }

    private function authorizeIncidentView(User $user, Incident $incident): void
    {
        if (! $user->can('view', $incident)) {
            throw new AuthorizationException('This action is unauthorized.');
        }
    }

    private function authorizeOrderView(User $user, Order $order): void
    {
        if (! $user->can('view', $order)) {
            throw new AuthorizationException('This action is unauthorized.');
        }
    }
}

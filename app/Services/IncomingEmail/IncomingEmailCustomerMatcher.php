<?php

namespace App\Services\IncomingEmail;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\IncomingEmailMessage;
use App\Models\Order;

class IncomingEmailCustomerMatcher
{
    public function __construct(
        private readonly IncomingEmailClosedCaseReopenService $closedCaseReopenService,
    ) {}

    /**
     * @return array{
     *     order: ?Order,
     *     incident: ?Incident,
     *     closed_incident: ?Incident,
     *     reason: ?string,
     * }
     */
    public function resolve(IncomingEmailMessage $message): array
    {
        $threadMatch = $this->resolveThread($message);

        if ($threadMatch !== null) {
            return $threadMatch;
        }

        $candidates = $this->emailCandidates($message->from_email);

        if ($candidates === []) {
            return [
                'order' => null,
                'incident' => null,
                'closed_incident' => null,
                'reason' => 'unknown_customer',
            ];
        }

        $order = Order::query()
            ->whereIn('customer_email', $candidates)
            ->orderByDesc('id')
            ->first();

        if (! $order instanceof Order) {
            return [
                'order' => null,
                'incident' => null,
                'closed_incident' => null,
                'reason' => 'unknown_customer',
            ];
        }

        $incident = Incident::query()
            ->where('order_id', $order->id)
            ->whereIn('status', IncidentStatus::operationallyActive())
            ->lockForUpdate()
            ->orderByDesc('id')
            ->first();

        if ($incident instanceof Incident) {
            return [
                'order' => $order,
                'incident' => $incident,
                'closed_incident' => null,
                'reason' => null,
            ];
        }

        $closedIncident = $this->findReopenableClosedIncident($order);

        if ($closedIncident instanceof Incident) {
            return [
                'order' => $order,
                'incident' => null,
                'closed_incident' => $closedIncident,
                'reason' => 'closed_service_case',
            ];
        }

        return [
            'order' => $order,
            'incident' => null,
            'closed_incident' => null,
            'reason' => 'historical_customer',
        ];
    }

    /**
     * @return array{
     *     order: ?Order,
     *     incident: ?Incident,
     *     closed_incident: ?Incident,
     *     reason: ?string,
     * }|null
     */
    private function resolveThread(IncomingEmailMessage $message): ?array
    {
        if ($message->thread_id === null || trim($message->thread_id) === '') {
            return null;
        }

        $prior = IncomingEmailMessage::query()
            ->where('thread_id', $message->thread_id)
            ->where('id', '!=', $message->id)
            ->whereNotNull('incident_id')
            ->where('status', 'linked')
            ->orderByDesc('id')
            ->first();

        if ($prior === null || $prior->incident_id === null) {
            return null;
        }

        $incident = Incident::query()
            ->with('order')
            ->whereKey($prior->incident_id)
            ->lockForUpdate()
            ->first();

        if (! $incident instanceof Incident) {
            return null;
        }

        if (in_array($incident->status, IncidentStatus::operationallyActive(), true)) {
            return [
                'order' => $incident->order,
                'incident' => $incident,
                'closed_incident' => null,
                'reason' => null,
            ];
        }

        if ($this->closedCaseReopenService->isReopenable($incident)) {
            return [
                'order' => $incident->order,
                'incident' => null,
                'closed_incident' => $incident,
                'reason' => 'closed_service_case',
            ];
        }

        return null;
    }

    private function findReopenableClosedIncident(Order $order): ?Incident
    {
        $closed = Incident::query()
            ->where('order_id', $order->id)
            ->where('status', IncidentStatus::Closed)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->first();

        if (! $closed instanceof Incident) {
            return null;
        }

        return $this->closedCaseReopenService->isReopenable($closed) ? $closed : null;
    }

    /**
     * @return list<string>
     */
    public function emailCandidates(?string $email): array
    {
        if ($email === null) {
            return [];
        }

        $normalized = strtolower(trim($email));

        if ($normalized === '' || ! filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            return [];
        }

        $candidates = [$normalized];

        // Support stored variants that differ only by surrounding whitespace/case
        // (already normalized). Also include the local alias without plus-tag if present.
        if (str_contains($normalized, '+')) {
            [$local, $domain] = explode('@', $normalized, 2);
            $baseLocal = explode('+', $local, 2)[0];
            $withoutPlus = $baseLocal.'@'.$domain;

            if ($withoutPlus !== $normalized) {
                $candidates[] = $withoutPlus;
            }
        }

        return array_values(array_unique($candidates));
    }
}

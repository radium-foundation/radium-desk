<?php

namespace App\Services\IncomingEmail;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\IncomingEmailMessage;
use App\Models\Order;

class IncomingEmailCustomerMatcher
{
    /**
     * @return array{
     *     order: ?Order,
     *     incident: ?Incident,
     *     reason: ?string,
     * }
     */
    public function resolve(IncomingEmailMessage $message): array
    {
        $threadIncident = $this->findIncidentByThread($message);

        if ($threadIncident instanceof Incident) {
            return [
                'order' => $threadIncident->order,
                'incident' => $threadIncident,
                'reason' => null,
            ];
        }

        $candidates = $this->emailCandidates($message->from_email);

        if ($candidates === []) {
            return [
                'order' => null,
                'incident' => null,
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
                'reason' => 'unknown_customer',
            ];
        }

        $incident = Incident::query()
            ->where('order_id', $order->id)
            ->whereIn('status', IncidentStatus::operationallyActive())
            ->lockForUpdate()
            ->orderByDesc('id')
            ->first();

        if (! $incident instanceof Incident) {
            return [
                'order' => $order,
                'incident' => null,
                'reason' => 'no_open_incident',
            ];
        }

        return [
            'order' => $order,
            'incident' => $incident,
            'reason' => null,
        ];
    }

    private function findIncidentByThread(IncomingEmailMessage $message): ?Incident
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

        return Incident::query()
            ->with('order')
            ->whereKey($prior->incident_id)
            ->whereIn('status', IncidentStatus::operationallyActive())
            ->lockForUpdate()
            ->first();
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

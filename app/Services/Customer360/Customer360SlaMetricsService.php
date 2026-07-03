<?php

namespace App\Services\Customer360;

use App\Data\Customer360\Customer360SlaMetrics;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Services\Notifications\NotificationAuditTrailService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class Customer360SlaMetricsService
{
    private const CACHE_TTL_SECONDS = 120;

    public function forOrder(Order $order): Customer360SlaMetrics
    {
        $cacheKey = 'customer360:sla-metrics:order:'.$order->id;

        $cached = Cache::get($cacheKey);

        if ($cached instanceof Customer360SlaMetrics) {
            return $cached;
        }

        $metrics = $this->buildForCustomerPhone($order->customer_phone, $order);
        Cache::put($cacheKey, $metrics, now()->addSeconds(self::CACHE_TTL_SECONDS));

        return $metrics;
    }

    private function buildForCustomerPhone(?string $phone, Order $focusOrder): Customer360SlaMetrics
    {
        $orders = $this->ordersForPhone($phone, $focusOrder);

        return new Customer360SlaMetrics(
            stages: [
                'payment_to_order' => $this->aggregateStage($orders, fn (Order $order) => $this->paymentToOrderMinutes($order)),
                'order_to_sync' => $this->aggregateStage($orders, fn (Order $order) => $this->orderToSyncMinutes($order)),
                'sync_to_email' => $this->aggregateStage($orders, fn (Order $order) => $this->syncToEmailMinutes($order)),
                'email_to_booking' => $this->aggregateStage($orders, fn (Order $order) => $this->emailToBookingMinutes($order)),
                'booking_to_resolution' => $this->aggregateStage($orders, fn (Order $order) => $this->bookingToResolutionMinutes($order)),
            ],
        );
    }

    /**
     * @return Collection<int, Order>
     */
    private function ordersForPhone(?string $phone, Order $focusOrder): Collection
    {
        if (! filled($phone)) {
            return collect([$focusOrder]);
        }

        return Order::query()
            ->where('customer_phone', $phone)
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @param  callable(Order): ?float  $resolver
     * @return array{median_minutes: ?float, average_minutes: ?float, p95_minutes: ?float, sample_size: int}
     */
    private function aggregateStage(Collection $orders, callable $resolver): array
    {
        $values = $orders
            ->map(fn (Order $order): ?float => $resolver($order))
            ->filter(fn (?float $value): bool => $value !== null)
            ->sort()
            ->values()
            ->all();

        $count = count($values);

        if ($count === 0) {
            return [
                'median_minutes' => null,
                'average_minutes' => null,
                'p95_minutes' => null,
                'sample_size' => 0,
            ];
        }

        return [
            'median_minutes' => round($this->percentile($values, 50), 1),
            'average_minutes' => round(array_sum($values) / $count, 1),
            'p95_minutes' => round($this->percentile($values, 95), 1),
            'sample_size' => $count,
        ];
    }

    /**
     * @param  list<float>  $values
     */
    private function percentile(array $values, int $percentile): float
    {
        $index = (int) ceil(($percentile / 100) * count($values)) - 1;

        return $values[max(0, min($index, count($values) - 1))];
    }

    private function paymentToOrderMinutes(Order $order): ?float
    {
        if ($order->payment_date === null || $order->created_at === null) {
            return null;
        }

        return $this->minutesBetween($order->payment_date, $order->created_at);
    }

    private function orderToSyncMinutes(Order $order): ?float
    {
        if ($order->created_at === null || $order->radiumbox_last_sync_at === null) {
            return null;
        }

        if ($order->radiumbox_sync_status !== RadiumBoxEnrichmentSyncStatus::Synced) {
            return null;
        }

        return $this->minutesBetween($order->created_at, $order->radiumbox_last_sync_at);
    }

    private function syncToEmailMinutes(Order $order): ?float
    {
        if ($order->radiumbox_last_sync_at === null) {
            return null;
        }

        $emailSentAt = $this->firstNotificationAt($order, 'email');

        if ($emailSentAt === null) {
            return null;
        }

        return $this->minutesBetween($order->radiumbox_last_sync_at, $emailSentAt);
    }

    private function emailToBookingMinutes(Order $order): ?float
    {
        $emailSentAt = $this->firstNotificationAt($order, 'email');
        $bookingAt = $this->firstAppointmentAt($order);

        if ($emailSentAt === null || $bookingAt === null) {
            return null;
        }

        return $this->minutesBetween($emailSentAt, $bookingAt);
    }

    private function bookingToResolutionMinutes(Order $order): ?float
    {
        $bookingAt = $this->firstAppointmentAt($order);
        $resolvedAt = $this->firstClosedIncidentAt($order);

        if ($bookingAt === null || $resolvedAt === null) {
            return null;
        }

        return $this->minutesBetween($bookingAt, $resolvedAt);
    }

    private function firstNotificationAt(Order $order, string $channel): ?Carbon
    {
        $order->loadMissing('incidents');
        $incidentIds = $order->incidents->pluck('id');

        if ($incidentIds->isEmpty()) {
            return null;
        }

        $logs = AuditLog::query()
            ->where('auditable_type', (new Incident)->getMorphClass())
            ->whereIn('auditable_id', $incidentIds)
            ->where('event', NotificationAuditTrailService::EVENT_DISPATCHED)
            ->orderBy('created_at')
            ->get(['created_at', 'new_values']);

        foreach ($logs as $log) {
            foreach ($log->new_values['channel_results'] ?? [] as $record) {
                if (! is_array($record)) {
                    continue;
                }

                if (strtolower((string) ($record['channel'] ?? '')) !== $channel) {
                    continue;
                }

                if (($record['success'] ?? false) === true && $log->created_at !== null) {
                    return $log->created_at;
                }
            }
        }

        return null;
    }

    private function firstAppointmentAt(Order $order): ?Carbon
    {
        $order->loadMissing('incidents.supportAppointments');

        $appointment = $order->incidents
            ->flatMap(fn (Incident $incident) => $incident->supportAppointments)
            ->sortBy(fn (SupportAppointment $item) => $item->created_at?->timestamp ?? 0)
            ->first();

        return $appointment?->created_at;
    }

    private function firstClosedIncidentAt(Order $order): ?Carbon
    {
        $order->loadMissing('incidents');

        return $order->incidents
            ->filter(fn (Incident $incident): bool => $incident->status?->value === 'closed')
            ->sortBy(fn (Incident $incident) => $incident->updated_at?->timestamp ?? 0)
            ->first()
            ?->updated_at;
    }

    private function minutesBetween(Carbon $from, Carbon $to): float
    {
        return max(0, $from->diffInSeconds($to) / 60);
    }
}

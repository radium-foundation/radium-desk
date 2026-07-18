<?php

namespace App\Services\Operations;

use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class WorkforceActivityTimelineService
{
    /**
     * Business audit events projected onto the person activity timeline.
     *
     * @var list<string>
     */
    public const TIMELINE_EVENTS = [
        WorkforceActivityContextService::EVENT_ORDER_VIEWED,
        WorkforceActivityContextService::EVENT_SERVICE_CASE_VIEWED,
        'serial.assigned',
        'refund.approved',
        'refund.rejected',
        'refund.completed',
        'notification.dispatched',
        'service_case.assigned',
        'service_case.reassigned',
        'service_case.status_changed',
    ];

    /**
     * @return list<array{at: Carbon, time: string, label: string, event: string, source: string}>
     */
    public function forUserOnDate(User $user, Carbon $date): array
    {
        $dayStart = $date->copy()->startOfDay();
        $dayEnd = $date->copy()->endOfDay();

        return collect()
            ->merge($this->sessionBoundaryEntries($user, $dayStart, $dayEnd))
            ->merge($this->auditEntries($user, $dayStart, $dayEnd))
            ->sortBy(fn (array $entry): int => $entry['at']->getTimestamp())
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, array{at: Carbon, time: string, label: string, event: string, source: string}>
     */
    private function sessionBoundaryEntries(User $user, Carbon $dayStart, Carbon $dayEnd): Collection
    {
        return WorkSession::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', $dayStart->toDateString())
            ->orderBy('login_at')
            ->get(['id', 'login_at', 'logout_at'])
            ->flatMap(function (WorkSession $session) use ($dayStart, $dayEnd): array {
                $entries = [];

                if ($session->login_at !== null
                    && $session->login_at->between($dayStart, $dayEnd)
                ) {
                    $entries[] = $this->entry(
                        at: $session->login_at,
                        label: 'Login',
                        event: 'work_session.login',
                        source: 'work_session',
                    );
                }

                if ($session->logout_at !== null
                    && $session->logout_at->between($dayStart, $dayEnd)
                ) {
                    $entries[] = $this->entry(
                        at: $session->logout_at,
                        label: 'Logout',
                        event: 'work_session.logout',
                        source: 'work_session',
                    );
                }

                return $entries;
            });
    }

    /**
     * @return Collection<int, array{at: Carbon, time: string, label: string, event: string, source: string}>
     */
    private function auditEntries(User $user, Carbon $dayStart, Carbon $dayEnd): Collection
    {
        $logs = AuditLog::query()
            ->with('auditable')
            ->where('user_id', $user->id)
            ->whereIn('event', self::TIMELINE_EVENTS)
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->orderBy('created_at')
            ->get();

        return $logs->map(function (AuditLog $log): array {
            return $this->entry(
                at: $log->created_at ?? now(),
                label: $this->labelForAudit($log),
                event: $log->event,
                source: 'audit_log',
            );
        });
    }

    private function labelForAudit(AuditLog $log): string
    {
        $auditable = $log->auditable;

        return match ($log->event) {
            WorkforceActivityContextService::EVENT_ORDER_VIEWED => sprintf(
                'Viewed %s',
                $this->orderLabel($auditable, $log->new_values['order_id'] ?? null),
            ),
            WorkforceActivityContextService::EVENT_SERVICE_CASE_VIEWED => sprintf(
                'Viewed %s',
                $this->serviceCaseLabel($auditable, $log->new_values['reference_no'] ?? null),
            ),
            'serial.assigned' => 'Assigned Serial',
            'refund.approved' => 'Refund Approved',
            'refund.rejected' => 'Refund Rejected',
            'refund.completed' => 'Refund Completed',
            'notification.dispatched' => 'Communication Sent',
            'service_case.assigned' => 'Service Case Assigned',
            'service_case.reassigned' => 'Service Case Reassigned',
            'service_case.status_changed' => 'Status Changed',
            default => audit_event_label($log->event),
        };
    }

    private function orderLabel(mixed $auditable, mixed $fallback): string
    {
        if ($auditable instanceof Order && filled($auditable->order_id)) {
            return (string) $auditable->order_id;
        }

        return filled($fallback) ? (string) $fallback : 'Order';
    }

    private function serviceCaseLabel(mixed $auditable, mixed $fallback): string
    {
        if ($auditable instanceof Incident) {
            $reference = $auditable->display_reference ?: $auditable->reference_no;

            if (filled($reference)) {
                return (string) $reference;
            }
        }

        return filled($fallback) ? (string) $fallback : 'Service Case';
    }

    /**
     * @return array{at: Carbon, time: string, label: string, event: string, source: string}
     */
    private function entry(Carbon $at, string $label, string $event, string $source): array
    {
        return [
            'at' => $at,
            'time' => $at->format('H:i'),
            'label' => $label,
            'event' => $event,
            'source' => $source,
        ];
    }
}

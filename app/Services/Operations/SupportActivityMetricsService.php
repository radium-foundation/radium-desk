<?php

namespace App\Services\Operations;

use App\Data\Operations\HumanEffortOutcomeMetrics;
use App\Enums\OperationsKpiProfile;
use App\Enums\RemarkOrigin;
use App\Enums\WhatsAppTemplateTriggerSource;
use App\Models\AuditLog;
use App\Models\BonvoiceCallEvent;
use App\Models\Remark;
use App\Models\User;
use App\Support\Dashboard\TeamActivityKpiAuditQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SupportActivityMetricsService
{
    public function __construct(
        private readonly TeamActivityKpiAuditQuery $kpiAuditQuery,
    ) {}

    public function metricsFor(User $user, ?Carbon $dayStart = null): HumanEffortOutcomeMetrics
    {
        return $this->metricsForUsers([$user->id], $dayStart)[$user->id]
            ?? new HumanEffortOutcomeMetrics(
                profile: OperationsKpiProfile::Support,
                outcome: 0,
                effort: 0,
            );
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, HumanEffortOutcomeMetrics>
     */
    public function metricsForUsers(array $userIds, ?Carbon $dayStart = null): array
    {
        if ($userIds === []) {
            return [];
        }

        $dayStart ??= Carbon::now()->startOfDay();
        $casesWorked = $this->kpiAuditQuery->todayCountsForUsers($userIds, $dayStart);
        $effortCounts = $this->effortCountsForUsers($userIds, $dayStart);
        $callCounts = $this->callCountsForUsers($userIds, $dayStart);

        $metrics = [];

        foreach ($userIds as $userId) {
            $breakdown = $effortCounts[$userId] ?? $this->emptyEffortBreakdown();
            $breakdown['calls'] = (int) ($callCounts[$userId] ?? 0);
            $effort = array_sum($breakdown);

            $metrics[$userId] = new HumanEffortOutcomeMetrics(
                profile: OperationsKpiProfile::Support,
                outcome: (int) ($casesWorked[$userId] ?? 0),
                effort: $effort,
                breakdown: $breakdown,
            );
        }

        return $metrics;
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, array<string, int>>
     */
    private function effortCountsForUsers(array $userIds, Carbon $dayStart): array
    {
        $counts = array_fill_keys($userIds, $this->emptyEffortBreakdown());
        $eventMap = config('operations-kpi.support.effort_events', []);

        $statusEvents = $eventMap['status_updates'] ?? ['service_case.status_changed'];
        $remarkEvents = $eventMap['remarks'] ?? ['created', 'deleted'];
        $whatsappEvents = $eventMap['whatsapp'] ?? ['whatsapp.template_sent'];
        $emailEvents = $eventMap['emails'] ?? ['notification.dispatched', 'communication_action.lifecycle'];

        foreach ($this->countDirectEvents($userIds, $dayStart, $statusEvents) as $userId => $count) {
            $counts[$userId]['status_updates'] = $count;
        }

        foreach ($this->countDirectEvents($userIds, $dayStart, $emailEvents) as $userId => $count) {
            $counts[$userId]['emails'] = $count;
        }

        foreach ($this->manualWhatsAppCounts($userIds, $dayStart) as $userId => $count) {
            $counts[$userId]['whatsapp'] = $count;
        }

        foreach ($this->manualRemarkCounts($userIds, $dayStart, $remarkEvents) as $userId => $count) {
            $counts[$userId]['remarks'] = $count;
        }

        return $counts;
    }

    /**
     * @param  list<int>  $userIds
     * @param  list<string>  $events
     * @return array<int, int>
     */
    private function countDirectEvents(array $userIds, Carbon $dayStart, array $events): array
    {
        if ($events === []) {
            return [];
        }

        return AuditLog::query()
            ->selectRaw('user_id, COUNT(*) as aggregate')
            ->whereIn('user_id', $userIds)
            ->whereIn('event', $events)
            ->where('created_at', '>=', $dayStart)
            ->groupBy('user_id')
            ->pluck('aggregate', 'user_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, int>
     */
    private function manualWhatsAppCounts(array $userIds, Carbon $dayStart): array
    {
        return AuditLog::query()
            ->selectRaw('user_id, COUNT(*) as aggregate')
            ->whereIn('user_id', $userIds)
            ->where('event', 'whatsapp.template_sent')
            ->where('new_values->trigger_source', WhatsAppTemplateTriggerSource::Manual->value)
            ->where('created_at', '>=', $dayStart)
            ->groupBy('user_id')
            ->pluck('aggregate', 'user_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * @param  list<int>  $userIds
     * @param  list<string>  $events
     * @return array<int, int>
     */
    private function manualRemarkCounts(array $userIds, Carbon $dayStart, array $events): array
    {
        $remarkMorph = (new Remark)->getMorphClass();
        $counts = array_fill_keys($userIds, 0);

        if (in_array('created', $events, true)) {
            foreach (
                AuditLog::query()
                    ->selectRaw('user_id, COUNT(*) as aggregate')
                    ->whereIn('user_id', $userIds)
                    ->where('event', 'created')
                    ->where('auditable_type', $remarkMorph)
                    ->where('new_values->origin', RemarkOrigin::Manual->value)
                    ->where('created_at', '>=', $dayStart)
                    ->groupBy('user_id')
                    ->pluck('aggregate', 'user_id') as $userId => $count
            ) {
                $counts[$userId] += (int) $count;
            }
        }

        if (in_array('deleted', $events, true)) {
            foreach (
                AuditLog::query()
                    ->selectRaw('user_id, COUNT(*) as aggregate')
                    ->whereIn('user_id', $userIds)
                    ->where('event', 'deleted')
                    ->where('auditable_type', $remarkMorph)
                    ->where('old_values->origin', RemarkOrigin::Manual->value)
                    ->where('created_at', '>=', $dayStart)
                    ->groupBy('user_id')
                    ->pluck('aggregate', 'user_id') as $userId => $count
            ) {
                $counts[$userId] += (int) $count;
            }
        }

        return $counts;
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, int>
     */
    private function callCountsForUsers(array $userIds, Carbon $dayStart): array
    {
        $users = User::query()
            ->whereIn('id', $userIds)
            ->whereNotNull('bonvoice_extension')
            ->get(['id', 'bonvoice_extension']);

        if ($users->isEmpty()) {
            return [];
        }

        $events = BonvoiceCallEvent::query()
            ->where('started_at', '>=', $dayStart)
            ->get([
                'id',
                'call_id',
                'destination_number',
                'source_number',
                'callback_params',
            ]);

        $counts = array_fill_keys($userIds, 0);
        $seen = [];

        foreach ($events as $event) {
            $userId = $this->resolveCallUserId($event, $users);

            if ($userId === null || ! isset($counts[$userId])) {
                continue;
            }

            $dedupeKey = $userId.':'.$event->call_id;

            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $seen[$dedupeKey] = true;
            $counts[$userId]++;
        }

        return $counts;
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function resolveCallUserId(BonvoiceCallEvent $event, Collection $users): ?int
    {
        $callbackParams = is_array($event->callback_params) ? $event->callback_params : [];
        $callbackUserId = (int) ($callbackParams['user_id'] ?? 0);

        if ($callbackUserId > 0 && $users->contains('id', $callbackUserId)) {
            return $callbackUserId;
        }

        foreach ([$event->destination_number, $event->source_number] as $phone) {
            if (! filled($phone)) {
                continue;
            }

            foreach ($users as $user) {
                if ($this->phoneNumbersMatch((string) $user->bonvoice_extension, (string) $phone)) {
                    return (int) $user->id;
                }
            }
        }

        return null;
    }

    private function phoneNumbersMatch(string $left, string $right): bool
    {
        $normalize = static function (string $value): string {
            $digits = preg_replace('/\D+/', '', $value) ?? '';

            if (strlen($digits) > 10) {
                return substr($digits, -10);
            }

            return $digits;
        };

        $leftDigits = $normalize($left);
        $rightDigits = $normalize($right);

        return $leftDigits !== '' && $leftDigits === $rightDigits;
    }

    /**
     * @return array<string, int>
     */
    private function emptyEffortBreakdown(): array
    {
        return [
            'calls' => 0,
            'whatsapp' => 0,
            'emails' => 0,
            'remarks' => 0,
            'status_updates' => 0,
        ];
    }
}

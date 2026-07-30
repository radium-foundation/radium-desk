<?php

namespace App\Services\Workforce\Contribution;

use App\Enums\RemarkOrigin;
use App\Enums\WhatsAppTemplateTriggerSource;
use App\Models\AuditLog;
use App\Models\BonvoiceCallEvent;
use App\Models\Remark;
use App\Models\ServiceCaseCloseOutcome;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only day-bounded queries over existing business activity sources.
 * Adapts metrics already used by Support/Activation KPIs — no new calculations.
 */
class ContributionActivityQuery
{
    /**
     * @param  list<string>  $events
     */
    public function countAuditEvents(int $userId, Carbon $workDate, array $events): int
    {
        if ($events === []) {
            return 0;
        }

        [$start, $end] = $this->dayWindow($workDate);

        return (int) AuditLog::query()
            ->where('user_id', $userId)
            ->whereIn('event', $events)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->count();
    }

    public function countManualWhatsApp(int $userId, Carbon $workDate): int
    {
        [$start, $end] = $this->dayWindow($workDate);

        return (int) AuditLog::query()
            ->where('user_id', $userId)
            ->where('event', 'whatsapp.template_sent')
            ->where('new_values->trigger_source', WhatsAppTemplateTriggerSource::Manual->value)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->count();
    }

    public function countManualRemarks(int $userId, Carbon $workDate): int
    {
        [$start, $end] = $this->dayWindow($workDate);
        $remarkMorph = (new Remark)->getMorphClass();
        $events = config('operations-kpi.support.effort_events.remarks', ['created', 'deleted']);
        $total = 0;

        if (in_array('created', $events, true)) {
            $total += (int) AuditLog::query()
                ->where('user_id', $userId)
                ->where('event', 'created')
                ->where('auditable_type', $remarkMorph)
                ->where('new_values->origin', RemarkOrigin::Manual->value)
                ->where('created_at', '>=', $start)
                ->where('created_at', '<', $end)
                ->count();
        }

        if (in_array('deleted', $events, true)) {
            $total += (int) AuditLog::query()
                ->where('user_id', $userId)
                ->where('event', 'deleted')
                ->where('auditable_type', $remarkMorph)
                ->where('old_values->origin', RemarkOrigin::Manual->value)
                ->where('created_at', '>=', $start)
                ->where('created_at', '<', $end)
                ->count();
        }

        return $total;
    }

    public function countCalls(User $user, Carbon $workDate): int
    {
        if (! filled($user->bonvoice_extension)) {
            return 0;
        }

        [$start, $end] = $this->dayWindow($workDate);

        $events = BonvoiceCallEvent::query()
            ->where('started_at', '>=', $start)
            ->where('started_at', '<', $end)
            ->get([
                'id',
                'call_id',
                'destination_number',
                'source_number',
                'callback_params',
            ]);

        $seen = [];
        $count = 0;
        $users = collect([$user]);

        foreach ($events as $event) {
            $matchedUserId = $this->resolveCallUserId($event, $users);

            if ($matchedUserId !== (int) $user->id) {
                continue;
            }

            $dedupeKey = $matchedUserId.':'.$event->call_id;

            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $seen[$dedupeKey] = true;
            $count++;
        }

        return $count;
    }

    public function countOrdersActivated(int $userId, Carbon $workDate): int
    {
        $event = (string) config(
            'operations-kpi.activation.orders_activated_event',
            'service_reference.assigned',
        );

        return $this->countAuditEvents($userId, $workDate, [$event]);
    }

    public function countCasesClosed(int $userId, Carbon $workDate): int
    {
        [$start, $end] = $this->dayWindow($workDate);

        return (int) ServiceCaseCloseOutcome::query()
            ->where('closed_by', $userId)
            ->where('closed_at', '>=', $start)
            ->where('closed_at', '<', $end)
            ->count();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function dayWindow(Carbon $workDate): array
    {
        $start = $workDate->copy()->startOfDay();

        return [$start, $start->copy()->addDay()];
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
}

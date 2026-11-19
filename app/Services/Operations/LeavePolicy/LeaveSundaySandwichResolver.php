<?php

namespace App\Services\Operations\LeavePolicy;

use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Owner rule: Sat/Mon leave with <7 calendar days' notice adds Sunday (once per weekend).
 */
class LeaveSundaySandwichResolver
{
    public function minimumNoticeDays(): int
    {
        return max(1, (int) config('leave_policy.sunday_sandwich.minimum_notice_calendar_days', 7));
    }

    /**
     * @return array{dates: list<string>, applied: bool, notice_days: ?int, metadata: array<string, mixed>}
     */
    public function resolve(
        Carbon $requestedStart,
        Carbon $requestedEnd,
        Carbon $submittedAt,
        ?User $user = null,
        ?int $excludeLeaveRequestId = null,
    ): array {
        $minNotice = $this->minimumNoticeDays();
        $submittedDay = $submittedAt->copy()->startOfDay();
        $extraSundays = [];
        $triggered = false;
        $minNoticeObserved = null;

        $cursor = $requestedStart->copy()->startOfDay();
        $end = $requestedEnd->copy()->startOfDay();

        while ($cursor->lte($end)) {
            $dow = (int) $cursor->dayOfWeek;
            if ($dow !== Carbon::SATURDAY && $dow !== Carbon::MONDAY) {
                $cursor->addDay();

                continue;
            }

            $noticeDays = (int) $submittedDay->diffInDays($cursor, false);
            if ($noticeDays < 0) {
                $noticeDays = 0;
            }

            if ($noticeDays < $minNotice) {
                $sunday = $dow === Carbon::SATURDAY
                    ? $cursor->copy()->addDay()
                    : $cursor->copy()->subDay();

                if ((int) $sunday->dayOfWeek === Carbon::SUNDAY) {
                    $isoWeek = $sunday->isoWeekYear.'-W'.$sunday->isoWeek;
                    if ($user !== null && $this->sundayAlreadyChargedForWeek($user, $sunday, $excludeLeaveRequestId)) {
                        $cursor->addDay();

                        continue;
                    }
                    if (! in_array($sunday->toDateString(), $extraSundays, true)) {
                        $extraSundays[] = $sunday->toDateString();
                    }
                    $triggered = true;
                    $minNoticeObserved = $minNoticeObserved === null
                        ? $noticeDays
                        : min($minNoticeObserved, $noticeDays);
                }
            }

            $cursor->addDay();
        }

        $effectiveStart = $requestedStart->copy()->startOfDay();
        $effectiveEnd = $requestedEnd->copy()->startOfDay();

        foreach ($extraSundays as $sundayDate) {
            $sunday = Carbon::parse($sundayDate)->startOfDay();
            if ($sunday->lt($effectiveStart)) {
                $effectiveStart = $sunday;
            }
            if ($sunday->gt($effectiveEnd)) {
                $effectiveEnd = $sunday;
            }
        }

        return [
            'dates' => $extraSundays,
            'applied' => $triggered,
            'notice_days' => $minNoticeObserved,
            'effective_start' => $effectiveStart,
            'effective_end' => $effectiveEnd,
            'metadata' => [
                'sunday_sandwich' => [
                    'applied' => $triggered,
                    'extra_sundays' => $extraSundays,
                    'minimum_notice_days' => $minNotice,
                    'notice_calendar_days_observed' => $minNoticeObserved,
                ],
            ],
        ];
    }

    private function sundayAlreadyChargedForWeek(User $user, Carbon $sunday, ?int $excludeLeaveRequestId): bool
    {
        $weekStart = $sunday->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $sunday->copy()->endOfWeek(Carbon::SUNDAY);

        $candidates = LeaveRequest::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['pending', 'approved'])
            ->when($excludeLeaveRequestId !== null, fn ($q) => $q->where('id', '!=', $excludeLeaveRequestId))
            ->get();

        foreach ($candidates as $leave) {
            $meta = $leave->policy_metadata ?? [];
            $charged = $meta['sunday_sandwich']['extra_sundays'] ?? [];
            foreach ($charged as $date) {
                $chargedSunday = Carbon::parse($date)->startOfDay();
                if ($chargedSunday->gte($weekStart) && $chargedSunday->lte($weekEnd)) {
                    return true;
                }
            }

            $effStart = $leave->policyEffectiveStart();
            $effEnd = $leave->policyEffectiveEnd();
            if ($effStart->lte($weekEnd) && $effEnd->gte($weekStart)) {
                $walk = $effStart->copy();
                while ($walk->lte($effEnd)) {
                    if ($walk->isSunday() && $walk->gte($weekStart) && $walk->lte($weekEnd)) {
                        $sandwich = $meta['sunday_sandwich']['applied'] ?? false;
                        if ($sandwich) {
                            return true;
                        }
                    }
                    $walk->addDay();
                }
            }
        }

        return false;
    }
}

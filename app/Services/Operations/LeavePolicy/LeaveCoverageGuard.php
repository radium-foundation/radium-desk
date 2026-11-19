<?php

namespace App\Services\Operations\LeavePolicy;

use App\Enums\LeaveRequestStatus;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class LeaveCoverageGuard
{
    /**
     * @return array{triad_configured: bool, admin_pair_configured: bool}
     */
    public function configurationStatus(): array
    {
        return [
            'triad_configured' => count($this->resolveTriadUserIds()) === 3,
            'admin_pair_configured' => count($this->resolveAdminPairUserIds()) === 2,
        ];
    }

    /**
     * @param  list<int>  $excludeLeaveRequestIds
     */
    public function assertCoverageAllows(
        User $requester,
        Carbon $effectiveStart,
        Carbon $effectiveEnd,
        array $excludeLeaveRequestIds = [],
    ): void {
        $this->assertTriadCoverage($requester, $effectiveStart, $effectiveEnd, $excludeLeaveRequestIds);
        $this->assertAdminPairCoverage($requester, $effectiveStart, $effectiveEnd, $excludeLeaveRequestIds);
    }

    /**
     * @param  list<int>  $excludeLeaveRequestIds
     */
    private function assertTriadCoverage(
        User $requester,
        Carbon $effectiveStart,
        Carbon $effectiveEnd,
        array $excludeLeaveRequestIds,
    ): void {
        $triadIds = $this->resolveTriadUserIds();
        if ($triadIds === [] || count($triadIds) < 3) {
            return;
        }

        if (! in_array((int) $requester->id, $triadIds, true)) {
            return;
        }

        $cursor = $effectiveStart->copy()->startOfDay();
        $end = $effectiveEnd->copy()->startOfDay();

        while ($cursor->lte($end)) {
            $onLeaveTriad = $this->triadOnLeaveOnDate($triadIds, $cursor, $requester, $excludeLeaveRequestIds);
            if (count($onLeaveTriad) >= 3) {
                throw ValidationException::withMessages([
                    'start_date' => 'Operational coverage rule: Shubhanshi, Abhinav and Kanchan cannot all be on leave on '
                        .$cursor->toDateString().'. Submit a manual exception for Management review.',
                ]);
            }
            $cursor->addDay();
        }
    }

    /**
     * @param  list<int>  $triadIds
     * @param  list<int>  $excludeLeaveRequestIds
     * @return list<int> user ids on leave that day (including hypothetical requester)
     */
    private function triadOnLeaveOnDate(
        array $triadIds,
        Carbon $day,
        User $requester,
        array $excludeLeaveRequestIds,
    ): array {
        $onLeave = [];
        $dayKey = $day->toDateString();

        foreach ($triadIds as $userId) {
            $wouldBeOnLeave = (int) $userId === (int) $requester->id
                || $this->userHasActiveLeaveCovering($userId, $dayKey, $excludeLeaveRequestIds);

            if ($wouldBeOnLeave) {
                $onLeave[] = (int) $userId;
            }
        }

        return $onLeave;
    }

    /**
     * @param  list<int>  $excludeLeaveRequestIds
     */
    private function assertAdminPairCoverage(
        User $requester,
        Carbon $effectiveStart,
        Carbon $effectiveEnd,
        array $excludeLeaveRequestIds,
    ): void {
        $pairIds = $this->resolveAdminPairUserIds();
        if (count($pairIds) !== 2) {
            return;
        }

        if (! in_array((int) $requester->id, $pairIds, true)) {
            return;
        }

        $cursor = $effectiveStart->copy()->startOfDay();
        $end = $effectiveEnd->copy()->startOfDay();

        while ($cursor->lte($end)) {
            $dayKey = $cursor->toDateString();
            $both = true;
            foreach ($pairIds as $userId) {
                $onLeave = (int) $userId === (int) $requester->id
                    || $this->userHasActiveLeaveCovering($userId, $dayKey, $excludeLeaveRequestIds);
                if (! $onLeave) {
                    $both = false;
                    break;
                }
            }
            if ($both) {
                throw ValidationException::withMessages([
                    'start_date' => 'Administrator coverage rule: Dileep and Avinash cannot both be on leave on '
                        .$dayKey.'. Submit a manual exception for Management review.',
                ]);
            }
            $cursor->addDay();
        }
    }

    /**
     * @param  list<int>  $excludeLeaveRequestIds
     */
    private function userHasActiveLeaveCovering(int $userId, string $dayKey, array $excludeLeaveRequestIds): bool
    {
        return LeaveRequest::query()
            ->where('user_id', $userId)
            ->whereIn('status', [
                LeaveRequestStatus::Pending->value,
                LeaveRequestStatus::Approved->value,
            ])
            ->when($excludeLeaveRequestIds !== [], fn ($q) => $q->whereNotIn('id', $excludeLeaveRequestIds))
            ->where(function ($query) use ($dayKey): void {
                $query->where(function ($q) use ($dayKey): void {
                    $q->whereNotNull('policy_effective_start_date')
                        ->whereDate('policy_effective_start_date', '<=', $dayKey)
                        ->whereDate('policy_effective_end_date', '>=', $dayKey);
                })->orWhere(function ($q) use ($dayKey): void {
                    $q->whereNull('policy_effective_start_date')
                        ->whereDate('start_date', '<=', $dayKey)
                        ->whereDate('end_date', '>=', $dayKey);
                });
            })
            ->exists();
    }

    /**
     * @return list<int>
     */
    public function resolveTriadUserIds(): array
    {
        return $this->resolveUserIdsFromEmails(
            config('leave_policy.coverage.triad_emails', []),
        );
    }

    /**
     * @return list<int>
     */
    public function resolveAdminPairUserIds(): array
    {
        return $this->resolveUserIdsFromEmails(
            config('leave_policy.coverage.admin_pair_emails', []),
        );
    }

    /**
     * @param  list<string>  $emails
     * @return list<int>
     */
    private function resolveUserIdsFromEmails(array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        return User::query()
            ->where('is_active', true)
            ->where(function ($query) use ($emails): void {
                foreach ($emails as $email) {
                    $query->orWhereRaw('LOWER(email) = ?', [strtolower($email)]);
                }
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function lockCoverageGroupRows(User $requester): void
    {
        $ids = array_unique(array_merge(
            $this->resolveTriadUserIds(),
            $this->resolveAdminPairUserIds(),
            [(int) $requester->id],
        ));

        if ($ids === []) {
            return;
        }

        LeaveRequest::query()
            ->whereIn('user_id', $ids)
            ->whereIn('status', [
                LeaveRequestStatus::Pending->value,
                LeaveRequestStatus::Approved->value,
            ])
            ->lockForUpdate()
            ->get();
    }
}

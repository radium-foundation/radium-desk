<?php

namespace App\Services\Operations;

use App\Data\Operations\AttendanceDayResult;
use App\Models\User;
use App\Models\WorkforceAttendanceDay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AttendanceRegisterService
{
    public function __construct(
        private readonly AttendanceDayCalculator $calculator,
        private readonly OperationsRoleService $roleService,
    ) {}

    public function refreshDay(
        User $user,
        ?Carbon $workDate = null,
        ?Carbon $referenceAt = null,
        bool $allowPreShiftSkip = true,
    ): ?WorkforceAttendanceDay {
        $workDate ??= ($referenceAt ?? now())->copy()->startOfDay();
        $referenceAt ??= now();

        $result = $this->calculator->compute(
            user: $user,
            workDate: $workDate,
            referenceAt: $referenceAt,
            allowPreShiftSkip: $allowPreShiftSkip,
        );

        if ($result === null) {
            return null;
        }

        return $this->persist($result);
    }

    public function refreshDateRange(
        User $user,
        Carbon $startDate,
        Carbon $endDate,
        ?Carbon $referenceAt = null,
    ): int {
        $start = $startDate->copy()->startOfDay();
        $end = $endDate->copy()->startOfDay();
        $refreshed = 0;
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            if ($this->refreshDay(
                user: $user,
                workDate: $cursor->copy(),
                referenceAt: $referenceAt ?? $cursor->copy()->endOfDay(),
                allowPreShiftSkip: false,
            ) !== null) {
                $refreshed++;
            }

            $cursor->addDay();
        }

        return $refreshed;
    }

    public function findDay(User $user, Carbon $workDate): ?WorkforceAttendanceDay
    {
        return WorkforceAttendanceDay::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', $workDate->toDateString())
            ->first();
    }

    public function resolveDay(
        User $user,
        Carbon $workDate,
        ?Carbon $referenceAt = null,
        bool $allowPreShiftSkip = false,
    ): ?WorkforceAttendanceDay {
        $existing = $this->findDay($user, $workDate);

        if ($existing !== null) {
            return $existing;
        }

        return $this->refreshDay(
            user: $user,
            workDate: $workDate,
            referenceAt: $referenceAt,
            allowPreShiftSkip: $allowPreShiftSkip,
        );
    }

    /**
     * @return Collection<int, WorkforceAttendanceDay>
     */
    public function resolveDaysForRange(
        User $user,
        Carbon $startDate,
        Carbon $endDate,
        ?Carbon $referenceAt = null,
        bool $allowPreShiftSkip = false,
    ): Collection {
        $start = $startDate->copy()->startOfDay();
        $end = $endDate->copy()->startOfDay();
        $referenceAt ??= now();

        $existing = WorkforceAttendanceDay::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', '>=', $start->toDateString())
            ->whereDate('work_date', '<=', $end->toDateString())
            ->get()
            ->keyBy(fn (WorkforceAttendanceDay $day): string => $day->work_date->toDateString());

        $resolved = collect();
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $dateString = $cursor->toDateString();
            $day = $existing->get($dateString);

            if ($day === null) {
                $dayReference = $cursor->isSameDay($referenceAt)
                    ? $referenceAt
                    : $cursor->copy()->endOfDay();

                $day = $this->refreshDay(
                    user: $user,
                    workDate: $cursor->copy(),
                    referenceAt: $dayReference,
                    allowPreShiftSkip: $allowPreShiftSkip && $cursor->isSameDay($referenceAt),
                );
            }

            if ($day !== null) {
                $resolved->push($day);
            }

            $cursor->addDay();
        }

        return $resolved->values();
    }

    /**
     * @return Collection<int, WorkforceAttendanceDay>
     */
    public function resolveTrackedDaysOnDate(
        Carbon $workDate,
        ?Carbon $referenceAt = null,
        ?iterable $users = null,
        bool $allowPreShiftSkip = false,
    ): Collection {
        $referenceAt ??= now();
        $days = collect();

        foreach ($this->resolveUsers($users) as $user) {
            $day = $this->resolveDay(
                user: $user,
                workDate: $workDate,
                referenceAt: $referenceAt,
                allowPreShiftSkip: $allowPreShiftSkip,
            );

            if ($day !== null) {
                $days->push($day);
            }
        }

        if ($days->isEmpty()) {
            return $days;
        }

        return WorkforceAttendanceDay::query()
            ->with('user')
            ->whereIn('id', $days->pluck('id'))
            ->get();
    }

    public function reconcileRange(
        ?Carbon $startDate = null,
        ?Carbon $endDate = null,
        ?iterable $users = null,
    ): int {
        $end = ($endDate ?? now())->copy()->startOfDay();
        $start = ($startDate ?? $end->copy()->subDays(89))->copy()->startOfDay();
        $reconciled = 0;

        foreach ($this->resolveUsers($users) as $user) {
            $reconciled += $this->refreshDateRange(
                user: $user,
                startDate: $start,
                endDate: $end,
            );
        }

        return $reconciled;
    }

    private function persist(AttendanceDayResult $result): WorkforceAttendanceDay
    {
        $attributes = $result->persistenceAttributes();
        $workDate = $result->workDate->toDateString();

        $existing = WorkforceAttendanceDay::query()
            ->where('user_id', $result->userId)
            ->whereDate('work_date', $workDate)
            ->first();

        if ($existing !== null) {
            $existing->fill($attributes)->save();

            return $existing->fresh();
        }

        return WorkforceAttendanceDay::query()->create([
            'user_id' => $result->userId,
            'work_date' => $workDate,
            ...$attributes,
        ]);
    }

    /**
     * @return iterable<int, User>
     */
    private function resolveUsers(?iterable $users): iterable
    {
        if ($users !== null) {
            return $users;
        }

        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $this->roleService->attendanceTrackedRoleSlugs()))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->filter(fn (User $user): bool => $this->roleService->isAttendanceTracked($user));
    }
}

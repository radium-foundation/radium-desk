<?php

namespace App\Services\Workforce;

use App\Contracts\Workforce\WorkforceEventPublisher;
use App\Data\Workforce\PayrollMonthLockStatus;
use App\Data\Workforce\WorkforceEvent;
use App\Enums\WorkforceAuditEvent;
use App\Enums\WorkforceEventType;
use App\Models\PayrollMonthLock;
use App\Models\User;
use App\Services\AuditLogService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollMonthLockService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly WorkforceEventPublisher $workforceEventPublisher,
    ) {}

    public function isMonthLocked(Carbon $monthOrDay): bool
    {
        $month = $monthOrDay->copy()->startOfMonth()->toDateString();

        return PayrollMonthLock::query()
            ->whereDate('month', $month)
            ->whereNull('unlocked_at')
            ->exists();
    }

    public function statusForMonth(Carbon $month): PayrollMonthLockStatus
    {
        $monthStart = $month->copy()->startOfMonth();
        $lock = PayrollMonthLock::query()
            ->with('locker')
            ->whereDate('month', $monthStart->toDateString())
            ->first();

        if ($lock === null || ! $lock->isCurrentlyLocked()) {
            return new PayrollMonthLockStatus(
                state: 'open',
                month: $monthStart,
            );
        }

        return new PayrollMonthLockStatus(
            state: 'locked',
            month: $monthStart,
            lockedBy: $lock->locker?->name,
            lockedById: $lock->locked_by,
            lockedOn: $lock->locked_at?->copy(),
            reason: $lock->reason,
        );
    }

    public function lock(Carbon $month, User $actor, ?string $reason = null): PayrollMonthLock
    {
        $this->assertSuperAdmin($actor);

        $monthStart = $month->copy()->startOfMonth();
        $reason = $this->normalizeReason($reason);

        $lock = DB::transaction(function () use ($monthStart, $actor, $reason): PayrollMonthLock {
            $existing = PayrollMonthLock::query()
                ->whereDate('month', $monthStart->toDateString())
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->isCurrentlyLocked()) {
                throw ValidationException::withMessages([
                    'month' => 'This payroll month is already locked.',
                ]);
            }

            if ($existing !== null) {
                $existing->fill([
                    'locked_by' => $actor->id,
                    'locked_at' => now(),
                    'unlocked_by' => null,
                    'unlocked_at' => null,
                    'reason' => $reason,
                ])->save();

                return $existing->fresh(['locker']);
            }

            return PayrollMonthLock::query()->create([
                'month' => $monthStart->toDateString(),
                'locked_by' => $actor->id,
                'locked_at' => now(),
                'reason' => $reason,
            ])->fresh(['locker']);
        });

        $this->audit(
            event: WorkforceAuditEvent::PayrollLocked,
            actor: $actor,
            lock: $lock,
            newValues: [
                'action' => 'lock',
                'month' => $monthStart->format('Y-m'),
                'reason' => $reason,
                'locked_by' => $actor->id,
                'locked_at' => $lock->locked_at?->toIso8601String(),
            ],
        );

        $this->workforceEventPublisher->publish(WorkforceEvent::make(
            type: WorkforceEventType::PayrollLocked,
            userId: $actor->id,
            workDate: $monthStart,
            payload: [
                'month' => $monthStart->format('Y-m'),
                'payroll_month_lock_id' => $lock->id,
                'reason' => $reason,
            ],
        ));

        return $lock;
    }

    public function unlock(Carbon $month, User $actor, ?string $reason = null): PayrollMonthLock
    {
        $this->assertSuperAdmin($actor);

        $monthStart = $month->copy()->startOfMonth();
        $reason = $this->normalizeReason($reason);

        $lock = DB::transaction(function () use ($monthStart, $actor): PayrollMonthLock {
            $existing = PayrollMonthLock::query()
                ->whereDate('month', $monthStart->toDateString())
                ->lockForUpdate()
                ->first();

            if ($existing === null || ! $existing->isCurrentlyLocked()) {
                throw ValidationException::withMessages([
                    'month' => 'This payroll month is not locked.',
                ]);
            }

            $existing->fill([
                'unlocked_by' => $actor->id,
                'unlocked_at' => now(),
            ])->save();

            return $existing->fresh(['locker', 'unlocker']);
        });

        $this->audit(
            event: WorkforceAuditEvent::PayrollUnlocked,
            actor: $actor,
            lock: $lock,
            newValues: [
                'action' => 'unlock',
                'month' => $monthStart->format('Y-m'),
                'reason' => $reason,
                'unlocked_by' => $actor->id,
                'unlocked_at' => $lock->unlocked_at?->toIso8601String(),
            ],
        );

        return $lock;
    }

    public function assertLeaveWritable(Carbon $startDate, Carbon $endDate): void
    {
        $cursor = $startDate->copy()->startOfMonth();
        $end = $endDate->copy()->startOfMonth();

        while ($cursor->lte($end)) {
            if ($this->isMonthLocked($cursor)) {
                throw ValidationException::withMessages([
                    'start_date' => sprintf(
                        'Payroll month %s is locked. Leave cannot be created, approved, or rejected for this period.',
                        $cursor->format('Y-m'),
                    ),
                ]);
            }

            $cursor->addMonth();
        }
    }

    public function assertDateWritable(Carbon $date): void
    {
        if ($this->isMonthLocked($date)) {
            throw ValidationException::withMessages([
                'holiday_date' => sprintf(
                    'Payroll month %s is locked. Attendance-affecting changes are not allowed.',
                    $date->copy()->startOfMonth()->format('Y-m'),
                ),
            ]);
        }
    }

    private function assertSuperAdmin(User $actor): void
    {
        if (! $actor->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN)) {
            throw ValidationException::withMessages([
                'month' => 'Only Super Admin can lock or unlock a payroll month.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $newValues
     */
    private function audit(
        WorkforceAuditEvent $event,
        User $actor,
        PayrollMonthLock $lock,
        array $newValues,
    ): void {
        $this->auditLogService->log(
            userId: $actor->id,
            event: $event->value,
            auditable: $lock,
            newValues: [
                ...$newValues,
                'legacy_event' => $event->legacyEvent(),
            ],
        );
    }

    private function normalizeReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        $trimmed = trim($reason);

        return $trimmed === '' ? null : $trimmed;
    }
}

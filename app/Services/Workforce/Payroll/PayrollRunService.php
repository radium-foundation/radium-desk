<?php

namespace App\Services\Workforce\Payroll;

use App\Contracts\Workforce\WorkforceEventPublisher;
use App\Data\Workforce\Payroll\PayrollMonthResult;
use App\Data\Workforce\WorkforceEvent;
use App\Enums\PayrollRunStatus;
use App\Enums\WorkforceAuditEvent;
use App\Enums\WorkforceEventType;
use App\Models\PayrollMonthRun;
use App\Models\PayrollRunLine;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Workforce\PayrollMonthLockService;
use App\Services\Workforce\ShortAttendance\ShortAttendanceReviewService;
use App\Support\Workforce\AttendanceManagementAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Historical payroll foundation: draft (live) vs finalized (immutable snapshot).
 *
 * Attendance month lock remains separate — finalize never auto-locks,
 * but requires an existing attendance lock for the same month.
 */
class PayrollRunService
{
    public const CALCULATION_VERSION = 'phase1.day_rate.v1';

    public const ATTENDANCE_LOCK_REQUIRED_MESSAGE = 'Lock attendance for this month before finalizing payroll.';

    public function __construct(
        private readonly PayrollCalculationService $payrollCalculationService,
        private readonly PayrollMonthLockService $payrollMonthLockService,
        private readonly AuditLogService $auditLogService,
        private readonly WorkforceEventPublisher $workforceEventPublisher,
        private readonly ShortAttendanceReviewService $shortAttendanceReviewService,
    ) {}

    public function createDraft(Carbon $month, ?string $notes = null): PayrollMonthRun
    {
        $monthStart = $month->copy()->startOfMonth();

        return DB::transaction(function () use ($monthStart, $notes): PayrollMonthRun {
            $existing = PayrollMonthRun::query()
                ->whereDate('month', $monthStart->toDateString())
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->isFinalized()) {
                    throw ValidationException::withMessages([
                        'month' => 'This payroll month is already finalized.',
                    ]);
                }

                if ($notes !== null) {
                    $existing->notes = $notes;
                    $existing->save();
                }

                return $existing->fresh();
            }

            return PayrollMonthRun::query()->create([
                'month' => $monthStart->toDateString(),
                'status' => PayrollRunStatus::Draft,
                'calculation_version' => self::CALCULATION_VERSION,
                'notes' => $notes,
            ]);
        });
    }

    /**
     * Calculate every tracked employee once and freeze the month.
     */
    public function finalize(Carbon $month, User $actor, ?string $notes = null): PayrollMonthRun
    {
        $this->assertCanFinalize($actor);

        $monthStart = $month->copy()->startOfMonth();
        $this->shortAttendanceReviewService->assertNoPendingForMonth($monthStart);
        $notes = $this->normalizeNotes($notes);

        if (! $this->payrollMonthLockService->isMonthLocked($monthStart)) {
            throw ValidationException::withMessages([
                'month' => self::ATTENDANCE_LOCK_REQUIRED_MESSAGE,
            ]);
        }

        $run = DB::transaction(function () use ($monthStart, $actor, $notes): PayrollMonthRun {
            $existing = PayrollMonthRun::query()
                ->whereDate('month', $monthStart->toDateString())
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->isFinalized()) {
                throw ValidationException::withMessages([
                    'month' => 'This payroll month is already finalized.',
                ]);
            }

            $run = $existing ?? PayrollMonthRun::query()->create([
                'month' => $monthStart->toDateString(),
                'status' => PayrollRunStatus::Draft,
                'calculation_version' => self::CALCULATION_VERSION,
                'notes' => $notes,
            ]);

            // Replace any prior draft lines (should be empty for draft-display-live mode).
            $run->lines()->delete();

            $results = $this->payrollCalculationService->calculateForTrackedUsers($monthStart);

            if ($results->isEmpty()) {
                throw ValidationException::withMessages([
                    'month' => 'Cannot finalize payroll with no employees who have an active salary for this month.',
                ]);
            }

            foreach ($results as $result) {
                $this->storeLine($run, $result);
            }

            $run->fill([
                'status' => PayrollRunStatus::Finalized,
                'finalized_at' => now(),
                'finalized_by' => $actor->id,
                'calculation_version' => self::CALCULATION_VERSION,
                'notes' => $notes ?? $run->notes,
            ])->save();

            return $run->fresh(['lines.user', 'lines.salaryRevision', 'finalizer']);
        });

        $this->audit(
            event: WorkforceAuditEvent::PayrollFinalized,
            actor: $actor,
            run: $run,
            newValues: [
                'action' => 'finalize',
                'month' => $monthStart->format('Y-m'),
                'notes' => $notes,
                'line_count' => $run->lines->count(),
                'calculation_version' => $run->calculation_version,
                'finalized_by' => $actor->id,
                'finalized_at' => $run->finalized_at?->toIso8601String(),
            ],
        );

        $this->workforceEventPublisher->publish(WorkforceEvent::make(
            type: WorkforceEventType::PayrollFinalized,
            userId: $actor->id,
            workDate: $monthStart,
            payload: [
                'month' => $monthStart->format('Y-m'),
                'payroll_month_run_id' => $run->id,
                'line_count' => $run->lines->count(),
                'calculation_version' => $run->calculation_version,
            ],
        ));

        return $run;
    }

    public function loadFinalized(Carbon $month): ?PayrollMonthRun
    {
        $monthStart = $month->copy()->startOfMonth();

        return PayrollMonthRun::query()
            ->with(['lines.user', 'lines.salaryRevision', 'finalizer'])
            ->whereDate('month', $monthStart->toDateString())
            ->where('status', PayrollRunStatus::Finalized)
            ->first();
    }

    public function isFinalized(Carbon $month): bool
    {
        return $this->loadFinalized($month) !== null;
    }

    /**
     * Rows for the payroll screen: finalized snapshot or live calculation.
     *
     * @return Collection<int, PayrollMonthResult>
     */
    public function resultsForMonth(Carbon $month): Collection
    {
        $monthStart = $month->copy()->startOfMonth();
        $run = $this->loadFinalized($monthStart);

        if ($run !== null) {
            return $run->lines
                ->sortBy(fn (PayrollRunLine $line): string => (string) ($line->user?->name ?? ''))
                ->values()
                ->map(fn (PayrollRunLine $line): PayrollMonthResult => PayrollMonthResult::fromRunLine($line, $monthStart));
        }

        return $this->payrollCalculationService->calculateForTrackedUsers($monthStart);
    }

    public function resultForUser(User $user, Carbon $month): ?PayrollMonthResult
    {
        $monthStart = $month->copy()->startOfMonth();
        $run = $this->loadFinalized($monthStart);

        if ($run !== null) {
            $line = $run->lines->firstWhere('user_id', $user->id);

            return $line !== null
                ? PayrollMonthResult::fromRunLine($line, $monthStart)
                : null;
        }

        return $this->payrollCalculationService->calculateForUser($user, $monthStart);
    }

    /**
     * Future: reopen a finalized month back to draft. Not implemented in Phase 1.5.
     * Reserved for Super Admin (workforce.payroll.reopen).
     */
    public function reopen(Carbon $month, User $actor): never
    {
        if (! AttendanceManagementAccess::allowsPayrollReopen($actor)) {
            throw ValidationException::withMessages([
                'month' => 'Only Super Admin can reopen a finalized payroll month.',
            ]);
        }

        throw new RuntimeException('Payroll reopen is not implemented yet.');
    }

    private function storeLine(PayrollMonthRun $run, PayrollMonthResult $result): PayrollRunLine
    {
        return PayrollRunLine::query()->create([
            'run_id' => $run->id,
            'user_id' => $result->userId,
            'salary_revision_id' => $result->salaryRecord?->id,
            'monthly_salary_snapshot' => $result->monthlySalary,
            'calendar_days' => $result->calendarDays,
            'day_rate' => $result->dayRate,
            'payable_days' => $result->payableDays,
            'non_payable_days' => $result->nonPayableDays,
            'gross_salary' => $result->grossSalary,
            'net_salary' => $result->netSalary,
            'attendance_summary_json' => [
                'present' => $result->presentDays,
                'late' => $result->lateDays,
                'leave' => $result->leaveDays,
                'half_day' => $result->halfDayDays,
                'weekly_off' => $result->weeklyOffDays,
                'holiday' => $result->holidayDays,
                'absent' => $result->absentDays,
                'extra' => $result->extraDays,
                'salary_effective_from' => $result->salaryRecord?->effective_from?->toDateString(),
            ],
        ]);
    }

    private function assertCanFinalize(User $actor): void
    {
        if (! AttendanceManagementAccess::allowsPayroll($actor)) {
            throw ValidationException::withMessages([
                'month' => 'You are not allowed to finalize payroll.',
            ]);
        }
    }

    private function normalizeNotes(?string $notes): ?string
    {
        if ($notes === null) {
            return null;
        }

        $trimmed = trim($notes);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<string, mixed>  $newValues
     */
    private function audit(
        WorkforceAuditEvent $event,
        User $actor,
        PayrollMonthRun $run,
        array $newValues,
    ): void {
        $this->auditLogService->log(
            userId: $actor->id,
            event: $event->value,
            auditable: $run,
            newValues: array_merge($newValues, [
                'legacy_event' => $event->legacyEvent(),
            ]),
        );
    }
}

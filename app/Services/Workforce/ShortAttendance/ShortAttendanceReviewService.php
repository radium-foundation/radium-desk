<?php

namespace App\Services\Workforce\ShortAttendance;

use App\Contracts\Workforce\CalendarPolicy;
use App\Contracts\Workforce\WorkforceEventPublisher;
use App\Data\Workforce\WorkforceEvent;
use App\Enums\AttendanceDayStatus;
use App\Enums\AttendanceMatrixCellKind;
use App\Enums\ShortAttendanceReviewDecision;
use App\Enums\ShortAttendanceReviewStatus;
use App\Enums\WorkforceAuditEvent;
use App\Enums\WorkforceEventType;
use App\Models\User;
use App\Models\WorkSession;
use App\Models\WorkforceAttendanceDay;
use App\Models\WorkforceShortAttendanceReview;
use App\Services\AuditLogService;
use App\Support\Workforce\AttendanceManagementAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 / 2.1 review layer for Short Attendance.
 * Does not mutate Phase 1 register calculation — only stores HR decisions
 * consumed by matrix/payroll via AttendanceMatrixCellMapper.
 */
class ShortAttendanceReviewService
{
    public const PAYROLL_PENDING_BLOCK_MESSAGE = 'Cannot finalize payroll. Short Attendance reviews are still pending.';

    public function __construct(
        private readonly CalendarPolicy $workCalendarService,
        private readonly AuditLogService $auditLogService,
        private readonly WorkforceEventPublisher $workforceEventPublisher,
    ) {}

    public function canView(?User $actor): bool
    {
        if ($actor === null || ! AttendanceManagementAccess::allows($actor)) {
            return false;
        }

        return $actor->can(RolePermissionSeeder::PERMISSION_SHORT_ATTENDANCE_VIEW);
    }

    public function canDecide(?User $actor): bool
    {
        if (! $this->canView($actor)) {
            return false;
        }

        return $actor->can(RolePermissionSeeder::PERMISSION_SHORT_ATTENDANCE_REVIEW);
    }

    public function ensurePendingForDay(WorkforceAttendanceDay $day): ?WorkforceShortAttendanceReview
    {
        if ($day->status !== AttendanceDayStatus::ShortAttendance) {
            $this->discardPendingIfStale($day);

            return null;
        }

        $existing = WorkforceShortAttendanceReview::query()
            ->where('user_id', $day->user_id)
            ->whereDate('work_date', $day->work_date->toDateString())
            ->first();

        if ($existing !== null && $existing->isDecided()) {
            return $existing;
        }

        $user = $day->relationLoaded('user')
            ? $day->user
            : User::query()->find($day->user_id);

        if ($user === null) {
            return null;
        }

        $attributes = $this->evidenceAttributes($user, $day);

        if ($existing !== null) {
            $existing->fill([
                ...$attributes,
                'status' => ShortAttendanceReviewStatus::PendingReview,
                'previous_status' => AttendanceDayStatus::ShortAttendance->value,
            ])->save();

            return $existing->fresh(['user']);
        }

        $review = WorkforceShortAttendanceReview::query()->create([
            ...$attributes,
            'user_id' => $user->id,
            'work_date' => $day->work_date->toDateString(),
            'status' => ShortAttendanceReviewStatus::PendingReview,
            'previous_status' => AttendanceDayStatus::ShortAttendance->value,
        ]);

        $this->auditLogService->log(
            userId: null,
            event: WorkforceAuditEvent::ShortAttendanceReviewCreated->value,
            auditable: $review,
            newValues: [
                'action' => 'create',
                'worked_minutes' => $review->worked_minutes,
                'calculated_reason' => $review->calculated_reason,
                'legacy_event' => WorkforceAuditEvent::ShortAttendanceReviewCreated->legacyEvent(),
            ],
        );

        return $review->fresh(['user']);
    }

    public function syncPendingForMonth(Carbon $month): int
    {
        return $this->syncPendingForRange(
            $month->copy()->startOfMonth(),
            $month->copy()->endOfMonth()->startOfDay(),
        );
    }

    public function syncPendingForRange(Carbon $from, Carbon $to): int
    {
        $start = $from->copy()->startOfDay()->toDateString();
        $end = $to->copy()->startOfDay()->toDateString();

        $days = WorkforceAttendanceDay::query()
            ->with('user')
            ->where('status', AttendanceDayStatus::ShortAttendance)
            ->whereDate('work_date', '>=', $start)
            ->whereDate('work_date', '<=', $end)
            ->orderBy('work_date')
            ->get();

        $touched = 0;
        foreach ($days as $day) {
            if ($this->ensurePendingForDay($day) !== null) {
                $touched++;
            }
        }

        return $touched;
    }

    public function pendingCount(?Carbon $month = null): int
    {
        $query = WorkforceShortAttendanceReview::query()
            ->where('status', ShortAttendanceReviewStatus::PendingReview);

        if ($month !== null) {
            $query
                ->whereDate('work_date', '>=', $month->copy()->startOfMonth()->toDateString())
                ->whereDate('work_date', '<=', $month->copy()->endOfMonth()->toDateString());
        }

        return $query->count();
    }

    public function pendingCountForDate(Carbon $date): int
    {
        return WorkforceShortAttendanceReview::query()
            ->where('status', ShortAttendanceReviewStatus::PendingReview)
            ->whereDate('work_date', $date->toDateString())
            ->count();
    }

    /**
     * @return array{today: int, yesterday: int, total: int}
     */
    public function dashboardPendingCounts(?Carbon $at = null): array
    {
        $at ??= now();
        $today = $at->copy()->startOfDay();
        $yesterday = $today->copy()->subDay();

        $this->syncPendingForRange($yesterday, $today);

        return [
            'today' => $this->pendingCountForDate($today),
            'yesterday' => $this->pendingCountForDate($yesterday),
            'total' => $this->pendingCount(),
        ];
    }

    public function hasYesterdayPendingReminder(?Carbon $at = null): bool
    {
        $at ??= now();

        return $this->pendingCountForDate($at->copy()->subDay()->startOfDay()) > 0;
    }

    /**
     * Block payroll lock/finalize when unresolved SA reviews remain for the month.
     */
    public function assertNoPendingForMonth(Carbon $month): void
    {
        $monthStart = $month->copy()->startOfMonth();
        $this->syncPendingForMonth($monthStart);
        $pending = $this->pendingCount($monthStart);

        if ($pending <= 0) {
            return;
        }

        $queueUrl = route('workforce-management.short-attendance.index', [
            'period' => ShortAttendanceReviewQueryService::PERIOD_THIS_MONTH,
            'status' => 'pending',
            'month' => $monthStart->format('Y-m'),
        ]);

        throw ValidationException::withMessages([
            'month' => sprintf(
                'Cannot finalize payroll. %d Short Attendance %s still pending. Open Review Queue: %s',
                $pending,
                $pending === 1 ? 'review is' : 'reviews are',
                $queueUrl,
            ),
        ]);
    }

    public function designatedReviewer(): ?User
    {
        $email = strtolower(trim((string) config(
            'workforce.short_attendance.reviewer_email',
            config('workforce.leave_approver.email', ''),
        )));

        if ($email === '') {
            return null;
        }

        return User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('is_active', true)
            ->first();
    }

    /**
     * Evening digest: notify designated HR when today's SA pending > 0.
     *
     * @return array{sent: bool, pending_today: int, recipient_id: ?int}
     */
    public function sendEveningReviewNotification(?Carbon $at = null): array
    {
        $at ??= now();
        $today = $at->copy()->startOfDay();
        $this->syncPendingForRange($today, $today);
        $pendingToday = $this->pendingCountForDate($today);

        if ($pendingToday <= 0) {
            return ['sent' => false, 'pending_today' => 0, 'recipient_id' => null];
        }

        $recipient = $this->designatedReviewer();
        if ($recipient === null || ! $this->canView($recipient)) {
            return ['sent' => false, 'pending_today' => $pendingToday, 'recipient_id' => null];
        }

        $recipient->notify(new \App\Notifications\ShortAttendanceEveningReviewNotification(
            pendingToday: $pendingToday,
            workDate: $today,
        ));

        return [
            'sent' => true,
            'pending_today' => $pendingToday,
            'recipient_id' => $recipient->id,
        ];
    }

    /**
     * @param  list<int>  $userIds
     * @return array<string, AttendanceMatrixCellKind> keyed by "{userId}:{Y-m-d}"
     */
    public function decidedOverridesFor(array $userIds, Carbon $from, Carbon $to): array
    {
        if ($userIds === []) {
            return [];
        }

        $reviews = WorkforceShortAttendanceReview::query()
            ->whereIn('user_id', $userIds)
            ->where('status', ShortAttendanceReviewStatus::Decided)
            ->whereNotNull('decision')
            ->whereDate('work_date', '>=', $from->toDateString())
            ->whereDate('work_date', '<=', $to->toDateString())
            ->get(['user_id', 'work_date', 'decision']);

        $map = [];
        foreach ($reviews as $review) {
            if (! $review->decision instanceof ShortAttendanceReviewDecision) {
                continue;
            }

            $key = $review->user_id.':'.$review->work_date->toDateString();
            $map[$key] = $review->decision->finalMatrixKind();
        }

        return $map;
    }

    public function decidedOverrideForDay(int $userId, Carbon $workDate): ?AttendanceMatrixCellKind
    {
        $map = $this->decidedOverridesFor(
            [$userId],
            $workDate->copy()->startOfDay(),
            $workDate->copy()->startOfDay(),
        );

        return $map[$userId.':'.$workDate->toDateString()] ?? null;
    }

    public function decide(
        WorkforceShortAttendanceReview $review,
        User $actor,
        ShortAttendanceReviewDecision $decision,
        string $reason,
        ?string $note = null,
    ): WorkforceShortAttendanceReview {
        if (! $this->canDecide($actor)) {
            throw ValidationException::withMessages([
                'decision' => 'You are not authorized to review Short Attendance.',
            ]);
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'decision_reason' => 'A reason is required for every Short Attendance decision.',
            ]);
        }

        $note = $note !== null ? trim($note) : null;
        $note = $note === '' ? null : $note;

        return DB::transaction(function () use ($review, $actor, $decision, $reason, $note): WorkforceShortAttendanceReview {
            /** @var WorkforceShortAttendanceReview|null $locked */
            $locked = WorkforceShortAttendanceReview::query()
                ->whereKey($review->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw ValidationException::withMessages([
                    'review' => 'Short Attendance review was not found.',
                ]);
            }

            if ($locked->isDecided()) {
                throw ValidationException::withMessages([
                    'status' => 'This Short Attendance case has already been reviewed.',
                ]);
            }

            $previousStatus = $locked->previous_status ?: AttendanceDayStatus::ShortAttendance->value;
            $newStatus = $decision->newStatusValue();

            $locked->fill([
                'status' => ShortAttendanceReviewStatus::Decided,
                'decision' => $decision,
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
                'decision_reason' => $reason,
                'decision_note' => $note,
                'decided_by' => $actor->id,
                'decided_at' => now(),
            ])->save();

            $locked = $locked->fresh(['user', 'decider']);

            $this->auditLogService->log(
                userId: $actor->id,
                event: WorkforceAuditEvent::ShortAttendanceReviewDecided->value,
                auditable: $locked,
                oldValues: [
                    'status' => ShortAttendanceReviewStatus::PendingReview->value,
                    'previous_status' => $previousStatus,
                ],
                newValues: [
                    'action' => 'decide',
                    'decision' => $decision->value,
                    'previous_status' => $previousStatus,
                    'new_status' => $newStatus,
                    'decision_reason' => $reason,
                    'decision_note' => $note,
                    'decided_by' => $actor->id,
                    'decided_at' => $locked->decided_at?->toIso8601String(),
                    'worked_minutes' => $locked->worked_minutes,
                    'legacy_event' => WorkforceAuditEvent::ShortAttendanceReviewDecided->legacyEvent(),
                ],
            );

            $this->workforceEventPublisher->publish(WorkforceEvent::make(
                type: WorkforceEventType::ShortAttendanceReviewDecided,
                userId: (int) $locked->user_id,
                workDate: $locked->work_date->copy()->startOfDay(),
                payload: [
                    'review_id' => $locked->id,
                    'decision' => $decision->value,
                    'previous_status' => $previousStatus,
                    'new_status' => $newStatus,
                    'decision_reason' => $reason,
                    'decision_note' => $note,
                    'decided_by' => $actor->id,
                    'worked_minutes' => $locked->worked_minutes,
                ],
            ));

            return $locked;
        });
    }

    private function discardPendingIfStale(WorkforceAttendanceDay $day): void
    {
        WorkforceShortAttendanceReview::query()
            ->where('user_id', $day->user_id)
            ->whereDate('work_date', $day->work_date->toDateString())
            ->where('status', ShortAttendanceReviewStatus::PendingReview)
            ->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function evidenceAttributes(User $user, WorkforceAttendanceDay $day): array
    {
        $user->loadMissing('workSchedule');
        $schedule = $this->workCalendarService->scheduleFor($user, $day->work_date);
        $sessions = WorkSession::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', $day->work_date->toDateString())
            ->where(function ($query): void {
                $query->where('is_attributable', true)->orWhereNull('is_attributable');
            })
            ->orderBy('login_at')
            ->get();

        $lastActivityAt = $sessions
            ->map(fn (WorkSession $session): ?Carbon => $session->last_activity_at)
            ->filter()
            ->sortByDesc(fn (Carbon $at): int => $at->getTimestamp())
            ->first();

        $workedMinutes = intdiv(max(0, (int) $day->active_duration_seconds), 60);
        $awayTimeoutCount = (int) $day->away_timeout_count;
        $shiftLabel = null;

        if ($schedule !== null) {
            $start = substr((string) $schedule->work_start_time, 0, 5);
            $end = substr((string) $schedule->work_end_time, 0, 5);
            $shiftLabel = "{$start} – {$end}";
        }

        return [
            'worked_minutes' => $workedMinutes,
            'first_login_at' => $day->first_login_at,
            'last_activity_at' => $lastActivityAt,
            'last_logout_at' => $day->last_logout_at,
            'session_count' => (int) $day->session_count,
            'away_timeout_count' => $awayTimeoutCount,
            'had_auto_logout' => $awayTimeoutCount > 0,
            'shift_label' => $shiftLabel,
            'department' => filled($user->department) ? (string) $user->department : null,
            'manager_name' => null,
            'calculated_reason' => $day->status_reason ?: 'short_attendance',
            'evidence_snapshot' => [
                'attendance_day_id' => $day->id,
                'active_duration_seconds' => (int) $day->active_duration_seconds,
                'session_duration_seconds' => (int) $day->session_duration_seconds,
                'manual_logout_count' => (int) $day->manual_logout_count,
                'on_time_login' => $day->on_time_login,
                'sessions' => $sessions->map(fn (WorkSession $session): array => [
                    'id' => $session->id,
                    'login_at' => $session->login_at?->toIso8601String(),
                    'logout_at' => $session->logout_at?->toIso8601String(),
                    'last_activity_at' => $session->last_activity_at?->toIso8601String(),
                    'ended_reason' => $session->ended_reason?->value,
                    'active_duration_seconds' => (int) $session->active_duration_seconds,
                ])->values()->all(),
            ],
        ];
    }
}

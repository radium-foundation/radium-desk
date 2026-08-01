<?php

namespace App\Services\Workforce\Recognition;

use App\Contracts\Workforce\WorkforceEventPublisher;
use App\Data\Workforce\Recognition\RecognitionCandidate;
use App\Data\Workforce\WorkforceEvent;
use App\Enums\RecognitionDayContext;
use App\Enums\RecognitionRecommendation;
use App\Enums\RecognitionReviewStatus;
use App\Enums\WorkforceAuditEvent;
use App\Enums\WorkforceEventType;
use App\Models\User;
use App\Models\WorkRecognitionReview;
use App\Services\AuditLogService;
use App\Services\Operations\OperationsRoleService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkRecognitionReviewService
{
    public function __construct(
        private readonly RecognitionCandidateDetector $candidateDetector,
        private readonly EvidenceSnapshotBuilder $evidenceSnapshotBuilder,
        private readonly RecognitionIraAdvisor $iraAdvisor,
        private readonly AuditLogService $auditLogService,
        private readonly WorkforceEventPublisher $workforceEventPublisher,
        private readonly OperationsRoleService $roleService,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('workforce_recognition.enabled', false);
    }

    public function ensurePendingFor(User $user, Carbon $date): ?WorkRecognitionReview
    {
        if (! $this->enabled()) {
            return null;
        }

        $candidate = $this->candidateDetector->detect($user, $date);

        if ($candidate === null) {
            return null;
        }

        $existing = WorkRecognitionReview::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', $candidate->workDate->toDateString())
            ->first();

        if ($existing !== null && $existing->status === RecognitionReviewStatus::Decided) {
            return $existing;
        }

        return $this->upsertFromCandidate($user, $candidate, $existing);
    }

    /**
     * Scan a calendar month for recognition candidates (same logic as artisan / queued job).
     */
    public function scanMonth(Carbon $month): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $users = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', $this->roleService->attendanceTrackedRoleSlugs()))
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user): bool => $this->roleService->isAttendanceTracked($user));

        $touched = 0;

        foreach ($users as $user) {
            $cursor = $start->copy();
            while ($cursor->lte($end)) {
                if ($this->ensurePendingFor($user, $cursor) !== null) {
                    $touched++;
                }
                $cursor->addDay();
            }
        }

        return $touched;
    }

    public function refreshPending(WorkRecognitionReview $review): WorkRecognitionReview
    {
        if ($review->status !== RecognitionReviewStatus::PendingReview) {
            throw ValidationException::withMessages([
                'status' => 'Only pending reviews can be refreshed.',
            ]);
        }

        $user = $review->user;
        if ($user === null) {
            throw ValidationException::withMessages([
                'user' => 'Review has no employee.',
            ]);
        }

        $candidate = $this->candidateDetector->detect($user, $review->work_date);
        if ($candidate === null) {
            throw ValidationException::withMessages([
                'work_date' => 'Date is no longer a Weekly Off / Holiday with activity.',
            ]);
        }

        return $this->upsertFromCandidate($user, $candidate, $review);
    }

    public function decide(
        WorkRecognitionReview $review,
        User $actor,
        RecognitionRecommendation $decision,
        ?string $reason = null,
    ): WorkRecognitionReview {
        $reason = $reason !== null ? trim($reason) : null;
        $reason = $reason === '' ? null : $reason;

        return DB::transaction(function () use ($review, $actor, $decision, $reason): WorkRecognitionReview {
            /** @var WorkRecognitionReview|null $locked */
            $locked = WorkRecognitionReview::query()
                ->whereKey($review->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw ValidationException::withMessages([
                    'review' => 'Recognition review was not found.',
                ]);
            }

            if ($locked->status === RecognitionReviewStatus::Decided) {
                return $locked->fresh(['user', 'decider']) ?? $locked;
            }

            if ($decision !== $locked->ira_recommendation && ($reason === null || $reason === '')) {
                throw ValidationException::withMessages([
                    'decision_reason' => 'A reason is required when overriding the IRA recommendation.',
                ]);
            }

            $locked->fill([
                'status' => RecognitionReviewStatus::Decided,
                'decision' => $decision,
                'decision_reason' => $reason,
                'decided_by' => $actor->id,
                'decided_at' => now(),
            ])->save();

            $locked = $locked->fresh(['user', 'decider']);

            $this->auditLogService->log(
                userId: $actor->id,
                event: WorkforceAuditEvent::RecognitionDecided->value,
                auditable: $locked,
                oldValues: [
                    'ira_recommendation' => $locked->ira_recommendation->value,
                ],
                newValues: [
                    'action' => 'decide',
                    'decision' => $decision->value,
                    'decision_reason' => $reason,
                    'overridden' => $decision !== $locked->ira_recommendation,
                    'legacy_event' => WorkforceAuditEvent::RecognitionDecided->legacyEvent(),
                ],
            );

            $this->workforceEventPublisher->publish(WorkforceEvent::make(
                type: WorkforceEventType::RecognitionDecided,
                userId: (int) $locked->user_id,
                workDate: $locked->work_date->copy()->startOfDay(),
                payload: [
                    'review_id' => $locked->id,
                    'decision' => $decision->value,
                    'ira_recommendation' => $locked->ira_recommendation->value,
                    'decided_by' => $actor->id,
                ],
            ));

            return $locked;
        });
    }

    private function upsertFromCandidate(
        User $user,
        RecognitionCandidate $candidate,
        ?WorkRecognitionReview $existing,
    ): WorkRecognitionReview {
        $snapshot = $this->evidenceSnapshotBuilder->build($user, $candidate->workDate);
        $advice = $this->iraAdvisor->advise($user, $snapshot);

        $attributes = [
            'user_id' => $user->id,
            'work_date' => $candidate->workDate->toDateString(),
            'day_context' => $candidate->dayContext,
            'status' => RecognitionReviewStatus::PendingReview,
            'login_seconds' => $snapshot['login_seconds'] ?? null,
            'productive_seconds' => $snapshot['productive_seconds'] ?? null,
            'evidence_snapshot' => $snapshot,
            'ira_score' => $advice->score,
            'ira_recommendation' => $advice->recommendation,
            'ira_rationale' => $advice->rationale,
            'department_pack' => $advice->departmentPack,
            'source_version' => (int) config('workforce_recognition.snapshot_version', 1),
            'decision' => null,
            'decision_reason' => null,
            'decided_by' => null,
            'decided_at' => null,
        ];

        $created = false;
        $shouldPublishRecommended = $existing === null
            || $this->recommendationMateriallyChanged($existing, $advice->recommendation, $advice->score, $advice->rationale);

        $review = DB::transaction(function () use ($existing, $attributes, &$created): WorkRecognitionReview {
            if ($existing !== null) {
                $existing->fill($attributes)->save();

                return $existing->fresh(['user']);
            }

            $created = true;

            return WorkRecognitionReview::query()->create($attributes)->fresh(['user']);
        });

        if ($created) {
            $this->auditLogService->log(
                userId: null,
                event: WorkforceAuditEvent::RecognitionCreated->value,
                auditable: $review,
                newValues: [
                    'action' => 'create',
                    'day_context' => $review->day_context->value,
                    'ira_recommendation' => $review->ira_recommendation->value,
                    'ira_score' => $review->ira_score,
                    'legacy_event' => WorkforceAuditEvent::RecognitionCreated->legacyEvent(),
                ],
            );

            $eventType = $review->day_context === RecognitionDayContext::CompanyHoliday
                ? WorkforceEventType::HolidayWorked
                : WorkforceEventType::WeeklyOffWorked;

            $this->workforceEventPublisher->publish(WorkforceEvent::make(
                type: $eventType,
                userId: (int) $review->user_id,
                workDate: $review->work_date->copy()->startOfDay(),
                payload: [
                    'review_id' => $review->id,
                    'day_context' => $review->day_context->value,
                ],
            ));
        }

        if ($shouldPublishRecommended) {
            $this->workforceEventPublisher->publish(WorkforceEvent::make(
                type: WorkforceEventType::RecognitionRecommended,
                userId: (int) $review->user_id,
                workDate: $review->work_date->copy()->startOfDay(),
                payload: [
                    'review_id' => $review->id,
                    'recommendation' => $review->ira_recommendation->value,
                    'score' => (float) $review->ira_score,
                    'department_pack' => $review->department_pack,
                ],
            ));
        }

        return $review;
    }

    private function recommendationMateriallyChanged(
        WorkRecognitionReview $existing,
        RecognitionRecommendation $recommendation,
        float $score,
        string $rationale,
    ): bool {
        if ($existing->ira_recommendation !== $recommendation) {
            return true;
        }

        if (round((float) $existing->ira_score, 2) !== round($score, 2)) {
            return true;
        }

        return trim((string) $existing->ira_rationale) !== trim($rationale);
    }
}

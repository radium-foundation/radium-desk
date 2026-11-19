<?php

namespace App\Services\Operations\LeavePolicy;

use App\Data\Operations\LeavePolicyEvaluation;
use App\Enums\LeaveDuration;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class LeavePolicyOrchestrator
{
    public function __construct(
        private readonly LeaveSundaySandwichResolver $sandwichResolver,
        private readonly LeaveCoverageGuard $coverageGuard,
        private readonly LeaveManualExceptionGuard $manualExceptionGuard,
        private readonly LeaveAutoApprovalEvaluator $autoApprovalEvaluator,
    ) {}

    public function evaluateSubmission(
        User $requester,
        Carbon $requestedStart,
        Carbon $requestedEnd,
        LeaveDuration $duration,
        bool $manualException,
        ?Carbon $submittedAt = null,
        ?int $excludeLeaveRequestId = null,
    ): LeavePolicyEvaluation {
        $submittedAt ??= now();

        $this->assertEmployeeDurationAllowed($duration);

        if ($manualException) {
            $this->manualExceptionGuard->assertManualExceptionAllowedOnSubmit($requester, $requestedStart);
        }

        $sandwich = $this->sandwichResolver->resolve(
            requestedStart: $requestedStart,
            requestedEnd: $requestedEnd,
            submittedAt: $submittedAt,
            user: $requester,
            excludeLeaveRequestId: $excludeLeaveRequestId,
        );

        /** @var Carbon $effectiveStart */
        $effectiveStart = $sandwich['effective_start'];
        /** @var Carbon $effectiveEnd */
        $effectiveEnd = $sandwich['effective_end'];

        $requiresManualReview = $manualException
            || $sandwich['applied']
            || $this->touchesPolicyManualDay($requestedStart, $requestedEnd)
            || ! $this->coverageGuard->configurationStatus()['triad_configured'];

        $coverageBlock = null;
        try {
            $this->coverageGuard->assertCoverageAllows(
                $requester,
                $effectiveStart,
                $effectiveEnd,
                excludeLeaveRequestIds: $excludeLeaveRequestId !== null ? [$excludeLeaveRequestId] : [],
            );
        } catch (ValidationException $exception) {
            if (! $manualException) {
                throw $exception;
            }
            $requiresManualReview = true;
            $messages = $exception->errors();
            $firstField = reset($messages);
            $coverageBlock = is_array($firstField) && $firstField !== []
                ? (string) reset($firstField)
                : (string) $exception->getMessage();
        }

        $metadata = array_merge($sandwich['metadata'], [
            'manual_exception' => $manualException,
            'coverage_configuration' => $this->coverageGuard->configurationStatus(),
            'coverage_conflict' => $coverageBlock,
            'awaiting_coverage_exception_review' => $manualException && $coverageBlock !== null,
        ]);

        return new LeavePolicyEvaluation(
            requestedStart: $requestedStart,
            requestedEnd: $requestedEnd,
            effectiveStart: $effectiveStart,
            effectiveEnd: $effectiveEnd,
            noticeCalendarDays: $sandwich['notice_days'],
            sundaySandwichApplied: $sandwich['applied'],
            sandwichSundayDates: $sandwich['dates'],
            requiresManualReview: $requiresManualReview,
            blocksAutoApproval: $requiresManualReview || $manualException,
            blockReason: $coverageBlock,
            metadata: $metadata,
        );
    }

    public function assertEmployeeDurationAllowed(LeaveDuration $duration, bool $halfDayException = false): void
    {
        if ($duration === LeaveDuration::FullDay) {
            return;
        }

        if ($halfDayException) {
            return;
        }

        throw ValidationException::withMessages([
            'duration' => 'Half-day leave is only available through Management/HR as an approved exception.',
        ]);
    }

    public function assertHrHalfDayAllowed(User $actor, LeaveDuration $duration, bool $halfDayException): void
    {
        if ($duration !== LeaveDuration::HalfDay) {
            return;
        }

        if (! $actor->can('leave-requests.manage')) {
            throw ValidationException::withMessages([
                'duration' => 'Half-day leave requires Management permission.',
            ]);
        }

        if (! $halfDayException) {
            throw ValidationException::withMessages([
                'duration' => 'Mark half-day as an approved Management exception.',
            ]);
        }
    }

    private function touchesPolicyManualDay(Carbon $start, Carbon $end): bool
    {
        $cursor = $start->copy()->startOfDay();
        while ($cursor->lte($end)) {
            $dow = (int) $cursor->dayOfWeek;
            if (in_array($dow, [Carbon::MONDAY, Carbon::SATURDAY, Carbon::SUNDAY], true)) {
                return true;
            }
            $cursor->addDay();
        }

        return false;
    }

    public function autoApprovalSnapshot(LeavePolicyEvaluation $policy, User $requester, LeaveDuration $duration, bool $manualException): array
    {
        $structural = $this->autoApprovalEvaluator->evaluatePhaseStructure(
            $requester,
            $policy,
            $duration,
            $manualException,
        );

        return [
            'enabled' => $this->autoApprovalEvaluator->isEnabled(),
            'disabled_reason' => $this->autoApprovalEvaluator->blockReasonWhenDisabled(),
            'structural' => $structural,
            'evaluated_at' => now()->toIso8601String(),
        ];
    }
}

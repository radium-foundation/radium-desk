<?php

namespace App\Services\Operations\LeavePolicy;

use App\Enums\LeaveRequestStatus;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class LeaveManualExceptionGuard
{
    public function maxApprovedPerMonth(): int
    {
        return max(0, (int) config('leave_policy.manual_exception.max_approved_per_month', 2));
    }

    /**
     * Count approved manual exceptions in the calendar month of the leave start date.
     * Historical rows without is_manual_exception are not counted (VERIFIED limitation).
     *
     * @param  list<int>  $excludeLeaveRequestIds
     */
    public function approvedManualExceptionCountForMonth(
        User $user,
        Carbon $monthAnchor,
        array $excludeLeaveRequestIds = [],
    ): int {
        $monthStart = $monthAnchor->copy()->startOfMonth();
        $monthEnd = $monthAnchor->copy()->endOfMonth();

        return (int) LeaveRequest::query()
            ->where('user_id', $user->id)
            ->where('status', LeaveRequestStatus::Approved)
            ->where('is_manual_exception', true)
            ->where('authorized_override', false)
            ->when($excludeLeaveRequestIds !== [], fn ($q) => $q->whereNotIn('id', $excludeLeaveRequestIds))
            ->whereDate('start_date', '>=', $monthStart->toDateString())
            ->whereDate('start_date', '<=', $monthEnd->toDateString())
            ->count();
    }

    /**
     * Block new manual exception requests when cap reached (approve-time check for pending).
     */
    public function assertManualExceptionAllowedOnSubmit(User $user, Carbon $startDate): void
    {
        $count = $this->approvedManualExceptionCountForMonth($user, $startDate);
        if ($count >= $this->maxApprovedPerMonth()) {
            throw ValidationException::withMessages([
                'manual_exception' => 'You have reached the limit of '.$this->maxApprovedPerMonth()
                    .' approved manual leave exceptions for '.$startDate->format('F Y')
                    .'. Contact Management for an authorized override.',
            ]);
        }
    }

    public function assertManualExceptionAllowedOnApprove(
        LeaveRequest $leaveRequest,
        bool $authorizedOverride,
    ): void {
        if ($authorizedOverride || ! $leaveRequest->is_manual_exception) {
            return;
        }

        $requester = $leaveRequest->user;
        if ($requester === null) {
            return;
        }

        $count = $this->approvedManualExceptionCountForMonth(
            $requester,
            $leaveRequest->start_date->copy()->startOfDay(),
            excludeLeaveRequestIds: [$leaveRequest->id],
        );

        if ($count >= $this->maxApprovedPerMonth()) {
            throw ValidationException::withMessages([
                'status' => 'This employee already has '.$this->maxApprovedPerMonth()
                    .' approved manual exceptions this month. Use an authorized override with mandatory reason.',
            ]);
        }
    }
}

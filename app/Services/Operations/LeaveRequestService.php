<?php

namespace App\Services\Operations;

use App\Enums\LeaveRequestStatus;
use App\Models\LeaveRequest;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class LeaveRequestService
{
    /**
     * @param  array{start_date: string, end_date: string, reason: string}  $data
     */
    public function submit(User $requester, array $data): LeaveRequest
    {
        return LeaveRequest::query()->create([
            'user_id' => $requester->id,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'reason' => $data['reason'],
            'status' => LeaveRequestStatus::Pending,
        ]);
    }

    public function approve(LeaveRequest $leaveRequest, User $reviewer, ?string $reviewNotes = null): LeaveRequest
    {
        $this->assertCanReview($reviewer, $leaveRequest);

        if ($leaveRequest->status !== LeaveRequestStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => 'Only pending leave requests can be approved.',
            ]);
        }

        $leaveRequest->fill([
            'status' => LeaveRequestStatus::Approved,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => $reviewNotes,
        ])->save();

        return $leaveRequest->fresh(['user', 'reviewer']);
    }

    public function reject(LeaveRequest $leaveRequest, User $reviewer, ?string $reviewNotes = null): LeaveRequest
    {
        $this->assertCanReview($reviewer, $leaveRequest);

        if ($leaveRequest->status !== LeaveRequestStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => 'Only pending leave requests can be rejected.',
            ]);
        }

        $leaveRequest->fill([
            'status' => LeaveRequestStatus::Rejected,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => $reviewNotes,
        ])->save();

        return $leaveRequest->fresh(['user', 'reviewer']);
    }

    public function canReview(User $reviewer, LeaveRequest $leaveRequest): bool
    {
        if (! $reviewer->can('leave-requests.review')) {
            return false;
        }

        $requester = $leaveRequest->user;

        if ($requester === null) {
            return false;
        }

        if ($requester->hasAnyRole([
            RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
            RolePermissionSeeder::ROLE_ADMIN,
        ])) {
            return $reviewer->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN);
        }

        if ($requester->hasAnyRole([
            RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST,
            RolePermissionSeeder::ROLE_CUSTOMER_COORDINATOR,
            RolePermissionSeeder::ROLE_HARDWARE_TEAM,
            RolePermissionSeeder::ROLE_AGENT,
        ])) {
            return $reviewer->hasAnyRole([
                RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
                RolePermissionSeeder::ROLE_ADMIN,
                RolePermissionSeeder::ROLE_SUPERADMIN,
            ]);
        }

        return false;
    }

    public function assertCanReview(User $reviewer, LeaveRequest $leaveRequest): void
    {
        if (! $this->canReview($reviewer, $leaveRequest)) {
            throw ValidationException::withMessages([
                'reviewer' => 'You are not allowed to review this leave request.',
            ]);
        }
    }

    public function coversDate(LeaveRequest $leaveRequest, Carbon $date): bool
    {
        if ($leaveRequest->status !== LeaveRequestStatus::Approved) {
            return false;
        }

        $day = $date->copy()->startOfDay();

        return $day->gte($leaveRequest->start_date->startOfDay())
            && $day->lte($leaveRequest->end_date->endOfDay());
    }
}

<?php

namespace App\Services\Platform\Cards;

use App\Contracts\Platform\PlatformCardProvider;
use App\Data\Platform\PlatformCardDefinition;
use App\Data\Platform\PlatformCardPayload;
use App\Enums\PlatformCardSize;
use App\Enums\PlatformDashboardSection;
use App\Enums\PlatformHealthStatus;
use App\Models\User;
use App\Services\Operations\LeaveRequestService;
use App\Services\Platform\Concerns\InteractsWithPlatformCardDefinition;
use Illuminate\Support\Facades\Route;

class PendingLeaveApprovalsCardProvider implements PlatformCardProvider
{
    use InteractsWithPlatformCardDefinition {
        authorize as traitAuthorize;
    }

    public function __construct(
        private readonly LeaveRequestService $leaveRequestService,
    ) {}

    public function definition(): PlatformCardDefinition
    {
        $indexUrl = Route::has('leave-requests.index')
            ? route('leave-requests.index')
            : null;

        return new PlatformCardDefinition(
            id: 'workforce_pending_leave_approvals',
            title: 'Pending Leave Approvals',
            section: PlatformDashboardSection::Workforce->value,
            priority: 5,
            icon: 'bi-calendar-check',
            refreshable: true,
            expandable: false,
            permission: 'leave-requests.review',
            size: PlatformCardSize::Large,
            subtitle: 'Action-first leave review',
            bodyPartial: 'admin.platform.cards.pending-leave-approvals',
            detailUrl: $indexUrl,
            actions: $indexUrl === null ? [] : [
                [
                    'label' => 'Open Leave',
                    'url' => $indexUrl,
                ],
            ],
            estimatedRefreshCost: 'cheap',
        );
    }

    public function authorize(User $viewer): bool
    {
        if (! $this->traitAuthorize($viewer)) {
            return false;
        }

        if (! $this->leaveRequestService->isDesignatedApprover($viewer)) {
            return false;
        }

        return $this->leaveRequestService->pendingApprovals()->isNotEmpty();
    }

    public function load(User $viewer): PlatformCardPayload
    {
        $definition = $this->definition();
        $items = $this->leaveRequestService->pendingApprovals()
            ->map(fn ($leaveRequest): array => [
                'id' => $leaveRequest->id,
                'employee' => $leaveRequest->user?->firstName()
                    ?: ($leaveRequest->user?->name ?? 'Team member'),
                'dates_label' => $this->leaveRequestService->leaveDatesLabel($leaveRequest),
                'age_label' => $this->leaveRequestService->pendingAgeLabel($leaveRequest),
                'review_url' => route('leave-requests.show', $leaveRequest),
            ])
            ->values()
            ->all();

        return PlatformCardPayload::fromDefinition(
            definition: $definition,
            status: $items === [] ? PlatformHealthStatus::Disabled : PlatformHealthStatus::Warning,
            generatedAt: now(),
            meta: [
                'items' => $items,
                'count' => count($items),
            ],
            detailUrl: $definition->detailUrl,
        );
    }
}

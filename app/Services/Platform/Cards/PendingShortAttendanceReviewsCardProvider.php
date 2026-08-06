<?php

namespace App\Services\Platform\Cards;

use App\Contracts\Platform\PlatformCardProvider;
use App\Data\Platform\PlatformCardDefinition;
use App\Data\Platform\PlatformCardPayload;
use App\Enums\PlatformCardSize;
use App\Enums\PlatformDashboardSection;
use App\Enums\PlatformHealthStatus;
use App\Models\User;
use App\Services\Platform\Concerns\InteractsWithPlatformCardDefinition;
use App\Services\Workforce\ShortAttendance\ShortAttendanceReviewQueryService;
use App\Services\Workforce\ShortAttendance\ShortAttendanceReviewService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Route;

class PendingShortAttendanceReviewsCardProvider implements PlatformCardProvider
{
    use InteractsWithPlatformCardDefinition {
        authorize as traitAuthorize;
    }

    public function __construct(
        private readonly ShortAttendanceReviewService $reviewService,
    ) {}

    public function definition(): PlatformCardDefinition
    {
        $indexUrl = Route::has('workforce-management.short-attendance.index')
            ? route('workforce-management.short-attendance.index', [
                'period' => ShortAttendanceReviewQueryService::PERIOD_TODAY,
                'status' => 'pending',
            ])
            : null;

        return new PlatformCardDefinition(
            id: 'workforce_pending_short_attendance_reviews',
            title: "Today's Short Attendance Review",
            section: PlatformDashboardSection::Workforce->value,
            priority: 6,
            icon: 'bi-clock-history',
            refreshable: true,
            expandable: false,
            permission: RolePermissionSeeder::PERMISSION_SHORT_ATTENDANCE_VIEW,
            size: PlatformCardSize::Small,
            subtitle: 'Daily SA pending queue',
            bodyPartial: 'admin.platform.cards.pending-short-attendance-reviews',
            detailUrl: $indexUrl,
            actions: $indexUrl === null ? [] : [
                [
                    'label' => 'Open Today\'s Queue',
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

        // Always visible to authorized HR (green when zero pending).
        return $this->reviewService->canView($viewer);
    }

    public function load(User $viewer): PlatformCardPayload
    {
        $definition = $this->definition();
        $counts = $this->reviewService->dashboardPendingCounts();
        $total = $counts['total'];

        $status = match (true) {
            $total === 0 => PlatformHealthStatus::Healthy,
            $total <= 5 => PlatformHealthStatus::Warning,
            default => PlatformHealthStatus::Critical,
        };

        return PlatformCardPayload::fromDefinition(
            definition: $definition,
            status: $status,
            generatedAt: now(),
            meta: [
                'pending_today' => $counts['today'],
                'pending_yesterday' => $counts['yesterday'],
                'pending_total' => $total,
            ],
            detailUrl: $definition->detailUrl,
        );
    }
}

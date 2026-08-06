<?php

namespace App\Services\IncomingEmail;

use App\Enums\IncomingEmailAutomaticSubcategory;
use App\Enums\IncomingEmailClassification;
use App\Enums\IncomingEmailAttentionCategory;
use App\Enums\IncomingEmailIntakeQueue;
use App\Enums\IncomingEmailMessageStatus;
use App\Models\IncomingEmailIgnoreStat;
use App\Models\IncomingEmailMessage;
use App\Models\User;
use App\Support\IncomingEmail\IncomingEmailAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class IncomingEmailIntakeCounterService
{
    public const DASHBOARD_WIDGET_CACHE_KEY_PREFIX = 'incoming_email:dashboard_widget:';

    public function __construct(
        private readonly IncomingEmailAttentionCategoryService $attentionCategoryService,
    ) {}

    /**
     * @return array{
     *     title: string,
     *     subtitle: string,
     *     needs_attention: int,
     *     severity: string,
     *     url: string,
     *     hover: array{
     *         needs_attention: list<array{label: string, count: int}>,
     *         ignored: list<array{label: string, count: int}>
     *     }
     * }|null
     */
    public function dashboardWidget(?User $user = null, ?Carbon $statDate = null): ?array
    {
        $user ??= auth()->user();

        if (! $this->canView($user)) {
            return null;
        }

        $statDate ??= now();

        return Cache::remember(
            $this->dashboardWidgetCacheKey($statDate),
            (int) config('inbound_email.dashboard_widget_cache_seconds', 45),
            fn (): array => $this->buildDashboardWidget($statDate),
        );
    }

    public function forgetDashboardWidgetCache(?Carbon $statDate = null): void
    {
        Cache::forget($this->dashboardWidgetCacheKey($statDate ?? now()));

        // Keep Platform Email Operations in sync with intake counter invalidation.
        try {
            app(\App\Services\Platform\PlatformCacheInvalidator::class)
                ->invalidateZone('email_operations');
        } catch (\Throwable) {
            Cache::forget(\App\Services\Platform\PlatformCachePolicy::KEY_EMAIL_OPERATIONS_OVERVIEW);
        }
    }

    public function severityForCount(int $count): string
    {
        if ($count <= 0) {
            return 'normal';
        }

        if ($count <= 5) {
            return 'blue';
        }

        if ($count <= 15) {
            return 'amber';
        }

        return 'red';
    }

    /**
     * @return array<string, int>
     */
    public function counts(?Carbon $statDate = null): array
    {
        if (! config('inbound_email.enabled')) {
            return $this->emptyCounts();
        }

        $statDate ??= now();

        return [
            IncomingEmailIntakeQueue::NeedsHuman->value => $this->needsHumanCount(),
            IncomingEmailIntakeQueue::ReviewSuggested->value => $this->reviewSuggestedCount(),
            IncomingEmailIntakeQueue::Promotional->value => $this->ignoredStatCount(
                IncomingEmailIntakeQueue::Promotional,
                $statDate,
            ),
            IncomingEmailIntakeQueue::Spam->value => $this->ignoredStatCount(
                IncomingEmailIntakeQueue::Spam,
                $statDate,
            ),
            IncomingEmailIntakeQueue::Automatic->value => $this->ignoredStatCount(
                IncomingEmailIntakeQueue::Automatic,
                $statDate,
            ),
        ];
    }

    /**
     * Live breakdown under Completed Automatically (presentation only).
     *
     * @return list<array{key: string, label: string, count: int, tooltip: string, url: string, active: bool}>
     */
    public function automaticSubcategoryBreakdown(?IncomingEmailAutomaticSubcategory $active = null): array
    {
        $counts = $this->automaticSubcategoryCounts();
        $items = [];

        foreach (IncomingEmailAutomaticSubcategory::cases() as $subcategory) {
            $items[] = [
                'key' => $subcategory->value,
                'label' => $subcategory->label(),
                'count' => $counts[$subcategory->value] ?? 0,
                'tooltip' => $subcategory->tooltip(),
                'url' => route('admin.incoming-emails.index', [
                    'queue' => IncomingEmailIntakeQueue::Automatic->value,
                    'sub' => $subcategory->value,
                ]),
                'active' => $active === $subcategory,
            ];
        }

        return $items;
    }

    /**
     * @return array<string, int>
     */
    public function automaticSubcategoryCounts(): array
    {
        if (! config('inbound_email.enabled')) {
            return $this->emptyAutomaticSubcategoryCounts();
        }

        $counts = $this->emptyAutomaticSubcategoryCounts();

        $reasonCounts = $this->queryForQueue(IncomingEmailIntakeQueue::Automatic)
            ->reorder()
            ->select('ignore_reason', DB::raw('count(*) as aggregate'))
            ->groupBy('ignore_reason')
            ->pluck('aggregate', 'ignore_reason');

        foreach (IncomingEmailAutomaticSubcategory::cases() as $subcategory) {
            $reason = $subcategory->ignoreReason();
            if ($reason === null) {
                continue;
            }

            $counts[$subcategory->value] = (int) ($reasonCounts[$reason] ?? 0);
        }

        // Own outbound may be stored as classification without ignore_reason.
        $ownOutboundExtra = $this->queryForQueue(IncomingEmailIntakeQueue::Automatic)
            ->reorder()
            ->where(function (Builder $builder): void {
                $builder->where('classification', IncomingEmailClassification::OwnOutbound->value)
                    ->where(function (Builder $nested): void {
                        $nested->whereNull('ignore_reason')
                            ->orWhere('ignore_reason', '!=', 'own_outbound');
                    });
            })
            ->count();

        $counts[IncomingEmailAutomaticSubcategory::OwnOutbound->value] += $ownOutboundExtra;
        $counts[IncomingEmailAutomaticSubcategory::DuplicateNotifications->value] = $this->duplicateNotificationCount();

        return $counts;
    }

    /**
     * @return list<array{
     *     queue: string,
     *     label: string,
     *     emoji: string,
     *     count: int,
     *     tooltip: string,
     *     url: string,
     *     uses_superscript: bool
     * }>
     */
    public function visibleCounters(?User $user = null, ?Carbon $statDate = null): array
    {
        $user ??= auth()->user();

        if (! $this->canView($user)) {
            return [];
        }

        $counters = [];

        foreach (IncomingEmailIntakeQueue::cases() as $queue) {
            $count = $this->counts($statDate)[$queue->value] ?? 0;

            if ($count <= 0) {
                continue;
            }

            $counters[] = [
                'queue' => $queue->value,
                'label' => $queue->label(),
                'emoji' => $queue->emoji(),
                'count' => $count,
                'tooltip' => $queue->tooltip(),
                'url' => route('admin.incoming-emails.index', ['queue' => $queue->value]),
                'uses_superscript' => $queue->usesSuperscriptCount(),
            ];
        }

        return $counters;
    }

    public function canView(?User $user): bool
    {
        return IncomingEmailAccess::allowsView($user);
    }

    public function needsHumanCount(): int
    {
        return IncomingEmailMessage::query()
            ->whereIn('status', [
                IncomingEmailMessageStatus::NeedsReview,
                IncomingEmailMessageStatus::Failed,
            ])
            ->count();
    }

    /**
     * Presentation-only: IRA recorded low confidence or processing failed.
     * Does not remove these rows from Needs Human and does not change routing.
     */
    public function reviewSuggestedCount(): int
    {
        return $this->queryForQueue(IncomingEmailIntakeQueue::ReviewSuggested)->count();
    }

    /**
     * @return Builder<IncomingEmailMessage>
     */
    public function queryForQueue(
        IncomingEmailIntakeQueue $queue,
        ?IncomingEmailAutomaticSubcategory $subcategory = null,
    ): Builder {
        $query = IncomingEmailMessage::query()->orderByDesc('received_at')->orderByDesc('id');

        if ($queue === IncomingEmailIntakeQueue::NeedsHuman) {
            return $query->whereIn('status', $queue->humanActionStatuses());
        }

        if ($queue === IncomingEmailIntakeQueue::ReviewSuggested) {
            return $query
                ->whereIn('status', $queue->humanActionStatuses())
                ->where(function (Builder $builder): void {
                    $builder->where('status', IncomingEmailMessageStatus::Failed)
                        ->orWhere(function (Builder $nested): void {
                            $nested->whereNotNull('ira_confidence')
                                ->where('ira_confidence', '<', 45);
                        });
                });
        }

        $query
            ->where('status', IncomingEmailMessageStatus::Ignored)
            ->where(function (Builder $builder) use ($queue): void {
                $reasons = $queue->ignoreReasons();
                $classifications = array_map(
                    static fn (IncomingEmailClassification $case): string => $case->value,
                    $queue->ignoredClassifications(),
                );

                $builder->where(function (Builder $nested) use ($reasons, $classifications): void {
                    $added = false;

                    if ($reasons !== []) {
                        $nested->whereIn('ignore_reason', $reasons);
                        $added = true;
                    }

                    if ($classifications !== []) {
                        if ($added) {
                            $nested->orWhereIn('classification', $classifications);
                        } else {
                            $nested->whereIn('classification', $classifications);
                        }
                    }
                });
            });

        if ($queue === IncomingEmailIntakeQueue::Automatic && $subcategory !== null) {
            $this->applyAutomaticSubcategoryFilter($query, $subcategory);
        }

        return $query;
    }

    /**
     * @param  Builder<IncomingEmailMessage>  $query
     */
    private function applyAutomaticSubcategoryFilter(
        Builder $query,
        IncomingEmailAutomaticSubcategory $subcategory,
    ): void {
        if ($subcategory === IncomingEmailAutomaticSubcategory::DuplicateNotifications) {
            $duplicateSubjects = $this->duplicateNotificationSubjectsQuery();

            $query->whereIn('subject', $duplicateSubjects);

            return;
        }

        $reason = $subcategory->ignoreReason();

        if ($reason === null) {
            return;
        }

        if ($subcategory === IncomingEmailAutomaticSubcategory::OwnOutbound) {
            $query->where(function (Builder $builder) use ($reason): void {
                $builder->where('ignore_reason', $reason)
                    ->orWhere('classification', IncomingEmailClassification::OwnOutbound->value);
            });

            return;
        }

        $query->where('ignore_reason', $reason);
    }

    private function duplicateNotificationCount(): int
    {
        return $this->queryForQueue(
            IncomingEmailIntakeQueue::Automatic,
            IncomingEmailAutomaticSubcategory::DuplicateNotifications,
        )->count();
    }

    /**
     * Subjects that appear more than once in the Completed Automatically population.
     *
     * @return Builder<IncomingEmailMessage>
     */
    private function duplicateNotificationSubjectsQuery(): Builder
    {
        return $this->queryForQueue(IncomingEmailIntakeQueue::Automatic)
            ->reorder()
            ->select('subject')
            ->whereNotNull('subject')
            ->where('subject', '!=', '')
            ->groupBy('subject')
            ->havingRaw('count(*) > 1');
    }

    /**
     * @return array{
     *     title: string,
     *     subtitle: string,
     *     needs_attention: int,
     *     severity: string,
     *     url: string,
     *     hover: array{
     *         needs_attention: list<array{label: string, count: int}>,
     *         ignored: list<array{label: string, count: int}>
     *     }
     * }
     */
    private function buildDashboardWidget(Carbon $statDate): array
    {
        $attention = $this->attentionCategoryService->aggregateCounts();
        $ignoredCounts = $this->counts($statDate);

        $needsAttention = $attention['sales'] + $attention['orders'] + $attention['priority'];

        return [
            'title' => 'Email Intake',
            'subtitle' => 'Needs Attention',
            'needs_attention' => $needsAttention,
            'severity' => $this->severityForCount($needsAttention),
            'url' => route('admin.incoming-emails.index', ['queue' => IncomingEmailIntakeQueue::NeedsHuman->value]),
            'hover' => [
                'needs_attention' => [
                    ['label' => IncomingEmailAttentionCategory::Sales->label(), 'count' => $attention['sales']],
                    ['label' => IncomingEmailAttentionCategory::Orders->label(), 'count' => $attention['orders']],
                    ['label' => IncomingEmailAttentionCategory::Priority->label(), 'count' => $attention['priority']],
                ],
                'ignored' => [
                    ['label' => 'Promotions', 'count' => $ignoredCounts[IncomingEmailIntakeQueue::Promotional->value] ?? 0],
                    ['label' => 'Spam', 'count' => $ignoredCounts[IncomingEmailIntakeQueue::Spam->value] ?? 0],
                    [
                        'label' => IncomingEmailIntakeQueue::Automatic->label(),
                        'count' => $ignoredCounts[IncomingEmailIntakeQueue::Automatic->value] ?? 0,
                    ],
                ],
            ],
        ];
    }

    private function dashboardWidgetCacheKey(Carbon $statDate): string
    {
        return self::DASHBOARD_WIDGET_CACHE_KEY_PREFIX.$statDate->toDateString();
    }

    private function ignoredStatCount(IncomingEmailIntakeQueue $queue, Carbon $statDate): int
    {
        $reasons = $queue->ignoreReasons();

        if ($reasons === []) {
            return 0;
        }

        return (int) IncomingEmailIgnoreStat::query()
            ->whereDate('stat_date', $statDate->toDateString())
            ->whereIn('reason', $reasons)
            ->sum('count');
    }

    /**
     * @return array<string, int>
     */
    private function emptyCounts(): array
    {
        return [
            IncomingEmailIntakeQueue::NeedsHuman->value => 0,
            IncomingEmailIntakeQueue::ReviewSuggested->value => 0,
            IncomingEmailIntakeQueue::Promotional->value => 0,
            IncomingEmailIntakeQueue::Spam->value => 0,
            IncomingEmailIntakeQueue::Automatic->value => 0,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function emptyAutomaticSubcategoryCounts(): array
    {
        $counts = [];

        foreach (IncomingEmailAutomaticSubcategory::cases() as $subcategory) {
            $counts[$subcategory->value] = 0;
        }

        return $counts;
    }
}

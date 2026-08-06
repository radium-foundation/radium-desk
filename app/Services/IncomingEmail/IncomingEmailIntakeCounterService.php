<?php

namespace App\Services\IncomingEmail;

use App\Enums\IncomingEmailClassification;
use App\Enums\IncomingEmailAttentionCategory;
use App\Enums\IncomingEmailIntakeQueue;
use App\Enums\IncomingEmailMessageStatus;
use App\Models\IncomingEmailIgnoreStat;
use App\Models\IncomingEmailMessage;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

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
        if (! config('inbound_email.enabled')) {
            return false;
        }

        return $user !== null && $user->can('update', SystemSetting::class);
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
     * @return Builder<IncomingEmailMessage>
     */
    public function queryForQueue(IncomingEmailIntakeQueue $queue): Builder
    {
        $query = IncomingEmailMessage::query()->orderByDesc('received_at')->orderByDesc('id');

        if ($queue === IncomingEmailIntakeQueue::NeedsHuman) {
            return $query->whereIn('status', $queue->humanActionStatuses());
        }

        return $query
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
            IncomingEmailIntakeQueue::Promotional->value => 0,
            IncomingEmailIntakeQueue::Spam->value => 0,
            IncomingEmailIntakeQueue::Automatic->value => 0,
        ];
    }
}

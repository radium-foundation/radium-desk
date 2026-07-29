<?php

namespace App\Data\Commercial;

use App\Enums\CommercialAction;
use App\Enums\CommercialState;
use App\Enums\ContextScope;
use Illuminate\Support\Carbon;

/**
 * Immutable commercial posture for UI + enforcement (BR-04).
 *
 * UI must consume this snapshot — never re-derive refund/close rules inline.
 */
readonly class CommercialStateSnapshot
{
    /**
     * @param  list<array{label: string, value: string}>  $details
     * @param  list<CommercialAction>  $blockedActions
     */
    public function __construct(
        public CommercialState $state,
        public string $headline,
        public string $summary,
        public array $details,
        public array $blockedActions,
        public bool $showBanner,
        public bool $allowsReopen,
        public bool $timelineIsHistorical,
        public ?string $dashboardBadgeLabel,
        public ?string $resolvedDurationLabel = null,
        public ?int $refundId = null,
        public ?string $refundReference = null,
        public ContextScope $contextScope = ContextScope::Case,
    ) {}

    public function blocks(CommercialAction $action): bool
    {
        return in_array($action, $this->blockedActions, true);
    }

    public function allowsCommercialWork(): bool
    {
        return $this->state->allowsCommercialWork();
    }

    /**
     * @return array{
     *     state: string,
     *     label: string,
     *     headline: string,
     *     summary: string,
     *     details: list<array{label: string, value: string}>,
     *     banner_variant: string,
     *     show_banner: bool,
     *     allows_reopen: bool,
     *     allows_commercial_work: bool,
     *     timeline_is_historical: bool,
     *     blocked_actions: list<string>,
     *     dashboard_badge_label: ?string,
     *     resolved_duration_label: ?string,
     *     refund_id: ?int,
     *     refund_reference: ?string,
     *     context_scope: string,
     * }
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'label' => $this->state->label(),
            'headline' => $this->headline,
            'summary' => $this->summary,
            'details' => $this->details,
            'banner_variant' => $this->state->bannerVariant(),
            'show_banner' => $this->showBanner,
            'allows_reopen' => $this->allowsReopen,
            'allows_commercial_work' => $this->allowsCommercialWork(),
            'timeline_is_historical' => $this->timelineIsHistorical,
            'blocked_actions' => array_map(
                fn (CommercialAction $action): string => $action->value,
                $this->blockedActions,
            ),
            'dashboard_badge_label' => $this->dashboardBadgeLabel,
            'resolved_duration_label' => $this->resolvedDurationLabel,
            'refund_id' => $this->refundId,
            'refund_reference' => $this->refundReference,
            'context_scope' => $this->contextScope->value,
        ];
    }
}

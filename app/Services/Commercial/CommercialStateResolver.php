<?php

namespace App\Services\Commercial;

use App\Data\Commercial\CommercialStateSnapshot;
use App\Enums\CommercialAction;
use App\Enums\CommercialState;
use App\Enums\IncidentStatus;
use App\Enums\RefundStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\ServiceCaseCloseOutcome;
use App\Support\AppDateFormatter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Single source of truth for commercial posture (BR-04).
 *
 * UI and eligibility layers must consume snapshots from this resolver —
 * do not scatter refund/close commercial rules elsewhere.
 */
class CommercialStateResolver
{
    /**
     * @return list<CommercialAction>
     */
    private const REFUND_INITIATED_BLOCKED_ACTIONS = [
        CommercialAction::AssignServiceReference,
        CommercialAction::PaidService,
        CommercialAction::PaidAppointment,
    ];

    /**
     * @return list<CommercialAction>
     */
    private const REFUND_COMPLETED_BLOCKED_ACTIONS = [
        CommercialAction::AssignServiceReference,
        CommercialAction::PaidService,
        CommercialAction::PaidAppointment,
        CommercialAction::ChargeCustomer,
    ];

    public function enabled(): bool
    {
        return (bool) config('commercial_state.enabled', true);
    }

    public function forIncident(Incident $incident): CommercialStateSnapshot
    {
        $incident->loadMissing([
            'order.refundRequests.requester',
            'order.refundRequests.reviewer',
            'order.refundRequests.executor',
            'refundRequests.requester',
            'refundRequests.reviewer',
            'refundRequests.executor',
            'closeOutcomes.closer',
            'assignee',
        ]);

        return $this->resolve($incident, $incident->order);
    }

    public function forIncidentAndOrder(Incident $incident, ?Order $order): CommercialStateSnapshot
    {
        $incident->loadMissing([
            'refundRequests.requester',
            'refundRequests.reviewer',
            'refundRequests.executor',
            'closeOutcomes.closer',
        ]);

        $order?->loadMissing([
            'refundRequests.requester',
            'refundRequests.reviewer',
            'refundRequests.executor',
        ]);

        return $this->resolve($incident, $order);
    }

    public function blocks(Incident $incident, CommercialAction $action): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        return $this->forIncident($incident)->blocks($action);
    }

    public function ineligibilityReason(Incident $incident, CommercialAction $action): ?string
    {
        if (! $this->blocks($incident, $action)) {
            return null;
        }

        $snapshot = $this->forIncident($incident);

        return match ($snapshot->state) {
            CommercialState::RefundInitiated => 'Commercial decision pending — '.$action->label().' is unavailable while a refund is in progress.',
            CommercialState::RefundCompleted => 'Commercially closed — '.$action->label().' is unavailable after a refund was completed.',
            CommercialState::CaseClosed => $action->label().' is unavailable on a closed service case.',
            CommercialState::Open => null,
        };
    }

    private function resolve(Incident $incident, ?Order $order): CommercialStateSnapshot
    {
        $refunds = $this->refundsFor($incident, $order);
        $completedRefund = $this->latestMatchingRefund($refunds, [
            RefundStatus::Completed,
            RefundStatus::Closed,
            RefundStatus::Approved,
        ]);

        if ($completedRefund instanceof RefundRequest) {
            return $this->refundCompletedSnapshot($incident, $order, $completedRefund);
        }

        $initiatedRefund = $this->latestMatchingRefund($refunds, [
            RefundStatus::Pending,
            RefundStatus::PendingExecution,
        ]);

        if ($initiatedRefund instanceof RefundRequest) {
            return $this->refundInitiatedSnapshot($incident, $initiatedRefund);
        }

        if ($this->isCaseClosed($incident)) {
            return $this->caseClosedSnapshot($incident, $order);
        }

        return $this->openSnapshot();
    }

    /**
     * @return Collection<int, RefundRequest>
     */
    private function refundsFor(Incident $incident, ?Order $order): Collection
    {
        $incidentRefunds = $incident->relationLoaded('refundRequests')
            ? $incident->refundRequests
            : $incident->refundRequests()->get();

        if ($order === null) {
            return $incidentRefunds;
        }

        $orderRefunds = $order->relationLoaded('refundRequests')
            ? $order->refundRequests
            : $order->refundRequests()->get();

        return $incidentRefunds
            ->concat($orderRefunds)
            ->unique('id')
            ->values();
    }

    /**
     * @param  Collection<int, RefundRequest>  $refunds
     * @param  list<RefundStatus>  $statuses
     */
    private function latestMatchingRefund(Collection $refunds, array $statuses): ?RefundRequest
    {
        $statusValues = array_map(fn (RefundStatus $status): string => $status->value, $statuses);

        return $refunds
            ->filter(function (RefundRequest $refund) use ($statusValues): bool {
                $status = $refund->status;

                return $status instanceof RefundStatus
                    && in_array($status->value, $statusValues, true);
            })
            ->sortByDesc(fn (RefundRequest $refund): int => $refund->id)
            ->first();
    }

    private function isCaseClosed(Incident $incident): bool
    {
        return in_array($incident->status, [IncidentStatus::Closed, IncidentStatus::Resolved], true);
    }

    private function openSnapshot(): CommercialStateSnapshot
    {
        return new CommercialStateSnapshot(
            state: CommercialState::Open,
            headline: 'Open',
            summary: 'Commercial work is allowed.',
            details: [],
            blockedActions: [],
            showBanner: false,
            allowsReopen: false,
            timelineIsHistorical: false,
            dashboardBadgeLabel: null,
        );
    }

    private function caseClosedSnapshot(Incident $incident, ?Order $order): CommercialStateSnapshot
    {
        $outcome = $this->latestCloseOutcome($incident);
        $closedAt = $outcome?->closed_at
            ?? $order?->completed_at
            ?? $incident->updated_at;
        $closedBy = $outcome?->closer?->name
            ?? $incident->assignee?->name
            ?? '—';
        $resolutionLabel = $this->resolutionDurationLabel($incident, $closedAt, $order);

        $details = [
            ['label' => 'Closed By', 'value' => $closedBy],
            ['label' => 'Closed On', 'value' => AppDateFormatter::datetime($closedAt) ?? '—'],
            ['label' => 'Resolution Time', 'value' => $resolutionLabel ?? '—'],
        ];

        return new CommercialStateSnapshot(
            state: CommercialState::CaseClosed,
            headline: 'Case Closed',
            summary: 'Service case is closed. Reopen remains available.',
            details: $details,
            blockedActions: [],
            showBanner: true,
            allowsReopen: true,
            timelineIsHistorical: true,
            dashboardBadgeLabel: null,
            resolvedDurationLabel: $resolutionLabel,
        );
    }

    private function refundInitiatedSnapshot(Incident $incident, RefundRequest $refund): CommercialStateSnapshot
    {
        $details = [
            ['label' => 'Refund Requested', 'value' => $refund->reference_no ?: '—'],
            ['label' => 'Requested By', 'value' => $refund->requester?->name ?? '—'],
            ['label' => 'Requested On', 'value' => AppDateFormatter::datetime($refund->created_at) ?? '—'],
            ['label' => 'Approval Pending', 'value' => $refund->status?->label() ?? 'Pending'],
        ];

        return new CommercialStateSnapshot(
            state: CommercialState::RefundInitiated,
            headline: 'Refund Requested',
            summary: 'Commercial decision pending. Assign Ref No, Paid Service, and Paid Appointment are disabled.',
            details: $details,
            blockedActions: self::REFUND_INITIATED_BLOCKED_ACTIONS,
            showBanner: true,
            allowsReopen: $this->isCaseClosed($incident),
            timelineIsHistorical: $this->isCaseClosed($incident),
            dashboardBadgeLabel: 'Refund pending',
            refundId: $refund->id,
            refundReference: $refund->reference_no,
        );
    }

    private function refundCompletedSnapshot(
        Incident $incident,
        ?Order $order,
        RefundRequest $refund,
    ): CommercialStateSnapshot {
        $completedAt = $refund->executed_at ?? $refund->closed_at ?? $refund->reviewed_at ?? $refund->updated_at;
        $approvedBy = $refund->reviewer?->name ?? $refund->executor?->name ?? '—';
        $amount = '₹'.number_format($refund->displayAmount(), 2);

        $details = [
            ['label' => 'Refund Ref', 'value' => $refund->reference_no ?: '—'],
            ['label' => 'Amount', 'value' => $amount],
            ['label' => 'Approved By', 'value' => $approvedBy],
            ['label' => 'Completed On', 'value' => AppDateFormatter::datetime($completedAt) ?? '—'],
            ['label' => 'Status', 'value' => 'Commercially Closed'],
        ];

        return new CommercialStateSnapshot(
            state: CommercialState::RefundCompleted,
            headline: 'Refund Completed',
            summary: 'Commercially closed. Service reference generation and paid commercial actions are disabled.',
            details: $details,
            blockedActions: self::REFUND_COMPLETED_BLOCKED_ACTIONS,
            showBanner: true,
            allowsReopen: $this->isCaseClosed($incident),
            timelineIsHistorical: true,
            dashboardBadgeLabel: 'Refunded',
            resolvedDurationLabel: $this->resolutionDurationLabel($incident, $completedAt, $order),
            refundId: $refund->id,
            refundReference: $refund->reference_no,
        );
    }

    private function latestCloseOutcome(Incident $incident): ?ServiceCaseCloseOutcome
    {
        $outcomes = $incident->relationLoaded('closeOutcomes')
            ? $incident->closeOutcomes
            : $incident->closeOutcomes()->with('closer')->get();

        return $outcomes->sortByDesc(fn (ServiceCaseCloseOutcome $outcome): int => $outcome->id)->first();
    }

    private function resolutionDurationLabel(
        Incident $incident,
        ?Carbon $endedAt,
        ?Order $order,
    ): ?string {
        $end = $endedAt ?? $order?->completed_at;

        if ($incident->created_at === null || $end === null) {
            return null;
        }

        return Order::formatCompactDurationBetween($incident->created_at, $end);
    }
}

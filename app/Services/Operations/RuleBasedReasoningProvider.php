<?php

namespace App\Services\Operations;

use App\Contracts\Operations\IraReasoningProvider;
use App\Data\Operations\IraMorningBriefing;
use App\Data\Operations\IraOperationalRecommendation;
use App\Data\Operations\IraOperationalRisk;
use App\Data\Operations\IraOperationalSnapshotData;
use Illuminate\Support\Carbon;

class RuleBasedReasoningProvider implements IraReasoningProvider
{
    public function __construct(
        private readonly IraBriefingFormatter $briefingFormatter,
        private readonly OperationsCashfreeDeviceEnrichmentService $cashfreeDeviceEnrichmentService,
        private readonly OperationsMissingSerialAutomationService $missingSerialAutomationService,
        private readonly OperationsCashfreeHealthService $cashfreeHealthService,
    ) {}

    public function name(): string
    {
        return 'rule_based';
    }

    /**
     * @param  list<IraOperationalRisk>  $risks
     * @param  list<IraOperationalRecommendation>  $recommendations
     */
    public function generateBriefing(
        IraOperationalSnapshotData $snapshot,
        ?IraOperationalSnapshotData $yesterday,
        array $risks,
        array $recommendations,
        ?Carbon $at = null,
    ): IraMorningBriefing {
        $at ??= now();
        $greeting = $this->briefingFormatter->greeting(at: $at);
        $highlights = $this->highlights($snapshot, $yesterday, $at);
        $healthStatus = $this->healthStatus($risks);
        $summary = $this->summary($healthStatus, $risks, $recommendations, $snapshot);

        return new IraMorningBriefing(
            greeting: $greeting,
            summary: $summary,
            healthStatus: $healthStatus,
            highlights: $highlights,
            risks: $risks,
            recommendations: $recommendations,
            snapshot: $snapshot,
            yesterdaySnapshot: $yesterday,
        );
    }

    /**
     * @param  list<IraOperationalRisk>  $risks
     * @param  list<IraOperationalRecommendation>  $recommendations
     */
    private function summary(
        string $healthStatus,
        array $risks,
        array $recommendations,
        IraOperationalSnapshotData $snapshot,
    ): string {
        $riskCounts = $this->briefingFormatter->classifyRisks($risks, $snapshot);

        $healthMessage = match ($healthStatus) {
            'healthy' => 'Operations look healthy today.',
            'critical' => 'Operations need immediate attention.',
            default => 'Operations are manageable with some risks to watch.',
        };

        $riskParts = [];

        if ($riskCounts['critical'] > 0) {
            $riskParts[] = $riskCounts['critical'] === 1
                ? '1 requires action'
                : "{$riskCounts['critical']} require action";
        }

        if ($riskCounts['monitoring'] > 0) {
            $riskParts[] = $riskCounts['monitoring'] === 1
                ? '1 being monitored'
                : "{$riskCounts['monitoring']} being monitored";
        }

        if ($riskCounts['attention'] > 0) {
            $riskParts[] = $riskCounts['attention'] === 1
                ? '1 should review'
                : "{$riskCounts['attention']} should review";
        }

        if ($riskParts === []) {
            return $healthMessage;
        }

        $summary = "{$healthMessage} ".implode(', ', $riskParts).'.';
        $suggestion = $this->briefingFormatter->selectSuggestion($recommendations, $risks);

        if ($suggestion !== null) {
            $summary .= ' '.$suggestion;
        }

        return $summary;
    }

    /**
     * @return list<string>
     */
    private function highlights(
        IraOperationalSnapshotData $snapshot,
        ?IraOperationalSnapshotData $yesterday,
        Carbon $at,
    ): array {
        $highlights = [];
        $actionRequired = (int) ($snapshot->operations['action_required'] ?? 0);
        $attention = (int) ($snapshot->operations['attention'] ?? 0);
        $casesNeedAction = $actionRequired + $attention;
        $scheduled = (int) ($snapshot->operations['scheduled'] ?? 0);
        $overdue = (int) ($snapshot->operations['overdue'] ?? 0);
        $warning = (int) ($snapshot->operations['warning'] ?? 0);

        $highlights[] = "{$casesNeedAction} case(s) need action today";
        $highlights[] = "{$scheduled} appointment(s) scheduled";

        $leaveMembers = $this->leaveMemberNames($at);

        foreach ($leaveMembers as $name) {
            $highlights[] = "{$name} is on leave";
        }

        if ($overdue > 0) {
            $highlights[] = $overdue === 1
                ? '1 case requires action'
                : "{$overdue} cases require action";
        }

        if ($warning > 0) {
            $highlights[] = $warning === 1
                ? '1 case being monitored'
                : "{$warning} cases being monitored";
        }

        $cashfreeHealth = $this->cashfreeHealthService->widget();

        if (($cashfreeHealth['is_healthy'] ?? false) === true) {
            if (($cashfreeHealth['historical_resolved_failures'] ?? 0) > 0) {
                $highlights[] = sprintf(
                    'Cashfree healthy. %d historical failure(s) archived.',
                    $cashfreeHealth['historical_resolved_failures'],
                );
            }
        } else {
            if (($cashfreeHealth['paid_without_desk_order'] ?? 0) > 0) {
                $highlights[] = sprintf(
                    '%d paid Cashfree payment(s) missing Desk orders.',
                    $cashfreeHealth['paid_without_desk_order'],
                );
            } elseif (($cashfreeHealth['active_failed_webhooks'] ?? 0) > 0) {
                $highlights[] = sprintf(
                    '%d actionable Cashfree webhook failure(s) require recovery.',
                    $cashfreeHealth['active_failed_webhooks'],
                );
            }
        }

        $deviceQuality = $this->cashfreeDeviceEnrichmentService->qualitySummary();

        if ($deviceQuality->paidOrdersMissingDeviceInfo > 0) {
            $highlights[] = sprintf(
                '%d paid order(s) missing device info (%d recovered from RadiumBox, %d need customer contact)',
                $deviceQuality->paidOrdersMissingDeviceInfo,
                $deviceQuality->recoveredFromRadiumBox,
                $deviceQuality->needCustomerContact,
            );
        }

        $missingSerialQuality = $this->missingSerialAutomationService->qualitySummary();

        if ($missingSerialQuality->needSerial > 0) {
            $highlights[] = sprintf(
                'Missing serial automation: %d need serial (%d auto requested, %d customer replied, %d coordinator follow-up)',
                $missingSerialQuality->needSerial,
                $missingSerialQuality->autoRequested,
                $missingSerialQuality->customerReplied,
                $missingSerialQuality->coordinatorFollowUp,
            );
        }

        if ($yesterday !== null) {
            $openDelta = (int) ($snapshot->operations['open_cases'] ?? 0)
                - (int) ($yesterday->operations['open_cases'] ?? 0);

            if ($openDelta !== 0) {
                $direction = $openDelta > 0 ? 'up' : 'down';
                $highlights[] = 'Open cases are '.abs($openDelta)." {$direction} vs yesterday";
            }
        }

        return $highlights;
    }

    /**
     * @param  list<IraOperationalRisk>  $risks
     */
    private function healthStatus(array $risks): string
    {
        $highRisks = collect($risks)
            ->filter(fn (IraOperationalRisk $risk): bool => $risk->severity === \App\Enums\AI\AIRiskLevel::High)
            ->count();

        if ($highRisks === 0 && count($risks) <= 1) {
            return 'healthy';
        }

        if ($highRisks >= 2) {
            return 'critical';
        }

        return 'attention_needed';
    }

    /**
     * @return list<string>
     */
    private function leaveMemberNames(Carbon $at): array
    {
        $workCalendar = app(WorkCalendarService::class);
        $availability = app(TeamAvailabilityService::class);
        $roleService = app(OperationsRoleService::class);

        return \App\Models\User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $roleService->operationalRoleSlugs()))
            ->get()
            ->filter(fn (\App\Models\User $user): bool => $workCalendar->hasApprovedLeave($user, $at)
                || $availability->isOnLeave($user, $at))
            ->map(fn (\App\Models\User $user): string => $user->name)
            ->values()
            ->all();
    }
}

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
        $greeting = $this->greeting($at);
        $highlights = $this->highlights($snapshot, $yesterday, $at);
        $healthStatus = $this->healthStatus($risks);
        $summary = $this->summary($healthStatus, $risks, $recommendations);

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

    private function greeting(Carbon $at): string
    {
        $hour = (int) $at->format('G');

        $timeGreeting = match (true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };

        return "{$timeGreeting}.";
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
        $slaRisk = (int) ($snapshot->operations['overdue'] ?? 0)
            + (int) ($snapshot->operations['warning'] ?? 0);

        $highlights[] = "{$casesNeedAction} case(s) need action today";
        $highlights[] = "{$scheduled} appointment(s) scheduled";

        $leaveMembers = $this->leaveMemberNames($at);

        foreach ($leaveMembers as $name) {
            $highlights[] = "{$name} is on leave";
        }

        if ($slaRisk > 0) {
            $highlights[] = "{$slaRisk} case(s) risk SLA breach";
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
     * @param  list<IraOperationalRisk>  $risks
     * @param  list<IraOperationalRecommendation>  $recommendations
     */
    private function summary(string $healthStatus, array $risks, array $recommendations): string
    {
        $riskCount = count($risks);

        $healthMessage = match ($healthStatus) {
            'healthy' => 'Operations look healthy today.',
            'critical' => 'Operations need immediate attention.',
            default => 'Operations are manageable with some risks to watch.',
        };

        if ($riskCount === 0) {
            return $healthMessage;
        }

        $riskLabel = $riskCount === 1 ? '1 risk needs' : "{$riskCount} risks need";

        $summary = "{$healthMessage} {$riskLabel} attention.";

        if ($recommendations !== []) {
            $summary .= ' '.$recommendations[0]->message;
        }

        return $summary;
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

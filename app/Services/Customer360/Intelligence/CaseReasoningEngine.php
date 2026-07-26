<?php

namespace App\Services\Customer360\Intelligence;

use App\Data\Customer360\Intelligence\CaseIntelligenceBlocker;
use App\Data\Customer360\Intelligence\CaseIntelligenceRisk;
use App\Data\Customer360\Intelligence\CaseIntelligenceSnapshot;
use App\Data\Customer360\Intelligence\CaseReasoningFinding;
use App\Data\Customer360\Intelligence\CaseReasoningResult;
use App\Data\Customer360\Intelligence\CaseStory;
use App\Data\TimelineEvent;
use App\Enums\AI\AIRiskLevel;
use App\Enums\AutomationExecutionStatus;
use App\Enums\SupportAppointmentStatus;
use App\Enums\TimelineEventType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Deterministic case understanding layer.
 *
 * Receives only CaseIntelligenceSnapshot and returns an enriched snapshot
 * with reasoning findings + structured Case Story. No AI / LLM.
 */
class CaseReasoningEngine
{
    public function enrich(CaseIntelligenceSnapshot $snapshot): CaseIntelligenceSnapshot
    {
        $findings = array_values(array_filter([
            $this->detectWaitingTooLong($snapshot),
            $this->detectRepeatedCustomerReminders($snapshot),
            $this->detectRepeatedCustomerReschedules($snapshot),
            $this->detectMultipleEngineerAssignments($snapshot),
            $this->detectEngineerNoShow($snapshot),
            $this->detectCustomerSilent($snapshot),
            $this->detectFrequentCustomerCalls($snapshot),
            $this->detectRepeatedRepairs($snapshot),
            $this->detectPaymentOverdue($snapshot),
            $this->detectSerialPendingTooLong($snapshot),
            $this->detectAppointmentOverdue($snapshot),
            $this->detectRepeatedCancellations($snapshot),
            $this->detectLongInactivity($snapshot),
            $this->detectSlaLikelyToBreach($snapshot),
            $this->detectCaseIdle($snapshot),
            $this->detectMissingMandatoryInformation($snapshot),
            $this->detectContactedWithoutProgress($snapshot),
            $this->detectAutomationStalled($snapshot),
            $this->detectPremiumCustomerAtRisk($snapshot),
            $this->detectHighPriorityUnattended($snapshot),
            $this->detectWaitingOnInternalTooLong($snapshot),
        ]));

        $result = $this->assembleResult($snapshot, $findings);
        $caseStory = $this->buildCaseStory($snapshot, $result);

        return $snapshot->withReasoning($result, $caseStory);
    }

    /**
     * @param  list<CaseReasoningFinding>  $findings
     */
    private function assembleResult(CaseIntelligenceSnapshot $snapshot, array $findings): CaseReasoningResult
    {
        $riskExplanations = [];
        foreach ($snapshot->risks as $risk) {
            $matched = $this->findingForRisk($findings, $risk);
            if ($matched !== null) {
                $riskExplanations[$risk->key] = $matched->explanation;
            } elseif ($risk->explanation !== null) {
                $riskExplanations[$risk->key] = $risk->explanation;
            } else {
                $riskExplanations[$risk->key] = $this->defaultRiskExplanation($risk);
            }
        }

        foreach ($findings as $finding) {
            if ($finding->category === 'risk' && ! isset($riskExplanations[$finding->key])) {
                $riskExplanations[$finding->key] = $finding->explanation;
            }
        }

        $blockerExplanations = [];
        foreach ($snapshot->blockers as $blocker) {
            $matched = $this->findingForBlocker($findings, $blocker);
            if ($matched !== null) {
                $blockerExplanations[$blocker->key] = $matched->explanation;
            } elseif ($blocker->explanation !== null) {
                $blockerExplanations[$blocker->key] = $blocker->explanation;
            } else {
                $blockerExplanations[$blocker->key] = $this->defaultBlockerExplanation($blocker);
            }
        }

        foreach ($findings as $finding) {
            if ($finding->category === 'blocker' && ! isset($blockerExplanations[$finding->key])) {
                $blockerExplanations[$finding->key] = $finding->explanation;
            }
        }

        // Canonical recommendation reasoning is the snapshot recommendation rationale only.
        // Findings must not invent a competing next-action narrative (Q2).
        $recommendedActionReasoning = array_values(array_unique([
            ...$snapshot->recommendedAction->rationale,
        ]));

        $executiveSummaryFacts = array_values(array_unique(array_map(
            fn (CaseReasoningFinding $finding): string => $finding->title.': '.$finding->explanation,
            $this->sortedBySeverity($findings),
        )));

        return new CaseReasoningResult(
            findings: $findings,
            matchedRuleKeys: array_map(fn (CaseReasoningFinding $f): string => $f->key, $findings),
            riskExplanations: $riskExplanations,
            blockerExplanations: $blockerExplanations,
            recommendedActionReasoning: $recommendedActionReasoning,
            executiveSummaryFacts: $executiveSummaryFacts,
        );
    }

    private function buildCaseStory(CaseIntelligenceSnapshot $snapshot, CaseReasoningResult $result): CaseStory
    {
        $currentSituation = array_values(array_filter([
            'Status: '.$snapshot->currentStatusLabel.'.',
            $snapshot->isWaiting
                ? 'Waiting on '.($snapshot->waitingParty === 'none' ? 'unknown party' : $snapshot->waitingParty)
                    .($snapshot->waitingReasonLabel ? ' for '.$snapshot->waitingReasonLabel : '')
                    .($snapshot->waitingSince ? ' since '.$snapshot->waitingSince->toDateString() : '')
                    .'.'
                : 'Case is not in an active waiting state.',
            $snapshot->engineerName !== null ? 'Assigned engineer: '.$snapshot->engineerName.'.' : null,
            'Priority: '.$snapshot->priorityLevel.'.',
            'SLA: '.$snapshot->slaStatus.'.',
        ]));

        $progress = [];
        if ($snapshot->journey !== null) {
            foreach ($snapshot->journey->milestoneTitles() as $title) {
                $progress[] = $title;
            }
            if ($snapshot->journey->conclusion->headline !== '') {
                $progress[] = 'Journey: '.$snapshot->journey->conclusion->headline;
            }
        }
        if ($progress === []) {
            $progress[] = 'No structured journey milestones recorded yet.';
        }

        $blockers = [];
        foreach ($snapshot->blockers as $blocker) {
            $blockers[] = $result->blockerExplanations[$blocker->key] ?? $blocker->label;
        }
        foreach ($result->findings as $finding) {
            if ($finding->category === 'blocker' && ! in_array($finding->explanation, $blockers, true)) {
                $blockers[] = $finding->explanation;
            }
        }
        if ($blockers === []) {
            $blockers[] = 'No active blockers identified.';
        }

        $risks = [];
        foreach ($snapshot->risks as $risk) {
            $risks[] = $result->riskExplanations[$risk->key] ?? $risk->label;
        }
        foreach ($result->findings as $finding) {
            if (in_array($finding->category, ['risk', 'sla', 'inactivity'], true)
                && ! in_array($finding->explanation, $risks, true)) {
                $risks[] = $finding->explanation;
            }
        }
        if ($risks === []) {
            $risks[] = 'No elevated risk signals identified.';
        }

        $recommendedAction = array_values(array_filter([
            $snapshot->recommendedAction->label,
            $snapshot->recommendedAction->recommendationText,
            ...$snapshot->recommendedAction->rationale,
        ]));

        $supportingFacts = array_values(array_unique([
            ...array_map(fn (CaseReasoningFinding $f): string => $f->title, $result->findings),
            ...array_map(fn ($e) => $e->title, $snapshot->evidence),
            ...$snapshot->openQuestions,
            ...$result->executiveSummaryFacts,
        ]));

        return new CaseStory(
            currentSituation: $currentSituation,
            progress: $progress,
            blockers: $blockers,
            risks: $risks,
            recommendedAction: $recommendedAction,
            supportingFacts: $supportingFacts,
        );
    }

    private function detectWaitingTooLong(CaseIntelligenceSnapshot $snapshot): ?CaseReasoningFinding
    {
        if (! $snapshot->isWaiting || $snapshot->waitingSince === null) {
            return null;
        }

        $days = $this->daysSince($snapshot->waitingSince, $snapshot->generatedAt);
        $threshold = $this->threshold('waiting_too_long_days', 3);

        if ($days < $threshold) {
            return null;
        }

        return new CaseReasoningFinding(
            key: 'waiting_too_long',
            title: 'Waiting too long',
            category: 'waiting',
            severity: $days >= ($threshold * 2) ? AIRiskLevel::High : AIRiskLevel::Medium,
            explanation: sprintf(
                'Case has been waiting on %s for %d day(s) (threshold %d).',
                $snapshot->waitingParty,
                $days,
                $threshold,
            ),
            signals: [
                'waiting_party' => $snapshot->waitingParty,
                'waiting_days' => $days,
                'threshold_days' => $threshold,
                'waiting_reason' => $snapshot->waitingReasonCode,
            ],
            evidenceRefs: ['waiting'],
        );
    }

    private function detectRepeatedCustomerReminders(CaseIntelligenceSnapshot $snapshot): ?CaseReasoningFinding
    {
        $threshold = $this->threshold('repeated_reminders_threshold', 2);
        $count = $this->countTextMatches($snapshot, ['reminder', 'follow-up', 'follow up', 'followup']);

        if ($count < $threshold) {
            return null;
        }

        return new CaseReasoningFinding(
            key: 'repeated_customer_reminders',
            title: 'Repeated customer reminders',
            category: 'communication',
            severity: $count >= ($threshold + 2) ? AIRiskLevel::High : AIRiskLevel::Medium,
            explanation: sprintf(
                'Customer has been reminded %d time(s) without clearance of the waiting condition.',
                $count,
            ),
            signals: ['reminder_count' => $count, 'threshold' => $threshold],
            evidenceRefs: ['communication'],
        );
    }

    private function detectRepeatedCustomerReschedules(CaseIntelligenceSnapshot $snapshot): ?CaseReasoningFinding
    {
        $threshold = $this->threshold('repeated_reschedules_threshold', 2);
        $count = $this->countTextMatches($snapshot, ['reschedule', 're-schedule', 'slot changed', 'appointment changed']);

        if ($count < $threshold) {
            return null;
        }

        return new CaseReasoningFinding(
            key: 'repeated_customer_reschedules',
            title: 'Repeated customer reschedules',
            category: 'appointment',
            severity: AIRiskLevel::Medium,
            explanation: sprintf('Appointment was rescheduled %d time(s), indicating scheduling friction.', $count),
            signals: ['reschedule_count' => $count, 'threshold' => $threshold],
            evidenceRefs: ['appointment'],
        );
    }

    private function detectMultipleEngineerAssignments(CaseIntelligenceSnapshot $snapshot): ?CaseReasoningFinding
    {
        $threshold = $this->threshold('multiple_assignments_threshold', 2);
        $assignmentEvents = $this->timelineEvents($snapshot)
            ->filter(fn (TimelineEvent $event): bool => $event->type === TimelineEventType::Assignment);
        $activityAssignments = collect($snapshot->context->recentActivities)
            ->filter(fn (array $activity): bool => Str::contains(strtolower((string) ($activity['type'] ?? '')), 'assign'));

        $count = max($assignmentEvents->count(), $activityAssignments->count());

        if ($count < $threshold) {
            return null;
        }

        return new CaseReasoningFinding(
            key: 'multiple_engineer_assignments',
            title: 'Multiple engineer assignments',
            category: 'assignment',
            severity: $count >= ($threshold + 1) ? AIRiskLevel::High : AIRiskLevel::Medium,
            explanation: sprintf('Engineer assignment changed %d time(s), which can delay ownership continuity.', $count),
            signals: [
                'assignment_event_count' => $assignmentEvents->count(),
                'assignment_activity_count' => $activityAssignments->count(),
                'threshold' => $threshold,
                'current_engineer' => $snapshot->engineerName,
            ],
            evidenceRefs: ['assignment'],
        );
    }

    private function detectEngineerNoShow(CaseIntelligenceSnapshot $snapshot): ?CaseReasoningFinding
    {
        $appointment = $snapshot->supportAppointment ?? $snapshot->context->supportAppointment;
        if (! is_array($appointment)) {
            return null;
        }

        $preferredDate = $this->carbonOrNull($appointment['preferred_date'] ?? null);
        $isActive = (bool) ($appointment['is_active'] ?? false);
        $isCompleted = (bool) ($appointment['is_completed'] ?? false);
        $status = $appointment['status'] ?? null;
        $statusValue = $status instanceof SupportAppointmentStatus
            ? $status->value
            : (is_string($status) ? strtolower($status) : null);

        $missedKeywords = $this->countTextMatches($snapshot, ['no-show', 'no show', 'missed visit', 'engineer missed']);

        $cancelledAfterDue = $preferredDate !== null
            && $preferredDate->lt($snapshot->generatedAt->copy()->startOfDay())
            && in_array($statusValue, ['cancelled', SupportAppointmentStatus::Cancelled->value], true);

        // Overdue active appointments are covered by appointment_overdue; no-show needs
        // explicit miss/cancel evidence so the two rules stay distinct.
        if (! $cancelledAfterDue && $missedKeywords === 0) {
            return null;
        }

        return new CaseReasoningFinding(
            key: 'engineer_no_show',
            title: 'Engineer no-show',
            category: 'appointment',
            severity: AIRiskLevel::High,
            explanation: 'Scheduled visit appears missed or cancelled after the preferred date without completion.',
            signals: [
                'preferred_date' => $preferredDate?->toDateString(),
                'is_active' => $isActive,
                'is_completed' => $isCompleted,
                'status' => $statusValue,
                'missed_keyword_hits' => $missedKeywords,
            ],
            evidenceRefs: ['appointment'],
        );
    }

    private function detectCustomerSilent(CaseIntelligenceSnapshot $snapshot): ?CaseReasoningFinding
    {
        if (! $snapshot->isWaiting || $snapshot->waitingParty !== 'customer') {
            return null;
        }

        $threshold = $this->threshold('customer_silent_days', 2);
        $since = $snapshot->waitingSince ?? $snapshot->context->customerIntelligence->lastInteractionAt;
        if ($since === null) {
            return null;
        }

        $days = $this->daysSince($since, $snapshot->generatedAt);
        if ($days < $threshold) {
            return null;
        }

        $customerActivityAfterWait = $this->timelineEvents($snapshot)
            ->filter(fn (TimelineEvent $event): bool => $event->occurredAt->gte($since)
                && strcasecmp($event->actor->displayName, 'Customer') === 0)
            ->count();

        if ($customerActivityAfterWait > 0) {
            return null;
        }

        return new CaseReasoningFinding(
            key: 'customer_silent',
            title: 'Customer silent',
            category: 'waiting',
            severity: $days >= ($threshold * 2) ? AIRiskLevel::High : AIRiskLevel::Medium,
            explanation: sprintf(
                'Waiting on customer for %d day(s) with no customer-side activity recorded.',
                $days,
            ),
            signals: [
                'silent_days' => $days,
                'threshold_days' => $threshold,
                'waiting_reason' => $snapshot->waitingReasonCode,
            ],
            evidenceRefs: ['waiting', 'customer'],
        );
    }

    private function detectFrequentCustomerCalls(CaseIntelligenceSnapshot $snapshot): ?CaseReasoningFinding
    {
        $threshold = $this->threshold('frequent_calls_threshold', 3);
        $ivrCount = $this->timelineEvents($snapshot)
            ->filter(fn (TimelineEvent $event): bool => $event->type === TimelineEventType::IvrCall)
            ->count();
        $activityCalls = collect($snapshot->context->recentActivities)
            ->filter(function (array $activity): bool {
                $blob = strtolower(($activity['type'] ?? '').' '.($activity['title'] ?? ''));

                return Str::contains($blob, ['call', 'ivr', 'phone']);
            })
            ->count();
        $count = max($ivrCount, $activityCalls);

        $ops = $snapshot->context->operationalIntelligence;
        if ($count < $threshold && ! ($ops->repeatContactHighUrgency && Str::contains(strtolower((string) $ops->repeatContactSummary), 'call'))) {
            return null;
        }

        if ($count < $threshold && $ops->repeatContactHighUrgency) {
            $count = max($count, $threshold);
        }

        return new CaseReasoningFinding(
            key: 'frequent_customer_calls',
            title: 'Frequent customer calls',
            category: 'communication',
            severity: AIRiskLevel::High,
            explanation: sprintf('Customer call / IVR contact pressure is elevated (%d signal(s)).', $count),
            signals: [
                'ivr_count' => $ivrCount,
                'activity_call_count' => $activityCalls,
                'repeat_contact_high_urgency' => $ops->repeatContactHighUrgency,
                'repeat_contact_summary' => $ops->repeatContactSummary,
            ],
            evidenceRefs: ['communication'],
        );
    }

    private function detectRepeatedRepairs(CaseIntelligenceSnapshot $snapshot): ?CaseReasoningFinding
    {
        $threshold = $this->threshold('repeated_repairs_threshold', 2);
        $customer = $snapshot->context->customerIntelligence;
        $device = $snapshot->context->deviceIntelligence;
        $repairCount = max(
            $customer->lifetimeRepairCount,
            $device->previousRepairsOnSerial + 1,
        );

        if (
            ! $customer->repeatIssueDetected
            && $device->previousRepairsOnSerial < 1
            && $customer->lifetimeRepairCount < $threshold
        ) {
            return null;
        }

        return new CaseReasoningFinding(
            key: 'repeated_repairs',
            title: 'Repeated repairs',
            category: 'quality',
            severity: AIRiskLevel::High,
            explanation: $customer->repeatIssueSummary
                ?? sprintf('Device/customer shows repeat repair pattern (repair signals: %d).', $repairCount),
            signals: [
                'repeat_issue_detected' => $customer->repeatIssueDetected,
                'lifetime_repair_count' => $customer->lifetimeRepairCount,
                'previous_repairs_on_serial' => $device->previousRepairsOnSerial,
            ],
            evidenceRefs: ['device', 'customer'],
        );
    }

    private function detectPaymentOverdue(CaseIntelligenceSnapshot $snapshot): ?CaseReasoningFinding
    {
        $customer = $snapshot->context->customerIntelligence;
        $behaviour = strtolower($customer->paymentBehaviour);
        $waitingPayment = $snapshot->isWaiting
            && (
                Str::contains(strtolower((string) $snapshot->waitingReasonCode), 'payment')
                || Str::contains(strtolower((string) $snapshot->waitingReasonLabel), 'payment')
            );

        $overdue = $customer->outstandingBalance > 0
            || Str::contains($behaviour, ['overdue', 'outstanding', 'unpaid', 'pending payment'])
            || $waitingPayment;

        if (! $overdue) {
            return null;
        }

        return new CaseReasoningFinding(
            key: 'payment_overdue',
            title: 'Payment overdue',
            category: 'payment',
            severity: $customer->outstandingBalance > 0 ? AIRiskLevel::High : AIRiskLevel::Medium,
            explanation: $customer->outstandingBalance > 0
                ? sprintf('Outstanding balance of %.2f remains unpaid.', $customer->outstandingBalance)
                : 'Payment appears pending/overdue based on waiting state or payment behaviour.',
            signals: [
                'outstanding_balance' => $customer->outstandingBalance,
                'payment_behaviour' => $customer->paymentBehaviour,
                'waiting_payment' => $waitingPayment,
            ],
            evidenceRefs: ['payment'],
        );
    }

    private function detectSerialPendingTooLong(CaseIntelligenceSnapshot $snapshot): ?CaseReasoningFinding
    {
        if (! $snapshot->serialMissing) {
            return null;
        }

        $threshold = $this->threshold('serial_pending_too_long_days', 2);
        $since = $snapshot->waitingSince
            ?? $this->carbonOrNull($snapshot->context->waitingState['started_at'] ?? null)
            ?? $snapshot->generatedAt;
        $days = $this->daysSince($since, $snapshot->generatedAt);

        if ($days < $threshold && ! $this->hasBlockerKey($snapshot, 'serial_missing')) {
            // Still flag if serial missing with blocker even below threshold? Only when long enough OR waiting.
            if (! $snapshot->isWaiting || $days < $threshold) {
                return null;
            }
        }

        if ($days < $threshold) {
            return null;
        }

        return new CaseReasoningFinding(
            key: 'serial_pending_too_long',
            title: 'Serial pending too long',
            category: 'blocker',
            severity: AIRiskLevel::High,
            explanation: sprintf('Device serial has been missing for %d day(s) (threshold %d).', $days, $threshold),
            signals: [
                'serial_missing' => true,
                'pending_days' => $days,
                'threshold_days' => $threshold,
            ],
            evidenceRefs: ['serial'],
        );
    }

    private function detectAppointmentOverdue(CaseIntelligenceSnapshot $snapshot): ?CaseReasoningFinding
    {
        $appointment = $snapshot->supportAppointment ?? $snapshot->context->supportAppointment;
        if (! is_array($appointment)) {
            return null;
        }

        $preferredDate = $this->carbonOrNull($appointment['preferred_date'] ?? null);
        $isActive = (bool) ($appointment['is_active'] ?? false);
        $isCompleted = (bool) ($appointment['is_completed'] ?? false);

        if ($preferredDate === null || ! $isActive || $isCompleted) {
            return null;
        }

        if ($preferredDate->gte($snapshot->generatedAt->copy()->startOfDay())) {
            return null;
        }

        $days = $preferredDate->diffInDays($snapshot->generatedAt->copy()->startOfDay());

        return new CaseReasoningFinding(
            key: 'appointment_overdue',
            title: 'Appointment overdue',
            category: 'appointment',
            severity: $days >= 2 ? AIRiskLevel::High : AIRiskLevel::Medium,
            explanation: sprintf(
                'Active support appointment preferred date %s is overdue by %d day(s).',
                $preferredDate->toDateString(),
                $days,
            ),
            signals: [
                'preferred_date' => $preferredDate->toDateString(),
                'overdue_days' => $days,
                'assignee' => $appointment['assignee_name'] ?? $snapshot->engineerName,
            ],
            evidenceRefs: ['appointment'],
        );
    }

    private function detectRepeatedCancellations(CaseIntelligenceSnapshot $snapshot): ?CaseReasoningFinding
    {
        $threshold = $this->threshold('repeated_cancellations_threshold', 2);
        $count = $this->countTextMatches($snapshot, ['cancelled', 'canceled', 'cancellation']);

        if ($count < $threshold) {
            return null;
        }

        return new CaseReasoningFinding(
            key: 'repeated_cancellations',
            title: 'Repeated cancellations',
            category: 'appointment',
            severity: AIRiskLevel::Medium,
            explanation: sprintf('Case shows %d cancellation signal(s), indicating unstable scheduling.', $count),
            signals: ['cancellation_count' => $count, 'threshold' => $threshold],
            evidenceRefs: ['appointment'],
        );
    }

    private function detectLongInactivity(CaseIntelligenceSnapshot $snapshot): ?CaseReasoningFinding
    {
        $threshold = $this->threshold('long_inactivity_days', 5);
        $lastActivityAt = $this->lastActivityAt($snapshot);

        if ($lastActivityAt === null) {
            return null;
        }

        $days = $this->daysSince($lastActivityAt, $snapshot->generatedAt);
        if ($days < $threshold) {
            return null;
        }

        return new CaseReasoningFinding(
            key: 'long_inactivity',
            title: 'Long inactivity',
            category: 'inactivity',
            severity: $days >= ($threshold * 2) ? AIRiskLevel::High : AIRiskLevel::Medium,
            explanation: sprintf('No meaningful case activity for %d day(s) (threshold %d).', $days, $threshold),
            signals: [
                'inactive_days' => $days,
                'threshold_days' => $threshold,
                'last_activity_at' => $lastActivityAt->toIso8601String(),
            ],
            evidenceRefs: ['timeline'],
        );
    }

    private function detectSlaLikelyToBreach(CaseIntelligenceSnapshot $snapshot): ?CaseReasoningFinding
    {
        $sla = strtolower($snapshot->slaStatus);
        $opsSla = strtolower($snapshot->context->operationalIntelligence->slaState);
        $isWarning = Str::contains($sla, ['warning', 'at_risk', 'risk'])
            || Str::contains($opsSla, ['warning', 'risk', 'breach']);
        $isOverdue = Str::contains($sla, ['overdue', 'breach', 'breached'])
            || Str::contains($opsSla, ['overdue', 'breach', 'breached']);

        if (! $isWarning && ! $isOverdue) {
            return null;
        }

        return new CaseReasoningFinding(
            key: 'sla_likely_to_breach',
            title: $isOverdue ? 'SLA breached' : 'SLA likely to breach',
            category: 'sla',
            severity: $isOverdue ? AIRiskLevel::High : AIRiskLevel::Medium,
            explanation: $isOverdue
                ? 'Case SLA is already overdue/breached and needs immediate intervention.'
                : 'Case SLA is in warning/risk state and is likely to breach without action.',
            signals: [
                'sla_status' => $snapshot->slaStatus,
                'operational_sla_state' => $snapshot->context->operationalIntelligence->slaState,
            ],
            evidenceRefs: ['sla'],
        );
    }

    private function detectCaseIdle(CaseIntelligenceSnapshot $snapshot): ?CaseReasoningFinding
    {
        $threshold = $this->threshold('case_idle_days', 3);
        $lastActivityAt = $this->lastActivityAt($snapshot);
        if ($lastActivityAt === null) {
            return null;
        }

        $days = $this->daysSince($lastActivityAt, $snapshot->generatedAt);
        if ($days < $threshold) {
            return null;
        }

        // Idle is about stalled ownership while not already classified as long inactivity-only;
        // still emit when case has open blockers/waiting and no recent movement.
        if (! $snapshot->isWaiting && $snapshot->blockers === [] && $snapshot->openQuestions === []) {
            return null;
        }

        return new CaseReasoningFinding(
            key: 'case_idle',
            title: 'Case idle',
            category: 'inactivity',
            severity: AIRiskLevel::Medium,
            explanation: sprintf(
                'Case appears idle for %d day(s) while still open with unresolved waiting/blockers.',
                $days,
            ),
            signals: [
                'idle_days' => $days,
                'threshold_days' => $threshold,
                'is_waiting' => $snapshot->isWaiting,
                'blocker_count' => count($snapshot->blockers),
            ],
            evidenceRefs: ['timeline', 'waiting'],
        );
    }

    private function detectMissingMandatoryInformation(CaseIntelligenceSnapshot $snapshot): ?CaseReasoningFinding
    {
        // Structured fields only — never open questions or free-text inference (Q4).
        $missing = [];

        if ($snapshot->serialMissing) {
            $missing[] = 'device serial number';
        }

        $phone = trim((string) ($snapshot->context->customerPhone ?? ''));
        if ($phone === '') {
            $missing[] = 'customer phone';
        }

        $mandatoryBlockerKeys = [
            'serial_missing' => 'device serial number',
            'missing_customer_phone' => 'customer phone',
            'missing_address' => 'customer address',
            'incomplete_identity' => 'required identity fields',
        ];

        foreach ($snapshot->blockers as $blocker) {
            if (isset($mandatoryBlockerKeys[$blocker->key])) {
                $missing[] = $mandatoryBlockerKeys[$blocker->key];
            }
        }

        $missing = array_values(array_unique($missing));
        if ($missing === []) {
            return null;
        }

        return new CaseReasoningFinding(
            key: 'missing_mandatory_information',
            title: 'Missing mandatory information',
            category: 'blocker',
            severity: AIRiskLevel::High,
            explanation: 'Mandatory information still missing: '.implode('; ', $missing).'.',
            signals: ['missing_items' => $missing],
            evidenceRefs: ['data_quality'],
        );
    }

    private function detectContactedWithoutProgress(CaseIntelligenceSnapshot $snapshot): ?CaseReasoningFinding
    {
        $threshold = $this->threshold('contact_without_progress_threshold', 3);
        $outbound = $this->timelineEvents($snapshot)
            ->filter(fn (TimelineEvent $event): bool => in_array($event->type, [
                TimelineEventType::WhatsApp,
                TimelineEventType::WhatsAppTemplateSent,
                TimelineEventType::Email,
                TimelineEventType::Notification,
                TimelineEventType::IvrCall,
            ], true))
            ->count();

        $activityOutbound = collect($snapshot->context->recentActivities)
            ->filter(function (array $activity): bool {
                $blob = strtolower(($activity['type'] ?? '').' '.($activity['title'] ?? ''));

                return Str::contains($blob, ['whatsapp', 'email', 'sms', 'call', 'notification', 'reminder']);
            })
            ->count();

        $count = max($outbound, $activityOutbound);
        if ($count < $threshold) {
            return null;
        }

        $stalled = $snapshot->isWaiting
            || $snapshot->blockers !== []
            || $snapshot->serialMissing
            || in_array(strtolower($snapshot->slaStatus), ['warning', 'overdue'], true);

        if (! $stalled) {
            return null;
        }

        return new CaseReasoningFinding(
            key: 'contacted_many_times_without_progress',
            title: 'Customer contacted many times without progress',
            category: 'communication',
            severity: AIRiskLevel::High,
            explanation: sprintf(
                'Customer was contacted %d time(s) while the case remains blocked/waiting without progress.',
                $count,
            ),
            signals: [
                'outbound_contact_count' => $count,
                'threshold' => $threshold,
                'is_waiting' => $snapshot->isWaiting,
                'blocker_count' => count($snapshot->blockers),
            ],
            evidenceRefs: ['communication'],
        );
    }

    private function detectAutomationStalled(CaseIntelligenceSnapshot $snapshot): ?CaseReasoningFinding
    {
        $threshold = $this->threshold('automation_failure_threshold', 2);
        $history = $snapshot->context->automationHistory !== []
            ? $snapshot->context->automationHistory
            : $snapshot->context->operationalIntelligence->automationHistory;

        // Exact AutomationExecutionStatus values only — no substring matching (Q1).
        $failed = collect($history)
            ->filter(function (array $row): bool {
                $status = strtolower(trim((string) ($row['status'] ?? '')));

                return $status === AutomationExecutionStatus::Failed->value;
            })
            ->count();

        if ($failed < $threshold) {
            return null;
        }

        return new CaseReasoningFinding(
            key: 'automation_stalled',
            title: 'Automation stalled',
            category: 'automation',
            severity: AIRiskLevel::High,
            explanation: sprintf(
                'Automation shows %d failed execution(s); workflow may be stuck.',
                $failed,
            ),
            signals: [
                'failed_runs' => $failed,
                'threshold' => $threshold,
                'matched_status' => AutomationExecutionStatus::Failed->value,
            ],
            evidenceRefs: ['automation'],
        );
    }

    private function detectPremiumCustomerAtRisk(CaseIntelligenceSnapshot $snapshot): ?CaseReasoningFinding
    {
        if (! $snapshot->context->customerIntelligence->isPremiumCustomer) {
            return null;
        }

        $elevated = $snapshot->blockers !== []
            || in_array(strtolower($snapshot->slaStatus), ['warning', 'overdue'], true)
            || $snapshot->priorityLevel === 'critical'
            || ($snapshot->isWaiting && $snapshot->waitingSince !== null
                && $this->daysSince($snapshot->waitingSince, $snapshot->generatedAt) >= $this->threshold('waiting_too_long_days', 3));

        if (! $elevated) {
            return null;
        }

        return new CaseReasoningFinding(
            key: 'premium_customer_at_risk',
            title: 'Premium customer at risk',
            category: 'risk',
            severity: AIRiskLevel::High,
            explanation: 'Premium customer has elevated friction (waiting, blockers, or SLA pressure).',
            signals: [
                'is_premium' => true,
                'priority_level' => $snapshot->priorityLevel,
                'sla_status' => $snapshot->slaStatus,
            ],
            evidenceRefs: ['customer'],
        );
    }

    private function detectHighPriorityUnattended(CaseIntelligenceSnapshot $snapshot): ?CaseReasoningFinding
    {
        $isHighPriority = $snapshot->context->highPriority
            || in_array($snapshot->priorityLevel, ['high', 'critical'], true);

        if (! $isHighPriority) {
            return null;
        }

        $lastActivityAt = $this->lastActivityAt($snapshot);
        if ($lastActivityAt === null) {
            return null;
        }

        $idleDays = $this->daysSince($lastActivityAt, $snapshot->generatedAt);
        if ($idleDays < 1) {
            return null;
        }

        return new CaseReasoningFinding(
            key: 'high_priority_unattended',
            title: 'High-priority case unattended',
            category: 'risk',
            severity: AIRiskLevel::High,
            explanation: sprintf('High-priority case has had no activity for %d day(s).', $idleDays),
            signals: [
                'high_priority' => $snapshot->context->highPriority,
                'priority_level' => $snapshot->priorityLevel,
                'idle_days' => $idleDays,
                'last_activity_at' => $lastActivityAt->toIso8601String(),
            ],
            evidenceRefs: ['priority'],
        );
    }

    private function detectWaitingOnInternalTooLong(CaseIntelligenceSnapshot $snapshot): ?CaseReasoningFinding
    {
        if (! $snapshot->isWaiting || ! in_array($snapshot->waitingParty, ['engineer', 'internal', 'agent', 'ops'], true)) {
            return null;
        }

        if ($snapshot->waitingSince === null) {
            return null;
        }

        $threshold = max(1, (int) floor($this->threshold('waiting_too_long_days', 3) / 2));
        $days = $this->daysSince($snapshot->waitingSince, $snapshot->generatedAt);
        if ($days < $threshold) {
            return null;
        }

        return new CaseReasoningFinding(
            key: 'waiting_on_internal_too_long',
            title: 'Waiting on internal party too long',
            category: 'waiting',
            severity: AIRiskLevel::High,
            explanation: sprintf(
                'Internal/engineer wait has lasted %d day(s); ownership follow-through is required.',
                $days,
            ),
            signals: [
                'waiting_party' => $snapshot->waitingParty,
                'waiting_days' => $days,
                'threshold_days' => $threshold,
            ],
            evidenceRefs: ['waiting'],
        );
    }

    /**
     * @param  list<CaseReasoningFinding>  $findings
     */
    private function findingForRisk(array $findings, CaseIntelligenceRisk $risk): ?CaseReasoningFinding
    {
        foreach ($findings as $finding) {
            if ($finding->key === $risk->key || Str::contains($finding->key, Str::before($risk->key, '_risk') ?: $risk->key)) {
                return $finding;
            }
        }

        $map = [
            'sla' => 'sla_likely_to_breach',
            'data_quality' => 'missing_mandatory_information',
            'repeat' => 'repeated_repairs',
            'payment' => 'payment_overdue',
            'appointment' => 'appointment_overdue',
            'automation' => 'automation_stalled',
        ];

        foreach ($map as $needle => $ruleKey) {
            if (Str::contains($risk->key, $needle) || Str::contains($risk->category, $needle)) {
                foreach ($findings as $finding) {
                    if ($finding->key === $ruleKey) {
                        return $finding;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  list<CaseReasoningFinding>  $findings
     */
    private function findingForBlocker(array $findings, CaseIntelligenceBlocker $blocker): ?CaseReasoningFinding
    {
        foreach ($findings as $finding) {
            if ($finding->key === $blocker->key
                || ($blocker->key === 'serial_missing' && $finding->key === 'serial_pending_too_long')
                || ($blocker->key === 'serial_missing' && $finding->key === 'missing_mandatory_information')
                || ($blocker->key === 'appointment_overdue' && $finding->key === 'appointment_overdue')) {
                return $finding;
            }
        }

        return null;
    }

    private function defaultRiskExplanation(CaseIntelligenceRisk $risk): string
    {
        return $risk->label.' is active at '.$risk->severity->value.' severity.';
    }

    private function defaultBlockerExplanation(CaseIntelligenceBlocker $blocker): string
    {
        $since = $blocker->since ? ' since '.$blocker->since->toDateString() : '';

        return $blocker->label.' (party: '.$blocker->party.')'.$since.'.';
    }

    /**
     * @param  list<CaseReasoningFinding>  $findings
     * @return list<CaseReasoningFinding>
     */
    private function sortedBySeverity(array $findings): array
    {
        $rank = [
            AIRiskLevel::High->value => 3,
            AIRiskLevel::Medium->value => 2,
            AIRiskLevel::Low->value => 1,
        ];

        usort($findings, function (CaseReasoningFinding $a, CaseReasoningFinding $b) use ($rank): int {
            return ($rank[$b->severity->value] ?? 0) <=> ($rank[$a->severity->value] ?? 0);
        });

        return $findings;
    }

    /**
     * @return Collection<int, TimelineEvent>
     */
    private function timelineEvents(CaseIntelligenceSnapshot $snapshot): Collection
    {
        if ($snapshot->timeline === null) {
            return collect();
        }

        return $snapshot->timeline->events();
    }

    /**
     * @param  list<string>  $needles
     */
    private function countTextMatches(CaseIntelligenceSnapshot $snapshot, array $needles): int
    {
        $count = 0;

        foreach ($this->timelineEvents($snapshot) as $event) {
            $blob = strtolower($event->title.' '.($event->summary ?? '').' '.($event->detail ?? '').' '.($event->statusLabel ?? ''));
            foreach ($needles as $needle) {
                if (Str::contains($blob, strtolower($needle))) {
                    $count++;
                    break;
                }
            }
        }

        foreach ($snapshot->context->recentActivities as $activity) {
            $blob = strtolower(($activity['title'] ?? '').' '.($activity['type'] ?? ''));
            foreach ($needles as $needle) {
                if (Str::contains($blob, strtolower($needle))) {
                    $count++;
                    break;
                }
            }
        }

        foreach ($snapshot->context->automationHistory as $row) {
            $blob = strtolower(($row['policy_key'] ?? '').' '.($row['action_type'] ?? '').' '.($row['status'] ?? ''));
            foreach ($needles as $needle) {
                if (Str::contains($blob, strtolower($needle))) {
                    $count++;
                    break;
                }
            }
        }

        return $count;
    }

    private function lastActivityAt(CaseIntelligenceSnapshot $snapshot): ?Carbon
    {
        $candidates = [];

        $latestTimeline = $this->timelineEvents($snapshot)
            ->map(fn (TimelineEvent $event): Carbon => $event->occurredAt)
            ->sortByDesc(fn (Carbon $at): int => $at->timestamp)
            ->first();
        if ($latestTimeline instanceof Carbon) {
            $candidates[] = $latestTimeline;
        }

        foreach ($snapshot->context->recentActivities as $activity) {
            $at = $this->carbonOrNull($activity['occurred_at'] ?? null);
            if ($at !== null) {
                $candidates[] = $at;
            }
        }

        if ($snapshot->context->customerIntelligence->lastInteractionAt !== null) {
            $candidates[] = $snapshot->context->customerIntelligence->lastInteractionAt;
        }

        // Structured fallbacks when timeline/activity history is empty (Q7).
        if ($snapshot->incidentUpdatedAt !== null) {
            $candidates[] = $snapshot->incidentUpdatedAt;
        }
        if ($snapshot->incidentCreatedAt !== null) {
            $candidates[] = $snapshot->incidentCreatedAt;
        }
        if ($snapshot->waitingSince !== null) {
            $candidates[] = $snapshot->waitingSince;
        }

        if ($candidates === []) {
            return null;
        }

        return collect($candidates)->sortByDesc(fn (Carbon $c) => $c->timestamp)->first();
    }

    private function daysSince(Carbon $from, Carbon $to): int
    {
        return (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay());
    }

    private function carbonOrNull(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance(\DateTimeImmutable::createFromInterface($value));
        }

        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function hasBlockerKey(CaseIntelligenceSnapshot $snapshot, string $key): bool
    {
        foreach ($snapshot->blockers as $blocker) {
            if ($blocker->key === $key) {
                return true;
            }
        }

        return false;
    }

    private function threshold(string $key, int $default): int
    {
        return max(1, (int) config('ira.case_reasoning.'.$key, $default));
    }
}

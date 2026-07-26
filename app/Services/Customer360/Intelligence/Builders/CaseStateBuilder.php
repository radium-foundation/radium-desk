<?php

namespace App\Services\Customer360\Intelligence\Builders;

use App\Data\AI\AIIncidentBundle;
use App\Data\Customer360\Intelligence\CaseIntelligenceBlocker;
use App\Data\Customer360\Intelligence\CaseIntelligenceFacts;
use App\Enums\ServiceCaseSlaStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Projects current status, waiting party, and blockers from domain facts.
 */
class CaseStateBuilder
{
    /**
     * @return array{
     *     current_status_code: string,
     *     current_status_label: string,
     *     sla_status: string,
     *     is_waiting: bool,
     *     waiting_party: string,
     *     waiting_reason_code: ?string,
     *     waiting_reason_label: ?string,
     *     waiting_since: ?Carbon,
     *     blockers: list<CaseIntelligenceBlocker>,
     *     open_questions: list<string>,
     *     priority_level: string,
     *     priority_drivers: list<string>,
     * }
     */
    public function build(CaseIntelligenceFacts $facts, AIIncidentBundle $bundle): array
    {
        $incident = $facts->incident;
        $waiting = $facts->waitingStateCard;
        $isWaiting = is_array($waiting);
        $slaStatus = $incident->slaStatus();
        $appointment = $facts->supportAppointment;

        [$statusCode, $statusLabel] = $this->resolveStatus($incident, $isWaiting, $appointment, $slaStatus, $bundle);
        $waitingParty = $isWaiting ? 'customer' : 'none';
        $waitingReasonLabel = is_array($waiting) ? ($waiting['reason_label'] ?? null) : null;
        $waitingReasonCode = $incident->activeWaitingState?->waiting_reason?->value;
        $waitingSince = is_array($waiting) && ($waiting['customer_waiting_since'] ?? null) instanceof Carbon
            ? $waiting['customer_waiting_since']
            : (is_array($waiting) && ($waiting['started_at'] ?? null) instanceof Carbon
                ? $waiting['started_at']
                : null);

        $blockers = $this->buildBlockers($facts, $bundle, $isWaiting, $waitingReasonLabel, $waitingSince);
        [$priorityLevel, $priorityDrivers] = $this->resolvePriority($incident, $slaStatus, $bundle, $blockers);

        return [
            'current_status_code' => $statusCode,
            'current_status_label' => $statusLabel,
            'sla_status' => $slaStatus->value,
            'is_waiting' => $isWaiting,
            'waiting_party' => $waitingParty,
            'waiting_reason_code' => $waitingReasonCode,
            'waiting_reason_label' => is_string($waitingReasonLabel) ? $waitingReasonLabel : null,
            'waiting_since' => $waitingSince,
            'blockers' => $blockers,
            'open_questions' => $this->openQuestions($facts, $bundle, $blockers),
            'priority_level' => $priorityLevel,
            'priority_drivers' => $priorityDrivers,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $appointment
     * @return array{0: string, 1: string}
     */
    private function resolveStatus(
        \App\Models\Incident $incident,
        bool $isWaiting,
        ?array $appointment,
        ServiceCaseSlaStatus $slaStatus,
        AIIncidentBundle $bundle,
    ): array {
        if (! $incident->isActive()) {
            return ['closed', $incident->status->label()];
        }

        if ($isWaiting) {
            return ['waiting_customer', 'Waiting for customer'];
        }

        if (is_array($appointment) && ($appointment['is_active'] ?? false)) {
            return ['scheduled', 'Support appointment scheduled'];
        }

        if ($slaStatus === ServiceCaseSlaStatus::Overdue) {
            return ['sla_overdue', 'SLA overdue'];
        }

        if ($bundle->context->serialMissing) {
            return ['blocked_serial', 'Blocked — serial required'];
        }

        if ($slaStatus === ServiceCaseSlaStatus::Warning) {
            return ['sla_warning', 'SLA warning'];
        }

        return ['in_progress', $incident->status->label()];
    }

    /**
     * @return list<CaseIntelligenceBlocker>
     */
    private function buildBlockers(
        CaseIntelligenceFacts $facts,
        AIIncidentBundle $bundle,
        bool $isWaiting,
        ?string $waitingReasonLabel,
        ?Carbon $waitingSince,
    ): array {
        $blockers = [];

        if ($bundle->context->serialMissing) {
            $blockers[] = new CaseIntelligenceBlocker(
                key: 'serial_missing',
                label: 'Device serial number is missing',
                party: 'customer',
                severity: 'high',
                since: $waitingSince,
                evidenceRefs: ['serial'],
                clearsWhen: 'Customer provides a valid serial number',
            );
        }

        if ($isWaiting) {
            $reason = $waitingReasonLabel ?? 'customer input';
            $blockers[] = new CaseIntelligenceBlocker(
                key: 'waiting_customer',
                label: 'Waiting for '.$reason,
                party: 'customer',
                severity: 'medium',
                since: $waitingSince,
                evidenceRefs: ['waiting_state'],
                clearsWhen: 'Customer supplies the requested information',
            );
        }

        $appointment = $facts->supportAppointment;
        if (is_array($appointment)
            && isset($appointment['status'])
            && (string) ($appointment['status']->value ?? $appointment['status']) === 'missed') {
            $blockers[] = new CaseIntelligenceBlocker(
                key: 'missed_appointment',
                label: 'Customer missed a scheduled support appointment',
                party: 'customer',
                severity: 'medium',
                evidenceRefs: ['appointment'],
                clearsWhen: 'Support appointment is rescheduled',
            );
        }

        return $blockers;
    }

    /**
     * @param  list<CaseIntelligenceBlocker>  $blockers
     * @return array{0: string, 1: list<string>}
     */
    private function resolvePriority(
        \App\Models\Incident $incident,
        ServiceCaseSlaStatus $slaStatus,
        AIIncidentBundle $bundle,
        array $blockers,
    ): array {
        $drivers = [];

        if ($slaStatus === ServiceCaseSlaStatus::Overdue
            || ($incident->high_priority && $slaStatus === ServiceCaseSlaStatus::Warning)) {
            $drivers[] = 'SLA risk';
        }

        if ($incident->high_priority) {
            $drivers[] = 'High priority flag';
        }

        if ($bundle->context->operationalIntelligence->repeatContactHighUrgency) {
            $drivers[] = 'Repeat customer contact';
        }

        if ($blockers !== []) {
            $drivers[] = 'Active blockers';
        }

        $level = match (true) {
            $slaStatus === ServiceCaseSlaStatus::Overdue => 'critical',
            $incident->high_priority || $bundle->context->operationalIntelligence->repeatContactHighUrgency => 'high',
            $blockers !== [] || $slaStatus === ServiceCaseSlaStatus::Warning => 'normal',
            default => 'low',
        };

        return [$level, $drivers];
    }

    /**
     * @param  list<CaseIntelligenceBlocker>  $blockers
     * @return list<string>
     */
    private function openQuestions(
        CaseIntelligenceFacts $facts,
        AIIncidentBundle $bundle,
        array $blockers,
    ): array {
        $questions = [];

        if ($bundle->context->serialMissing) {
            $questions[] = 'What is the device serial number?';
        }

        $warranty = Str::lower($bundle->context->warrantyStatus);
        if (Str::contains($warranty, ['not available', 'unknown', 'unavailable', 'pending'])) {
            $questions[] = 'Can warranty coverage be verified?';
        }

        if ($facts->incident->assigned_to_user_id === null && $facts->incident->isActive()) {
            $questions[] = 'Who should own the next customer contact?';
        }

        foreach ($blockers as $blocker) {
            if ($blocker->key === 'waiting_customer' && filled($blocker->label)) {
                $questions[] = 'Has the customer been reminded about: '.$blocker->label.'?';
            }
        }

        return array_values(array_unique($questions));
    }
}

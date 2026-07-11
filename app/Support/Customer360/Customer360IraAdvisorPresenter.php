<?php

namespace App\Support\Customer360;

use App\Data\AI\CustomerJourneyDTO;
use App\Data\Customer360\Customer360SlaMetrics;
use App\Data\Operations\OperationsInsightDTO;
use App\Enums\AI\CustomerJourneyConclusionType;
use App\Enums\ServiceCaseSlaStatus;
use App\Enums\SupportAppointmentStatus;
use App\Models\Incident;
use App\Models\Order;
use Illuminate\Support\Str;

class Customer360IraAdvisorPresenter
{
    private const MAX_SECONDARY_ACTIONS = 3;

    /**
     * @var array<string, array{label: string, icon: string}>
     */
    private const ACTIONS = [
        'schedule_appointment' => [
            'label' => 'Schedule Appointment',
            'icon' => 'bi-calendar-plus',
        ],
        'request_serial' => [
            'label' => 'Request Serial',
            'icon' => 'bi-upc-scan',
        ],
        'contact_customer' => [
            'label' => 'Contact Customer',
            'icon' => 'bi-telephone',
        ],
        'wait' => [
            'label' => 'Wait',
            'icon' => 'bi-hourglass-split',
        ],
        'escalate' => [
            'label' => 'Escalate',
            'icon' => 'bi-arrow-up-circle',
        ],
        'verify_identity' => [
            'label' => 'Verify Identity',
            'icon' => 'bi-person-badge',
        ],
    ];

    /**
     * @param  array{
     *     incident: Incident,
     *     order: Order,
     *     customerSummary: array<string, int>,
     *     healthCardViewModel: array<string, mixed>,
     *     waitingStateCard: ?array<string, mixed>,
     *     supportAppointment: ?array<string, mixed>,
     *     customerJourney: CustomerJourneyDTO,
     *     slaMetrics: ?Customer360SlaMetrics,
     *     operationsAdvisorInsights: list<OperationsInsightDTO>,
     *     actionVisibility: array<string, bool>,
     *     canEscalate: bool,
     * }  $context
     * @return array<string, mixed>|null
     */
    public function present(array $context): ?array
    {
        $incident = $context['incident'];
        $order = $context['order'];

        if (! $incident->isActive()) {
            return null;
        }

        $candidates = array_values(array_filter([
            $this->evaluateActiveWaitingState($context),
            $this->evaluateSerialMissing($context),
            $this->evaluateSerialVerification($context),
            $this->evaluateSlaOverdue($context),
            $this->evaluateCancelledAppointment($context),
            $this->evaluateRepeatIssue($context),
            $this->evaluateEscalationRisk($context),
            $this->evaluateMissedAppointments($context),
            $this->evaluateScheduleAppointment($context),
            $this->evaluateActiveAppointment($context),
            $this->evaluateJourneyBlocked($context),
            $this->evaluateDefaultContact($context),
        ]));

        if ($candidates === []) {
            return null;
        }

        usort(
            $candidates,
            fn (array $left, array $right): int => $right['priority'] <=> $left['priority'],
        );

        $selected = $candidates[0];
        $primaryKey = $selected['action_key'];

        return [
            'recommended_action' => $this->action($primaryKey),
            'confidence' => [
                'level' => $selected['confidence'],
                'label' => Str::ucfirst($selected['confidence']),
            ],
            'reasons' => $selected['reasons'],
            'secondary_actions' => $this->secondaryActions($context, $primaryKey),
            'rule_context' => [
                'matched_rule' => $selected['rule_id'],
                'priority' => $selected['priority'],
                'signals' => $selected['signals'],
                'incident_id' => $incident->id,
                'order_id' => $order->id,
                'journey_conclusion' => $context['customerJourney']->conclusion->type->value,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function evaluateActiveWaitingState(array $context): ?array
    {
        $waitingState = $context['waitingStateCard'] ?? null;
        $visibility = $context['actionVisibility'] ?? [];

        if (! ($visibility['isWaitingForCustomer'] ?? false) && ! is_array($waitingState)) {
            return null;
        }

        $reasons = [];
        $signals = ['waiting_state_active' => true];

        if (is_array($waitingState)) {
            $reasonLabel = (string) ($waitingState['reason_label'] ?? 'customer input');

            $reasons[] = 'Customer is waiting to provide '.$reasonLabel.'.';
            $signals['waiting_reason'] = $reasonLabel;

            if (filled($waitingState['waiting_duration_label'] ?? null)) {
                $reasons[] = 'Waiting for '.$waitingState['waiting_duration_label'].'.';
                $signals['waiting_duration'] = $waitingState['waiting_duration_label'];
            }

            if ((bool) ($waitingState['sla_paused'] ?? false)) {
                $reasons[] = 'SLA is paused while waiting for the customer.';
                $signals['sla_paused'] = true;
            }
        } else {
            $reasons[] = 'Case is in a waiting-for-customer state.';
        }

        return $this->candidate(
            actionKey: 'wait',
            confidence: 'high',
            priority: 100,
            reasons: $reasons,
            ruleId: 'active_waiting_state',
            signals: $signals,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function evaluateSerialMissing(array $context): ?array
    {
        if (! ($context['actionVisibility']['canRequestSerialNumber'] ?? false)) {
            return null;
        }

        return $this->candidate(
            actionKey: 'request_serial',
            confidence: 'high',
            priority: 95,
            reasons: [
                'Device serial number is missing or pending validation.',
                'Service case cannot progress until the serial is confirmed.',
            ],
            ruleId: 'serial_missing',
            signals: [
                'can_request_serial_number' => true,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function evaluateSerialVerification(array $context): ?array
    {
        $visibility = $context['actionVisibility'] ?? [];
        $needsVerification = ($visibility['canRequestCorrectSerial'] ?? false)
            || ($visibility['canCorrectSerialNumber'] ?? false)
            || ($visibility['canCorrectCustomerDetails'] ?? false);

        if (! $needsVerification) {
            return null;
        }

        $reasons = ['Customer identity or serial details need verification before closing.'];
        $signals = [];

        if ($visibility['canRequestCorrectSerial'] ?? false) {
            $reasons[] = 'Serial insight flagged the current number for customer confirmation.';
            $signals['can_request_correct_serial'] = true;
        }

        if ($visibility['canCorrectCustomerDetails'] ?? false) {
            $signals['can_correct_customer_details'] = true;
        }

        if ($visibility['canCorrectSerialNumber'] ?? false) {
            $signals['can_correct_serial_number'] = true;
        }

        return $this->candidate(
            actionKey: 'verify_identity',
            confidence: 'high',
            priority: 90,
            reasons: $reasons,
            ruleId: 'identity_verification_required',
            signals: $signals,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function evaluateSlaOverdue(array $context): ?array
    {
        $incident = $context['incident'];
        $slaStatus = $incident->slaStatus();
        $canEscalate = (bool) ($context['canEscalate'] ?? false);

        if ($slaStatus !== ServiceCaseSlaStatus::Overdue
            && ! ($incident->high_priority && $slaStatus === ServiceCaseSlaStatus::Warning)) {
            return null;
        }

        $actionKey = $canEscalate ? 'escalate' : 'contact_customer';
        $reasons = [
            'Case SLA status is '.$slaStatus->label().'.',
        ];

        if ($incident->high_priority) {
            $reasons[] = 'Case is marked high priority.';
        }

        if ($incident->created_at !== null) {
            $pendingHours = (int) $incident->created_at->diffInHours(now());
            $reasons[] = 'Case has been pending for '.$pendingHours.' hours.';
        }

        return $this->candidate(
            actionKey: $actionKey,
            confidence: 'high',
            priority: 80,
            reasons: $reasons,
            ruleId: 'sla_risk',
            signals: [
                'sla_status' => $slaStatus->value,
                'high_priority' => (bool) $incident->high_priority,
                'can_escalate' => $canEscalate,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function evaluateCancelledAppointment(array $context): ?array
    {
        $journey = $context['customerJourney'];
        $appointment = $context['supportAppointment'] ?? null;

        $isInterrupted = $journey->conclusion->type === CustomerJourneyConclusionType::Interrupted;
        $isCancelled = is_array($appointment)
            && ($appointment['status'] ?? null) === SupportAppointmentStatus::Cancelled;

        if (! $isInterrupted && ! $isCancelled) {
            return null;
        }

        return $this->candidate(
            actionKey: 'schedule_appointment',
            confidence: 'high',
            priority: 70,
            reasons: array_values(array_filter([
                $isInterrupted ? 'Customer journey was interrupted after a cancelled appointment.' : null,
                $isCancelled ? 'The previous support appointment was cancelled.' : null,
                'Rebooking support will restore service momentum.',
            ])),
            ruleId: 'cancelled_appointment',
            signals: [
                'journey_interrupted' => $isInterrupted,
                'appointment_cancelled' => $isCancelled,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function evaluateRepeatIssue(array $context): ?array
    {
        $insight = $this->findAdvisorInsight($context, 'Repeat Failure Risk');

        if ($insight === null) {
            return null;
        }

        $summary = (string) ($insight->supportingMetrics['repeat_issue_summary'] ?? '');

        return $this->candidate(
            actionKey: 'contact_customer',
            confidence: 'high',
            priority: 65,
            reasons: array_values(array_filter([
                'Repeat failure pattern detected for this customer.',
                filled($summary) ? $summary : null,
                'Review prior repair history before repeating the same fix path.',
            ])),
            ruleId: 'repeat_issue',
            signals: [
                'advisor_insight' => $insight->title,
                'repeat_failure_percent' => $insight->supportingMetrics['repeat_failure_percent'] ?? null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function evaluateEscalationRisk(array $context): ?array
    {
        $insight = $this->findAdvisorInsight($context, 'Escalation Risk');

        if ($insight === null) {
            return null;
        }

        $canEscalate = (bool) ($context['canEscalate'] ?? false);
        $actionKey = $canEscalate ? 'escalate' : 'contact_customer';

        return $this->candidate(
            actionKey: $actionKey,
            confidence: 'medium',
            priority: 60,
            reasons: array_values(array_filter([
                'Escalation risk detected for this case.',
                filled($insight->recommendation) ? $insight->recommendation : null,
            ])),
            ruleId: 'escalation_risk',
            signals: [
                'advisor_insight' => $insight->title,
                'can_escalate' => $canEscalate,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function evaluateMissedAppointments(array $context): ?array
    {
        $missedAppointments = (int) ($context['healthCardViewModel']['missed_appointments'] ?? 0);

        if ($missedAppointments <= 0) {
            return null;
        }

        return $this->candidate(
            actionKey: 'contact_customer',
            confidence: 'medium',
            priority: 55,
            reasons: [
                number_format($missedAppointments).' missed appointment'.($missedAppointments === 1 ? '' : 's').' on record.',
                'Proactive outreach can re-establish appointment attendance.',
            ],
            ruleId: 'missed_appointments',
            signals: [
                'missed_appointments' => $missedAppointments,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function evaluateScheduleAppointment(array $context): ?array
    {
        $appointment = $context['supportAppointment'] ?? null;
        $hasActiveAppointment = is_array($appointment) && ($appointment['is_active'] ?? false);

        if ($hasActiveAppointment || ($context['actionVisibility']['isWaitingForCustomer'] ?? false)) {
            return null;
        }

        $reasons = ['No active support appointment is scheduled for this case.'];

        if (is_array($appointment) && ($appointment['status'] ?? null) === SupportAppointmentStatus::Completed) {
            $reasons[] = 'Previous support visit is complete; schedule follow-up if needed.';
        }

        return $this->candidate(
            actionKey: 'schedule_appointment',
            confidence: 'medium',
            priority: 50,
            reasons: $reasons,
            ruleId: 'no_active_appointment',
            signals: [
                'has_active_appointment' => false,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function evaluateActiveAppointment(array $context): ?array
    {
        $appointment = $context['supportAppointment'] ?? null;

        if (! is_array($appointment) || ! ($appointment['is_active'] ?? false)) {
            return null;
        }

        $reasons = ['Support appointment is scheduled and awaiting execution.'];
        $signals = ['appointment_active' => true];

        if (filled($appointment['preferred_date'] ?? null)) {
            $dateLabel = $appointment['preferred_date'] instanceof \Illuminate\Support\Carbon
                ? $appointment['preferred_date']->format('M j, Y')
                : (string) $appointment['preferred_date'];
            $reasons[] = 'Scheduled for '.$dateLabel
                .(filled($appointment['time_slot_label'] ?? null) ? ' ('.$appointment['time_slot_label'].')' : '').'.';
            $signals['preferred_date'] = $dateLabel;
        }

        return $this->candidate(
            actionKey: 'wait',
            confidence: 'medium',
            priority: 45,
            reasons: $reasons,
            ruleId: 'active_appointment',
            signals: $signals,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function evaluateJourneyBlocked(array $context): ?array
    {
        $journey = $context['customerJourney'];

        if ($journey->conclusion->type !== CustomerJourneyConclusionType::Blocked) {
            return null;
        }

        return $this->candidate(
            actionKey: 'wait',
            confidence: 'high',
            priority: 40,
            reasons: array_values(array_filter([
                'Customer journey is blocked: '.$journey->conclusion->detail,
                filled($journey->conclusion->recommendation) ? $journey->conclusion->recommendation : null,
            ])),
            ruleId: 'journey_blocked',
            signals: [
                'journey_headline' => $journey->conclusion->headline,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function evaluateDefaultContact(array $context): ?array
    {
        $journey = $context['customerJourney'];
        $reasons = [filled($journey->conclusion->recommendation)
            ? $journey->conclusion->recommendation
            : 'Review incident details and contact the customer with the next update.'];

        if ($journey->conclusion->type === CustomerJourneyConclusionType::Reopened) {
            $reasons[] = 'Issue has reoccurred after previous support completion.';
        }

        $preferredChannel = $context['healthCardViewModel']['preferred_channel'] ?? null;

        if (filled($preferredChannel)) {
            $reasons[] = 'Preferred contact channel: '.$preferredChannel.'.';
        }

        return $this->candidate(
            actionKey: 'contact_customer',
            confidence: 'low',
            priority: 10,
            reasons: $reasons,
            ruleId: 'default_contact',
            signals: [
                'journey_conclusion' => $journey->conclusion->type->value,
                'preferred_channel' => $preferredChannel,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<array{key: string, label: string, icon: string}>
     */
    private function secondaryActions(array $context, string $primaryKey): array
    {
        $eligible = [];

        foreach ($this->eligibleActionKeys($context) as $actionKey) {
            if ($actionKey === $primaryKey) {
                continue;
            }

            $eligible[] = $this->action($actionKey);
        }

        return array_slice($eligible, 0, self::MAX_SECONDARY_ACTIONS);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<string>
     */
    private function eligibleActionKeys(array $context): array
    {
        $visibility = $context['actionVisibility'] ?? [];
        $appointment = $context['supportAppointment'] ?? null;
        $hasActiveAppointment = is_array($appointment) && ($appointment['is_active'] ?? false);
        $keys = [];

        if (! $hasActiveAppointment && ! ($visibility['isWaitingForCustomer'] ?? false)) {
            $keys[] = 'schedule_appointment';
        }

        if ($visibility['canRequestSerialNumber'] ?? false) {
            $keys[] = 'request_serial';
        }

        if (($visibility['canRequestCorrectSerial'] ?? false)
            || ($visibility['canCorrectCustomerDetails'] ?? false)
            || ($visibility['canCorrectSerialNumber'] ?? false)) {
            $keys[] = 'verify_identity';
        }

        if (($visibility['isWaitingForCustomer'] ?? false) || ($visibility['canCustomerNotResponding'] ?? false)) {
            $keys[] = 'wait';
        }

        if ($context['canEscalate'] ?? false) {
            $keys[] = 'escalate';
        }

        if (filled($context['order']->customer_phone ?? null)) {
            $keys[] = 'contact_customer';
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function findAdvisorInsight(array $context, string $title): ?OperationsInsightDTO
    {
        foreach ($context['operationsAdvisorInsights'] as $insight) {
            if ($insight instanceof OperationsInsightDTO && $insight->title === $title) {
                return $insight;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $signals
     * @param  list<string>  $reasons
     * @return array<string, mixed>
     */
    private function candidate(
        string $actionKey,
        string $confidence,
        int $priority,
        array $reasons,
        string $ruleId,
        array $signals,
    ): array {
        return [
            'action_key' => $actionKey,
            'confidence' => $confidence,
            'priority' => $priority,
            'reasons' => $reasons,
            'rule_id' => $ruleId,
            'signals' => $signals,
        ];
    }

    /**
     * @return array{key: string, label: string, icon: string}
     */
    private function action(string $key): array
    {
        $definition = self::ACTIONS[$key] ?? [
            'label' => Str::headline(str_replace('_', ' ', $key)),
            'icon' => 'bi-lightbulb',
        ];

        return [
            'key' => $key,
            'label' => $definition['label'],
            'icon' => $definition['icon'],
        ];
    }
}

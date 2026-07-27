<?php

namespace App\Services\Customer360\Intelligence\Builders;

use App\Data\AI\AIIncidentBundle;
use App\Data\AI\IRAExecutiveSummaryDTO;
use App\Data\Customer360\Intelligence\CaseIntelligenceFacts;
use App\Data\Customer360\Intelligence\CommunicationSummary;
use App\Data\Operations\OperationsInsightDTO;
use App\Data\SerialInsight;
use App\Data\TimelineEvent;
use App\Enums\SerialInsightStatus;
use App\Enums\ServiceCaseSlaStatus;
use App\Enums\TimelineEventType;
use App\Models\Incident;
use App\Models\Order;
use App\Services\SerialValidation\SerialInsightService;
use App\Support\DeviceModelFormatter;
use Illuminate\Support\Carbon;

/**
 * Builds the Customer 360 executive briefing as a natural operations narrative.
 * Sections: introduction, current situation, communication, internal progress,
 * business impact, next action (next action filled by canonical recommendation sync).
 */
class CaseSummaryBuilder
{
    public function __construct(
        private readonly SerialInsightService $serialInsightService,
    ) {}

    /**
     * @param  array{
     *     current_status_code: string,
     *     current_status_label: string,
     *     sla_status: string,
     *     is_waiting: bool,
     *     waiting_party: string,
     *     waiting_reason_code: ?string,
     *     waiting_reason_label: ?string,
     *     waiting_since: ?Carbon,
     *     blockers: list<\App\Data\Customer360\Intelligence\CaseIntelligenceBlocker>,
     *     priority_level: string,
     *     priority_drivers: list<string>,
     * }  $state
     * @param  list<OperationsInsightDTO>  $operationsAdvisorInsights
     */
    public function build(
        Incident $incident,
        AIIncidentBundle $bundle,
        CaseIntelligenceFacts $facts,
        array $state,
        CommunicationSummary $communication,
        array $operationsAdvisorInsights = [],
    ): IRAExecutiveSummaryDTO {
        $serialInsight = $this->resolveSerialInsight($incident);
        $context = $bundle->context;
        $model = DeviceModelFormatter::shortDisplay($context->deviceModel)
            ?: DeviceModelFormatter::shortDisplay($facts->order->product_name)
            ?: 'device';

        $serialMissing = $context->serialMissing
            || $serialInsight?->status === SerialInsightStatus::Missing;

        // Communication briefing is injected by the IRA panel presenter from
        // CommunicationSummary::briefingLines (chronological human bullets).
        $ownerName = trim((string) ($facts->incident->assignee?->name ?? ''));
        $sections = array_values(array_filter([
            $this->caseIntroduction($incident, $model, $state),
            $this->currentSituation($facts, $state, $serialInsight, $serialMissing, $ownerName),
            $this->internalProgress($facts, $state, $serialInsight, $serialMissing, $context->lastPayment, $ownerName),
            $this->communicationGap($communication),
            $this->businessImpact($incident, $state, $serialInsight),
            $this->nextExpectedAction($state, $serialInsight, $serialMissing, $communication),
        ]));

        if ($sections === []) {
            $sections[] = 'Review the current service case context before contacting the customer.';
        }

        return new IRAExecutiveSummaryDTO(
            executiveSummary: $sections,
            opinion: $this->buildOpinion($state, $serialInsight, $communication),
            recommendation: '',
            serialInsight: $serialInsight,
        );
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function caseIntroduction(Incident $incident, string $model, array $state): string
    {
        $priority = (string) ($state['priority_level'] ?? 'normal');
        $priorityPhrase = match ($priority) {
            'critical' => 'critical-priority',
            'high' => 'high-priority',
            default => null,
        };

        if ($incident->high_priority && $priorityPhrase === null) {
            $priorityPhrase = 'high-priority';
        }

        if ($priorityPhrase !== null) {
            return "This is a {$priorityPhrase} service case for {$model}.";
        }

        return "This is an open service case for {$model}.";
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function currentSituation(
        CaseIntelligenceFacts $facts,
        array $state,
        ?SerialInsight $serialInsight,
        bool $serialMissing,
        string $ownerName = '',
    ): string {
        $parts = [];

        if (($state['is_waiting'] ?? false) && filled($state['waiting_reason_label'] ?? null)) {
            $days = $this->waitingDays($state['waiting_since'] ?? null);
            $reason = (string) $state['waiting_reason_label'];
            if ($days !== null && $days > 0) {
                $parts[] = "The case has been delayed for {$days} day(s) while waiting on the customer for {$reason}";
            } else {
                $parts[] = "The case is currently waiting on the customer for {$reason}";
            }
        } elseif (($state['current_status_code'] ?? '') === 'appointment_overdue') {
            $parts[] = $ownerName !== ''
                ? "The scheduled support appointment is overdue and has not yet been completed by {$ownerName}"
                : 'The scheduled support appointment is overdue and has not yet been completed';
        } elseif (($state['current_status_code'] ?? '') === 'scheduled') {
            $appointment = $facts->supportAppointment;
            $when = null;
            if (is_array($appointment) && isset($appointment['preferred_date'])) {
                $when = $appointment['preferred_date'] instanceof Carbon
                    ? $appointment['preferred_date']->format('M j, Y')
                    : (string) $appointment['preferred_date'];
            }
            $parts[] = $when !== null
                ? "A support appointment is scheduled for {$when}"
                : 'A support appointment is scheduled and awaiting execution';
        } elseif ($serialMissing) {
            $parts[] = 'Progress is blocked because the device serial number has not been provided';
        } elseif ($serialInsight?->status === SerialInsightStatus::Suspicious
            || $serialInsight?->status === SerialInsightStatus::Warning) {
            $parts[] = 'The case is waiting on serial-number verification before repair work can continue safely';
        }

        return $parts === []
            ? ''
            : rtrim(implode('. ', $parts), '.').'.';
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array{label: string, occurred_at: Carbon}|null  $lastPayment
     */
    private function internalProgress(
        CaseIntelligenceFacts $facts,
        array $state,
        ?SerialInsight $serialInsight,
        bool $serialMissing,
        ?array $lastPayment,
        string $ownerName = '',
    ): ?string {
        $parts = [];
        $statusCode = (string) ($state['current_status_code'] ?? '');

        $ownership = $this->ownershipChangeSentence($facts);
        if ($ownership !== null) {
            $parts[] = $ownership;
        } elseif ($ownerName !== '' && $statusCode !== 'appointment_overdue') {
            // Owner is already woven into the overdue-appointment sentence when applicable.
            $parts[] = "The case is currently owned by {$ownerName}";
        }

        $appointment = $facts->supportAppointment;
        if (is_array($appointment) && $statusCode !== 'appointment_overdue') {
            if ($appointment['is_completed'] ?? false) {
                $parts[] = 'A support appointment has already been completed';
            } elseif ($appointment['is_active'] ?? false) {
                $assignee = $appointment['assignee_name'] ?? null;
                $parts[] = filled($assignee)
                    ? "Engineer {$assignee} is assigned to the support appointment"
                    : 'A support appointment is on the calendar';
            }
        }

        if ($serialMissing) {
            $parts[] = 'The serial number is still pending verification';
        } elseif ($serialInsight?->status === SerialInsightStatus::Suspicious
            || $serialInsight?->status === SerialInsightStatus::Warning) {
            $parts[] = 'Serial number still needs verification';
        }

        if ($lastPayment !== null) {
            $label = (string) ($lastPayment['label'] ?? 'Payment');
            $parts[] = $label.' is on record';
        }

        if (($state['waiting_reason_code'] ?? null) === 'payment') {
            $parts[] = 'Payment is still outstanding for this case';
        }

        $parts = array_values(array_unique(array_filter($parts)));

        if ($parts === []) {
            return null;
        }

        return rtrim(implode('. ', $parts), '.').'.';
    }

    private function communicationGap(CommunicationSummary $communication): ?string
    {
        if (filled($communication->sinceLastCustomerReplyLabel)
            && str_contains(strtolower((string) $communication->sinceLastCustomerReplyLabel), 'not replied')) {
            return 'The customer has not replied after the last outreach.';
        }

        $ourLast = $communication->ourLastContact;
        $customerLast = $communication->customerLastReply;
        if ($ourLast !== null
            && $ourLast->direction === 'outbound'
            && ($customerLast === null || $customerLast->occurredAt->lt($ourLast->occurredAt))) {
            return 'Customer communication is outstanding after the last follow-up.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function nextExpectedAction(
        array $state,
        ?SerialInsight $serialInsight,
        bool $serialMissing,
        CommunicationSummary $communication,
    ): string {
        if ($serialMissing
            || $serialInsight?->status === SerialInsightStatus::Missing
            || (($state['is_waiting'] ?? false) && ($state['waiting_reason_code'] ?? null) === 'serial_number')) {
            return 'Contact the customer immediately and either complete verification or follow the closure policy if there is no response after the required follow-ups.';
        }

        if (($state['current_status_code'] ?? '') === 'appointment_overdue') {
            return 'Contact the customer immediately to recover the overdue appointment or reschedule before escalating.';
        }

        if ($communication->customerLastReply === null
            && ($state['is_waiting'] ?? false)
            && $this->waitingDays($state['waiting_since'] ?? null) >= 2) {
            return 'Send a clear follow-up today and close after the final reminder if the customer remains unreachable.';
        }

        return 'Complete the next operational step promptly and update the customer once it is done.';
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function businessImpact(
        Incident $incident,
        array $state,
        ?SerialInsight $serialInsight,
    ): string {
        $parts = [];
        $sla = (string) ($state['sla_status'] ?? '');

        if ($sla === ServiceCaseSlaStatus::Overdue->value || $sla === 'overdue') {
            $parts[] = 'SLA has already been breached, increasing escalation risk';
        } elseif ($sla === ServiceCaseSlaStatus::Warning->value || $sla === 'warning') {
            $parts[] = 'SLA is approaching breach and needs prompt movement';
        } elseif ($sla === 'paused') {
            $parts[] = 'SLA is paused while waiting on the customer';
        }

        if ($state['is_waiting'] ?? false) {
            $days = $this->waitingDays($state['waiting_since'] ?? null);
            if ($days !== null && $days >= 3) {
                $parts[] = 'Extended customer wait is slowing resolution';
            }
        }

        if ($serialInsight?->status === SerialInsightStatus::Missing
            || $serialInsight?->status === SerialInsightStatus::Suspicious) {
            $parts[] = 'Missing or unverified serial data blocks warranty and repair decisions';
        }

        if ($parts === []) {
            return '';
        }

        return rtrim(implode('. ', array_unique($parts)), '.').'.';
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function buildOpinion(
        array $state,
        ?SerialInsight $serialInsight,
        CommunicationSummary $communication,
    ): string {
        if ($serialInsight?->status === SerialInsightStatus::Missing
            || (($state['is_waiting'] ?? false) && ($state['waiting_reason_code'] ?? null) === 'serial_number')) {
            return 'This case cannot move forward until the device serial number is confirmed with the customer.';
        }

        if (($state['current_status_code'] ?? '') === 'appointment_overdue') {
            return 'The overdue appointment needs immediate customer contact to recover the visit.';
        }

        if ($communication->customerLastReply === null
            && ($state['is_waiting'] ?? false)
            && $this->waitingDays($state['waiting_since'] ?? null) >= 2) {
            return 'The customer has gone quiet, so a clear follow-up is needed before the case stalls further.';
        }

        if (in_array(($state['priority_level'] ?? ''), ['high', 'critical'], true)) {
            return 'This case needs focused ownership because delay has elevated business impact.';
        }

        return 'Keep the case moving with one clear next action and confirmed ownership.';
    }

    private function ownershipChangeSentence(CaseIntelligenceFacts $facts): ?string
    {
        $assignments = $facts->timeline->events()
            ->filter(fn (TimelineEvent $event): bool => $event->type === TimelineEventType::Assignment)
            ->sortBy(fn (TimelineEvent $event): int => $event->occurredAt->timestamp)
            ->values();

        if ($assignments->count() < 2) {
            return null;
        }

        $names = $assignments
            ->map(function (TimelineEvent $event): ?string {
                $title = $event->title;
                if (preg_match('/assigned to\s+(.+)$/i', $title, $matches) === 1) {
                    return trim($matches[1]);
                }

                $name = trim($event->actor->displayName);

                return $name !== '' && strcasecmp($name, 'System') !== 0 ? $name : null;
            })
            ->filter()
            ->unique()
            ->values();

        if ($names->count() < 2) {
            return null;
        }

        $from = $names[$names->count() - 2];
        $to = $names[$names->count() - 1];

        return "Ownership changed from {$from} → {$to}, increasing the risk of further delay";
    }

    private function resolveSerialInsight(Incident $incident): ?SerialInsight
    {
        $order = $incident->order;

        if (! $order instanceof Order) {
            return null;
        }

        return $this->serialInsightService->analyze($order);
    }

    private function waitingDays(mixed $waitingSince): ?int
    {
        if (! $waitingSince instanceof Carbon) {
            return null;
        }

        return (int) $waitingSince->copy()->startOfDay()->diffInDays(now()->startOfDay());
    }
}

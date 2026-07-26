<?php

namespace App\Services\Customer360\Intelligence\Builders;

use App\Data\AI\AIIncidentBundle;
use App\Data\Customer360\Intelligence\CaseIntelligenceEvidence;
use App\Data\Customer360\Intelligence\CaseIntelligenceFacts;
use App\Data\SerialInsight;
use App\Enums\SerialInsightStatus;
use App\Enums\ServiceCaseSlaStatus;
use App\Services\SerialValidation\SerialInsightService;
use Illuminate\Support\Carbon;

/**
 * Builds structured evidence from domain facts (not UI string heuristics).
 */
class CaseEvidenceBuilder
{
    public function __construct(
        private readonly SerialInsightService $serialInsightService,
    ) {}

    /**
     * @return list<CaseIntelligenceEvidence>
     */
    public function build(CaseIntelligenceFacts $facts, AIIncidentBundle $bundle): array
    {
        $evidence = [];
        $order = $facts->order;

        if (filled($order->product_name)) {
            $evidence[] = new CaseIntelligenceEvidence(
                id: 'product',
                title: 'Product matched',
                source: 'Order',
                tone: 'positive',
                supportsFields: ['identity'],
            );
        }

        if (filled($order->serial_number)) {
            $evidence[] = new CaseIntelligenceEvidence(
                id: 'device',
                title: 'Device identified',
                source: 'RadiumBox',
                tone: 'positive',
                supportsFields: ['identity', 'serial'],
            );
        }

        $serialInsight = $this->serialInsightService->analyze($order);

        if ($serialInsight instanceof SerialInsight) {
            $evidence[] = match ($serialInsight->status) {
                SerialInsightStatus::Valid => new CaseIntelligenceEvidence(
                    id: 'serial',
                    title: 'Serial verified',
                    source: 'IRA',
                    tone: 'positive',
                    supportsFields: ['serial', 'blockers'],
                ),
                SerialInsightStatus::Suspicious, SerialInsightStatus::Warning => new CaseIntelligenceEvidence(
                    id: 'serial',
                    title: 'Serial mismatch',
                    source: 'IRA',
                    tone: 'warning',
                    supportsFields: ['serial', 'blockers', 'risks'],
                ),
                SerialInsightStatus::Missing => new CaseIntelligenceEvidence(
                    id: 'serial',
                    title: 'Serial missing',
                    source: 'IRA',
                    tone: 'negative',
                    supportsFields: ['serial', 'blockers'],
                ),
                default => new CaseIntelligenceEvidence(
                    id: 'serial',
                    title: $serialInsight->status->label(),
                    source: 'IRA',
                    tone: 'warning',
                    supportsFields: ['serial'],
                ),
            };
        }

        if ($facts->waitingStateCard !== null) {
            $evidence[] = new CaseIntelligenceEvidence(
                id: 'waiting_state',
                title: 'Waiting state active',
                source: 'IRA',
                tone: 'warning',
                occurredAt: $facts->waitingStateCard['started_at'] ?? null,
                supportsFields: ['waiting', 'blockers', 'current_status'],
            );
        }

        if ($bundle->context->lastPayment !== null) {
            $evidence[] = new CaseIntelligenceEvidence(
                id: 'payment',
                title: 'Payment recorded',
                source: 'Timeline',
                tone: 'positive',
                occurredAt: $bundle->context->lastPayment['occurred_at'] ?? null,
                supportsFields: ['payment'],
            );
        }

        $appointment = $facts->supportAppointment;
        if (is_array($appointment)) {
            $isOverdue = $this->isAppointmentOverdue($appointment);
            $isActive = (bool) ($appointment['is_active'] ?? false);

            $evidence[] = new CaseIntelligenceEvidence(
                id: 'appointment',
                title: match (true) {
                    $isOverdue => 'Support appointment overdue',
                    $isActive => 'Support appointment scheduled',
                    default => 'Support appointment on record',
                },
                source: 'Appointments',
                tone: match (true) {
                    $isOverdue => 'negative',
                    $isActive => 'positive',
                    default => 'neutral',
                },
                occurredAt: $appointment['preferred_date'] ?? null,
                supportsFields: ['appointment', 'current_status', 'blockers'],
            );
        }

        $slaStatus = $facts->incident->slaStatus();
        if ($slaStatus === ServiceCaseSlaStatus::Overdue) {
            $evidence[] = new CaseIntelligenceEvidence(
                id: 'sla',
                title: 'SLA breached',
                source: 'SLA',
                tone: 'negative',
                supportsFields: ['risks', 'current_status', 'priority'],
            );
        } elseif ($slaStatus === ServiceCaseSlaStatus::Warning) {
            $evidence[] = new CaseIntelligenceEvidence(
                id: 'sla',
                title: 'SLA warning',
                source: 'SLA',
                tone: 'warning',
                supportsFields: ['risks', 'current_status', 'priority'],
            );
        }

        return $this->dedupe($evidence);
    }

    /**
     * @return list<array{title: string, source: string, tone: string}>
     */
    public function toViewItems(array $evidence): array
    {
        return array_map(
            fn (CaseIntelligenceEvidence $item): array => [
                'title' => $item->title,
                'source' => $item->source,
                'tone' => $item->tone,
            ],
            $evidence,
        );
    }

    /**
     * @param  array<string, mixed>|null  $appointment
     */
    private function isAppointmentOverdue(?array $appointment): bool
    {
        if (! is_array($appointment)) {
            return false;
        }

        if (! (bool) ($appointment['is_active'] ?? false) || (bool) ($appointment['is_completed'] ?? false)) {
            return false;
        }

        $preferredDate = $appointment['preferred_date'] ?? null;
        if ($preferredDate instanceof Carbon) {
            return $preferredDate->copy()->startOfDay()->lt(now()->startOfDay());
        }

        if (is_string($preferredDate) && $preferredDate !== '') {
            return Carbon::parse($preferredDate)->startOfDay()->lt(now()->startOfDay());
        }

        return false;
    }

    /**
     * @param  list<CaseIntelligenceEvidence>  $evidence
     * @return list<CaseIntelligenceEvidence>
     */
    private function dedupe(array $evidence): array
    {
        $seen = [];
        $unique = [];

        foreach ($evidence as $item) {
            $key = $item->title.'|'.$item->source;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $item;
        }

        return $unique;
    }
}

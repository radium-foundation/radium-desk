<?php

namespace App\Services\Customer360\Intelligence\Builders;

use App\Data\AI\AIIncidentBundle;
use App\Data\Customer360\Intelligence\CaseIntelligenceEvidence;
use App\Data\Customer360\Intelligence\CaseIntelligenceFacts;
use App\Data\SerialInsight;
use App\Enums\SerialInsightStatus;
use App\Services\SerialValidation\SerialInsightService;

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
            $evidence[] = new CaseIntelligenceEvidence(
                id: 'appointment',
                title: ($appointment['is_active'] ?? false)
                    ? 'Support appointment scheduled'
                    : 'Support appointment on record',
                source: 'Appointments',
                tone: ($appointment['is_active'] ?? false) ? 'positive' : 'neutral',
                occurredAt: $appointment['preferred_date'] ?? null,
                supportsFields: ['appointment', 'current_status'],
            );
        }

        return $evidence;
    }
}

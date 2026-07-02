<?php

namespace Tests\Support;

use App\Data\Knowledge\BusinessKnowledgeDTO;
use App\Data\Knowledge\CustomerKnowledgeDTO;
use App\Data\Knowledge\DeviceKnowledgeDTO;
use App\Data\Knowledge\KnowledgeResponseDTO;
use App\Data\Knowledge\OperationsKnowledgeDTO;
use App\Data\Knowledge\RepairKnowledgeDTO;

class KnowledgeFactory
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function make(array $overrides = []): KnowledgeResponseDTO
    {
        $customer = $overrides['customer'] ?? new CustomerKnowledgeDTO(
            lifetimeOrderCount: 1,
            lifetimeRepairCount: 1,
            isPremiumCustomer: false,
            previousIncidents: [],
            previousRepairs: [],
            previousPayments: [],
            previousEscalations: [],
            repeatComplaints: [],
            repeatIssueDetected: false,
            repeatIssueSummary: null,
            repeatIssuePercentage: 0.0,
            averageRepairTurnaroundDays: null,
            lastInteractionAt: null,
            lastInteractionSummary: null,
            outstandingBalance: 0.0,
            paymentBehaviour: 'No payments recorded',
            warrantyHistorySummary: 'Current warranty: Not Available.',
        );

        $device = $overrides['device'] ?? new DeviceKnowledgeDTO(
            model: 'MFS 110',
            category: 'General',
            variant: 'MFS 110 E3',
            serialAvailable: true,
            previousRepairsOnSerial: 0,
            previousRepairsOnModel: 0,
            repairHistory: [],
            failureHistory: [],
            partsReplaced: [],
            technicianHistory: [],
            serialHistory: [],
        );

        $repair = $overrides['repair'] ?? new RepairKnowledgeDTO(
            similarIncidentCount: 0,
            mostCommonResolution: null,
            averageResolutionTimeDays: null,
            historicalSuccessRate: 0.0,
            repeatFailurePercentage: 0.0,
            previousTechnician: null,
            commonFixes: [],
            successfulResolutions: [],
            repeatFailures: [],
            averageRepairDurationDays: null,
            modelWiseRepairStatistics: [],
            topRecommendedFixes: [],
        );

        $business = $overrides['business'] ?? new BusinessKnowledgeDTO(
            customerLifetimeValue: 0.0,
            profitability: 0.0,
            warrantyCost: 0.0,
            repeatRevenue: 0.0,
            totalRepairValue: 0.0,
            partsCostHistory: 0.0,
            amcHistory: [],
        );

        $operations = $overrides['operations'] ?? new OperationsKnowledgeDTO(
            slaHistory: [],
            automationHistory: [],
            notificationHistory: [],
            waitingStateHistory: [],
        );

        return new KnowledgeResponseDTO(
            customer: $customer,
            device: $device,
            repair: $repair,
            business: $business,
            operations: $operations,
            knowledgeSummary: $overrides['knowledgeSummary'] ?? 'Limited historical knowledge available for this case.',
        );
    }
}

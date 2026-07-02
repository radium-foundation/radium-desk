<?php

namespace App\Services\Knowledge;

use App\Data\AI\BusinessIntelligenceDTO;
use App\Data\AI\CustomerIntelligenceDTO;
use App\Data\AI\DeviceIntelligenceDTO;
use App\Data\AI\OperationalIntelligenceDTO;
use App\Data\Knowledge\KnowledgeResponseDTO;
use App\Models\Incident;

class KnowledgeIntelligenceMapper
{
    public function toCustomerIntelligence(KnowledgeResponseDTO $knowledge): CustomerIntelligenceDTO
    {
        $customer = $knowledge->customer;

        return new CustomerIntelligenceDTO(
            lifetimeOrderCount: $customer->lifetimeOrderCount,
            lifetimeRepairCount: $customer->lifetimeRepairCount,
            isPremiumCustomer: $customer->isPremiumCustomer,
            warrantyHistorySummary: $customer->warrantyHistorySummary,
            repeatIssueDetected: $customer->repeatIssueDetected,
            repeatIssueSummary: $customer->repeatIssueSummary,
            averageRepairTurnaroundDays: $customer->averageRepairTurnaroundDays,
            lastInteractionAt: $customer->lastInteractionAt,
            lastInteractionSummary: $customer->lastInteractionSummary,
            outstandingBalance: $customer->outstandingBalance,
            paymentBehaviour: $customer->paymentBehaviour,
        );
    }

    public function toDeviceIntelligence(KnowledgeResponseDTO $knowledge): DeviceIntelligenceDTO
    {
        $device = $knowledge->device;

        return new DeviceIntelligenceDTO(
            model: $device->model,
            category: $device->category,
            variant: $device->variant,
            serialAvailable: $device->serialAvailable,
            previousRepairsOnSerial: $device->previousRepairsOnSerial,
            previousRepairsOnModel: $device->previousRepairsOnModel,
            commonFailurePatterns: $device->failureHistory,
            partsFrequentlyReplaced: $device->partsReplaced,
        );
    }

    public function toOperationalIntelligence(
        KnowledgeResponseDTO $knowledge,
        Incident $incident,
        ?array $waitingState,
        string $automationStatus,
        string $timelineSummary,
        string $internalRemarksSummary,
        ?int $queuePosition,
    ): OperationalIntelligenceDTO {
        return new OperationalIntelligenceDTO(
            waitingState: $waitingState,
            slaState: $incident->slaStatus()->label(),
            priority: $incident->high_priority ? 'High' : 'Normal',
            assignment: $incident->assignee?->name,
            queuePosition: $queuePosition,
            automationHistory: $knowledge->operations->automationHistory,
            automationStatus: $automationStatus,
            timelineSummary: $timelineSummary,
            internalRemarksSummary: $internalRemarksSummary,
        );
    }

    public function toBusinessIntelligence(KnowledgeResponseDTO $knowledge): BusinessIntelligenceDTO
    {
        $business = $knowledge->business;

        return new BusinessIntelligenceDTO(
            revenueFromCustomer: $business->customerLifetimeValue,
            warrantyCost: $business->warrantyCost,
            totalRepairValue: $business->totalRepairValue,
            amcServicePlan: $business->amcHistory[0]['status'] ?? null,
            partsCostHistory: $business->partsCostHistory,
        );
    }
}

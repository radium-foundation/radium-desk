<?php

namespace Tests\Support;

use App\Data\AI\AIContextDTO;
use App\Data\AI\AIRiskIndicatorDTO;
use App\Data\AI\BusinessIntelligenceDTO;
use App\Data\AI\CustomerIntelligenceDTO;
use App\Data\AI\DeviceIntelligenceDTO;
use App\Data\AI\OperationalIntelligenceDTO;
use App\Enums\AI\AIRiskLevel;

class AIContextFactory
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function make(array $overrides = []): AIContextDTO
    {
        $customerIntelligence = $overrides['customerIntelligence'] ?? new CustomerIntelligenceDTO(
            lifetimeOrderCount: 1,
            lifetimeRepairCount: 1,
            isPremiumCustomer: false,
            warrantyHistorySummary: 'Current warranty: Not Available.',
            repeatIssueDetected: false,
            repeatIssueSummary: null,
            averageRepairTurnaroundDays: null,
            lastInteractionAt: null,
            lastInteractionSummary: null,
            outstandingBalance: 0.0,
            paymentBehaviour: 'No payments recorded',
        );

        $deviceIntelligence = $overrides['deviceIntelligence'] ?? new DeviceIntelligenceDTO(
            model: 'MFS 110',
            category: 'General',
            variant: 'MFS 110 E3',
            serialAvailable: true,
            previousRepairsOnSerial: 0,
            previousRepairsOnModel: 0,
            commonFailurePatterns: [],
            partsFrequentlyReplaced: [],
        );

        $operationalIntelligence = $overrides['operationalIntelligence'] ?? new OperationalIntelligenceDTO(
            waitingState: null,
            slaState: 'Within SLA',
            priority: 'Normal',
            assignment: null,
            queuePosition: null,
            automationHistory: [],
            automationStatus: 'Automation Pending',
            timelineSummary: 'No customer timeline events recorded.',
            internalRemarksSummary: 'No internal remarks recorded.',
        );

        $businessIntelligence = $overrides['businessIntelligence'] ?? new BusinessIntelligenceDTO(
            revenueFromCustomer: 0.0,
            warrantyCost: 0.0,
            totalRepairValue: 0.0,
            amcServicePlan: null,
            partsCostHistory: 0.0,
        );

        $defaults = [
            'incidentId' => 1,
            'incidentReference' => 'SC00001',
            'incidentTitle' => 'Test incident',
            'incidentDescription' => 'Test description',
            'incidentStatus' => 'Open',
            'incidentCategory' => 'General',
            'highPriority' => false,
            'customerName' => 'Jane Doe',
            'customerPhone' => '9000000000',
            'customerEmail' => 'jane@example.com',
            'customerSummary' => [
                'total_orders' => 1,
                'total_devices' => 1,
                'open_cases' => 1,
                'closed_cases' => 0,
            ],
            'orderId' => 'RD-001',
            'serialNumber' => 'SN-001',
            'deviceModel' => 'MFS 110',
            'activeServices' => [],
            'warrantyStatus' => 'Not Available',
            'lastPayment' => null,
            'waitingState' => null,
            'orderHistory' => [],
            'recentActivities' => [],
            'automationHistory' => [],
            'automationStatus' => 'Automation Pending',
            'serialMissing' => false,
            'riskIndicators' => [new AIRiskIndicatorDTO('Data Quality Risk', AIRiskLevel::Medium)],
            'internalRemarksCount' => 0,
            'supportAppointment' => null,
            'customerJourney' => null,
        ];

        $data = array_merge($defaults, $overrides);

        return new AIContextDTO(
            incidentId: $data['incidentId'],
            incidentReference: $data['incidentReference'],
            incidentTitle: $data['incidentTitle'],
            incidentDescription: $data['incidentDescription'],
            incidentStatus: $data['incidentStatus'],
            incidentCategory: $data['incidentCategory'],
            highPriority: $data['highPriority'],
            customerName: $data['customerName'],
            customerPhone: $data['customerPhone'],
            customerEmail: $data['customerEmail'],
            customerSummary: $data['customerSummary'],
            orderId: $data['orderId'],
            serialNumber: $data['serialNumber'],
            deviceModel: $data['deviceModel'],
            activeServices: $data['activeServices'],
            warrantyStatus: $data['warrantyStatus'],
            lastPayment: $data['lastPayment'],
            waitingState: $data['waitingState'],
            orderHistory: $data['orderHistory'],
            recentActivities: $data['recentActivities'],
            automationHistory: $data['automationHistory'],
            automationStatus: $data['automationStatus'],
            serialMissing: $data['serialMissing'],
            riskIndicators: $data['riskIndicators'],
            customerIntelligence: $customerIntelligence,
            deviceIntelligence: $deviceIntelligence,
            operationalIntelligence: $operationalIntelligence,
            businessIntelligence: $businessIntelligence,
            internalRemarksCount: $data['internalRemarksCount'],
            knowledge: $overrides['knowledge'] ?? KnowledgeFactory::make(),
            supportAppointment: $data['supportAppointment'],
            customerJourney: $data['customerJourney'],
        );
    }
}

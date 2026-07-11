<?php

namespace App\Data\AI;

use App\Data\Knowledge\KnowledgeResponseDTO;
use Illuminate\Support\Carbon;

readonly class AIContextDTO
{
    /**
     * @param  array<string, int>  $customerSummary
     * @param  list<array{label: string, status: string, variant: string}>  $activeServices
     * @param  array{label: string, occurred_at: Carbon}|null  $lastPayment
     * @param  array<string, mixed>|null  $waitingState
     * @param  list<array{reference: string, title: string, status: string, created_at: Carbon|null}>  $orderHistory
     * @param  list<array{title: string, type: string, occurred_at: Carbon}>  $recentActivities
     * @param  list<array{policy_key: string, action_type: string, status: string, occurred_at: Carbon|null}>  $automationHistory
     * @param  list<AIRiskIndicatorDTO>  $riskIndicators
     * @param  array{
     *     status: \App\Enums\SupportAppointmentStatus,
     *     preferred_date: Carbon,
     *     preferred_time_slot: \App\Enums\SupportAppointmentTimeSlot|null,
     *     time_slot_label: ?string,
     *     created_at: Carbon|null,
     *     updated_at: Carbon|null,
     *     completed_at: ?Carbon,
     *     assignee_name: ?string,
     *     is_active: bool,
     *     is_completed: bool,
     * }|null  $supportAppointment
     * @param  CustomerJourneyDTO|null  $customerJourney
     */
    public function __construct(
        public int $incidentId,
        public string $incidentReference,
        public string $incidentTitle,
        public ?string $incidentDescription,
        public string $incidentStatus,
        public string $incidentCategory,
        public bool $highPriority,
        public ?string $customerName,
        public ?string $customerPhone,
        public ?string $customerEmail,
        public array $customerSummary,
        public ?string $orderId,
        public ?string $serialNumber,
        public ?string $deviceModel,
        public array $activeServices,
        public string $warrantyStatus,
        public ?array $lastPayment,
        public ?array $waitingState,
        public array $orderHistory,
        public array $recentActivities,
        public array $automationHistory,
        public string $automationStatus,
        public bool $serialMissing,
        public array $riskIndicators,
        public CustomerIntelligenceDTO $customerIntelligence,
        public DeviceIntelligenceDTO $deviceIntelligence,
        public OperationalIntelligenceDTO $operationalIntelligence,
        public BusinessIntelligenceDTO $businessIntelligence,
        public int $internalRemarksCount,
        public KnowledgeResponseDTO $knowledge,
        public ?array $supportAppointment = null,
        public ?CustomerJourneyDTO $customerJourney = null,
    ) {}

    public function isWarrantyExpired(): bool
    {
        return str_contains(strtolower($this->warrantyStatus), 'expired');
    }

    public function hasSupportAppointment(): bool
    {
        return $this->supportAppointment !== null;
    }

    public function hasActiveSupportAppointment(): bool
    {
        return (bool) ($this->supportAppointment['is_active'] ?? false);
    }

    public function hasCompletedSupportAppointment(): bool
    {
        return (bool) ($this->supportAppointment['is_completed'] ?? false);
    }

    public function hasCustomerJourney(): bool
    {
        return $this->customerJourney !== null && $this->customerJourney->milestones !== [];
    }
}

<?php

namespace App\Support\Customer360\Journey\Contributors;

use App\Contracts\AI\CustomerJourneyMilestoneContributor;
use App\Data\AI\CustomerJourneyBuildContext;
use App\Data\AI\CustomerJourneyMilestoneDTO;
use App\Data\ServiceCaseTimelineEntry;
use App\Enums\AI\CustomerJourneyMilestoneType;
use App\Enums\SerialInsightStatus;
use App\Models\Order;
use App\Services\ServiceCaseActivityTimelineService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DeviceMilestoneContributor implements CustomerJourneyMilestoneContributor
{
    public function __construct(
        private readonly ServiceCaseActivityTimelineService $activityTimelineService,
    ) {}

    public function contribute(CustomerJourneyBuildContext $context): array
    {
        $order = $context->incident->order;

        if (! $order instanceof Order) {
            return [];
        }

        $milestones = [];
        $activities = $this->activities($context);

        $deviceModel = trim((string) ($context->deviceModel ?? $order->displayDeviceModelName()));

        if ($deviceModel !== '' && ! $order->isInquiryOrder()) {
            $identifiedAt = $order->updated_at ?? $order->created_at ?? now();

            $milestones[] = new CustomerJourneyMilestoneDTO(
                type: CustomerJourneyMilestoneType::DeviceIdentified,
                title: CustomerJourneyMilestoneType::DeviceIdentified->label(),
                timestamp: $identifiedAt instanceof Carbon ? $identifiedAt : Carbon::parse($identifiedAt),
                status: 'completed',
                actor: null,
                source: 'order',
                confidence: filled($deviceModel) ? 80 : 50,
            );
        }

        $serialInsight = $context->serialInsight;

        if ($serialInsight?->status === SerialInsightStatus::Suspicious
            || $serialInsight?->status === SerialInsightStatus::Warning) {
            $requestedAt = $this->firstActivityAt($activities, [
                'request correct serial',
                'waiting for manual correction',
            ]) ?? $order->updated_at ?? now();

            $milestones[] = new CustomerJourneyMilestoneDTO(
                type: CustomerJourneyMilestoneType::SerialCorrectionRequested,
                title: CustomerJourneyMilestoneType::SerialCorrectionRequested->label(),
                timestamp: $requestedAt,
                status: 'active',
                actor: null,
                source: 'serial_insight',
                confidence: 85,
            );
        } elseif ($context->serialMissing) {
            $requestedAt = $this->firstActivityAt($activities, [
                'request serial',
                'waiting for customer input',
            ]) ?? ($context->waitingState['customer_waiting_since'] ?? $order->updated_at ?? now());

            if ($requestedAt instanceof Carbon === false) {
                $requestedAt = Carbon::parse($requestedAt);
            }

            $milestones[] = new CustomerJourneyMilestoneDTO(
                type: CustomerJourneyMilestoneType::SerialCorrectionRequested,
                title: 'Serial number requested',
                timestamp: $requestedAt,
                status: 'active',
                actor: null,
                source: 'waiting_state',
                confidence: 80,
            );
        }

        $verifiedAt = $this->firstActivityAt($activities, [
            'serial validation successful',
            'radiumbox verification successful',
            'corrected by ira',
        ]);

        if ($verifiedAt !== null && ! $context->serialMissing) {
            $milestones[] = new CustomerJourneyMilestoneDTO(
                type: CustomerJourneyMilestoneType::SerialVerified,
                title: CustomerJourneyMilestoneType::SerialVerified->label(),
                timestamp: $verifiedAt,
                status: 'completed',
                actor: null,
                source: 'audit',
                confidence: 90,
            );
        } elseif (! $context->serialMissing && filled($order->serial_number)) {
            $milestones[] = new CustomerJourneyMilestoneDTO(
                type: CustomerJourneyMilestoneType::SerialVerified,
                title: CustomerJourneyMilestoneType::SerialVerified->label(),
                timestamp: $order->updated_at ?? $order->created_at ?? now(),
                status: 'completed',
                actor: null,
                source: 'order',
                confidence: 65,
            );
        }

        return $milestones;
    }

    private function activities(CustomerJourneyBuildContext $context): Collection
    {
        if ($context->activityTimeline instanceof Collection) {
            return $context->activityTimeline;
        }

        return $this->activityTimelineService->forIncident($context->incident);
    }

    /**
     * @param  list<string>  $needles
     */
    private function firstActivityAt(Collection $activities, array $needles): ?Carbon
    {
        foreach ($activities as $entry) {
            if (! $entry instanceof ServiceCaseTimelineEntry) {
                continue;
            }

            $haystack = Str::lower($entry->title.' '.($entry->body ?? ''));

            foreach ($needles as $needle) {
                if (Str::contains($haystack, Str::lower($needle))) {
                    return $entry->occurredAt;
                }
            }
        }

        return null;
    }
}

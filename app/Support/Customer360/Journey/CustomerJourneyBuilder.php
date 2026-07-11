<?php

namespace App\Support\Customer360\Journey;

use App\Contracts\AI\CustomerJourneyMilestoneContributor;
use App\Data\AI\CustomerJourneyBuildContext;
use App\Data\AI\CustomerJourneyDTO;
use App\Data\AI\CustomerJourneyMilestoneDTO;
use App\Data\SerialInsight;
use App\Enums\AI\CustomerJourneyMilestoneType;
use App\Models\Incident;
use App\Models\Order;
use App\Services\SerialValidation\SerialInsightService;
use App\Services\SerialValidation\SerialPlaceholderService;
use App\Services\ServiceCaseActivityTimelineService;
use App\Support\Customer360\Journey\Contributors\DeviceMilestoneContributor;
use App\Support\Customer360\Journey\Contributors\EngagementMilestoneContributor;
use App\Support\Customer360\Journey\Contributors\LifecycleMilestoneContributor;
use App\Support\Customer360\Journey\Contributors\OrderMilestoneContributor;
use App\Support\Customer360\Journey\Contributors\PaymentMilestoneContributor;
use App\Support\Customer360\Journey\Contributors\SupportMilestoneContributor;
use App\Support\DeviceModelFormatter;

class CustomerJourneyBuilder
{
    /** @var list<CustomerJourneyMilestoneContributor> */
    private readonly array $contributors;

    public function __construct(
        PaymentMilestoneContributor $paymentContributor,
        OrderMilestoneContributor $orderContributor,
        DeviceMilestoneContributor $deviceContributor,
        EngagementMilestoneContributor $engagementContributor,
        SupportMilestoneContributor $supportContributor,
        LifecycleMilestoneContributor $lifecycleContributor,
        private readonly CustomerJourneyConclusionResolver $conclusionResolver,
        private readonly CustomerJourneyConfidenceCalculator $confidenceCalculator,
        private readonly ServiceCaseActivityTimelineService $activityTimelineService,
        private readonly SerialInsightService $serialInsightService,
        private readonly SerialPlaceholderService $serialPlaceholderService,
    ) {
        $this->contributors = [
            $paymentContributor,
            $orderContributor,
            $deviceContributor,
            $engagementContributor,
            $supportContributor,
            $lifecycleContributor,
        ];
    }

    public function forIncident(Incident $incident, ?CustomerJourneyBuildContext $prefetched = null): CustomerJourneyDTO
    {
        $incident->loadMissing([
            'order.deviceModel',
            'activeWaitingState',
            'assignee',
            'supportAppointments',
        ]);

        $context = $prefetched ?? $this->buildContext($incident);
        $context = $this->enrichContext($context);

        $milestones = [];

        foreach ($this->contributors as $contributor) {
            $milestones = array_merge($milestones, $contributor->contribute($context));
        }

        $milestones = $this->sortAndDedupe($milestones);
        $conclusion = $this->conclusionResolver->resolve($context, $milestones);
        $confidence = $this->confidenceCalculator->calculate($context, $milestones);

        return new CustomerJourneyDTO(
            milestones: $milestones,
            conclusion: $conclusion,
            confidence: $confidence,
        );
    }

    private function buildContext(Incident $incident): CustomerJourneyBuildContext
    {
        $order = $incident->order;
        $serialInsight = $order instanceof Order ? $this->serialInsightService->analyze($order) : null;

        return new CustomerJourneyBuildContext(
            incident: $incident,
            serialMissing: $order instanceof Order ? $this->isSerialMissing($order) : false,
            deviceModel: $order !== null
                ? DeviceModelFormatter::shortDisplay($order->displayDeviceModelName())
                : null,
            serialInsight: $serialInsight,
        );
    }

    private function enrichContext(CustomerJourneyBuildContext $context): CustomerJourneyBuildContext
    {
        $order = $context->incident->order;
        $serialInsight = $context->serialInsight;

        if ($serialInsight === null && $order instanceof Order) {
            $serialInsight = $this->serialInsightService->analyze($order);
        }

        $activityTimeline = $context->activityTimeline
            ?? $this->activityTimelineService->forIncident($context->incident);

        $serialMissing = $context->serialMissing;

        if ($order instanceof Order && ! $serialMissing) {
            $serialMissing = $this->isSerialMissing($order);
        }

        return new CustomerJourneyBuildContext(
            incident: $context->incident,
            lastPayment: $context->lastPayment,
            waitingState: $context->waitingState,
            supportAppointment: $context->supportAppointment,
            serialMissing: $serialMissing,
            deviceModel: $context->deviceModel,
            timeline: $context->timeline,
            serialInsight: $serialInsight,
            activityTimeline: $activityTimeline,
        );
    }

    /**
     * @param  list<CustomerJourneyMilestoneDTO>  $milestones
     * @return list<CustomerJourneyMilestoneDTO>
     */
    private function sortAndDedupe(array $milestones): array
    {
        $unique = [];
        $seen = [];

        foreach ($milestones as $milestone) {
            $key = $milestone->type->value.'|'.$milestone->timestamp->timestamp.'|'.$milestone->status;

            if ($this->shouldKeepSingle($milestone->type)) {
                $typeKey = $milestone->type->value;

                if (isset($seen[$typeKey])) {
                    $existing = $unique[$seen[$typeKey]];

                    if ($milestone->confidence <= $existing->confidence) {
                        continue;
                    }

                    unset($unique[$seen[$typeKey]]);
                }

                $seen[$typeKey] = $key;
                $unique[$key] = $milestone;

                continue;
            }

            if (isset($unique[$key])) {
                continue;
            }

            $unique[$key] = $milestone;
        }

        return collect($unique)
            ->sortBy(fn (CustomerJourneyMilestoneDTO $milestone) => $milestone->timestamp->timestamp)
            ->values()
            ->all();
    }

    private function shouldKeepSingle(CustomerJourneyMilestoneType $type): bool
    {
        return in_array($type, [
            CustomerJourneyMilestoneType::PaymentReceived,
            CustomerJourneyMilestoneType::OrderImported,
            CustomerJourneyMilestoneType::DeviceIdentified,
            CustomerJourneyMilestoneType::SerialVerified,
            CustomerJourneyMilestoneType::SerialCorrectionRequested,
            CustomerJourneyMilestoneType::WaitingForCustomer,
            CustomerJourneyMilestoneType::Closed,
        ], true);
    }

    private function isSerialMissing(Order $order): bool
    {
        if ($order->isProductOrder() || $order->isInquiryOrder()) {
            return false;
        }

        $serial = trim((string) $order->serial_number);

        return $serial === '' || $this->serialPlaceholderService->isPlaceholder($serial);
    }
}

<?php

namespace App\Support\Customer360\Journey\Contributors;

use App\Contracts\AI\CustomerJourneyMilestoneContributor;
use App\Data\AI\CustomerJourneyBuildContext;
use App\Data\AI\CustomerJourneyMilestoneDTO;
use App\Enums\AI\CustomerJourneyMilestoneType;
use App\Enums\TimelineEventType;
use App\Models\Order;
use Illuminate\Support\Carbon;

class PaymentMilestoneContributor implements CustomerJourneyMilestoneContributor
{
    public function contribute(CustomerJourneyBuildContext $context): array
    {
        $order = $context->incident->order;

        if (! $order instanceof Order) {
            return [];
        }

        $payment = $context->lastPayment;

        if ($payment !== null && isset($payment['occurred_at'])) {
            $occurredAt = $payment['occurred_at'] instanceof Carbon
                ? $payment['occurred_at']
                : Carbon::parse($payment['occurred_at']);

            return [
                $this->milestone(
                    type: CustomerJourneyMilestoneType::PaymentReceived,
                    timestamp: $occurredAt,
                    source: 'timeline',
                    confidence: 90,
                ),
            ];
        }

        if ($order->payment_date !== null) {
            return [
                $this->milestone(
                    type: CustomerJourneyMilestoneType::PaymentReceived,
                    timestamp: $order->payment_date,
                    source: 'order',
                    confidence: 80,
                ),
            ];
        }

        $timeline = $context->timeline;

        if ($timeline !== null) {
            $paymentEvent = $timeline->events()->first(
                fn ($event) => $event->type === TimelineEventType::Payment,
            );

            if ($paymentEvent !== null) {
                return [
                    $this->milestone(
                        type: CustomerJourneyMilestoneType::PaymentReceived,
                        timestamp: $paymentEvent->occurredAt,
                        source: 'timeline',
                        confidence: 85,
                    ),
                ];
            }
        }

        return [];
    }

    private function milestone(
        CustomerJourneyMilestoneType $type,
        Carbon $timestamp,
        string $source,
        int $confidence,
    ): CustomerJourneyMilestoneDTO {
        return new CustomerJourneyMilestoneDTO(
            type: $type,
            title: $type->label(),
            timestamp: $timestamp,
            status: 'completed',
            actor: null,
            source: $source,
            confidence: $confidence,
        );
    }
}

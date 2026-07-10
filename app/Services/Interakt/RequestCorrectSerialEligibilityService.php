<?php

namespace App\Services\Interakt;

use App\Enums\IncidentStatus;
use App\Enums\SerialInsightStatus;
use App\Enums\WhatsAppTemplate;
use App\Models\Incident;
use App\Models\Order;
use App\Services\SerialValidation\SerialInsightService;
use App\Services\SerialValidation\SerialPlaceholderService;

class RequestCorrectSerialEligibilityService
{
    public function __construct(
        private readonly SerialPlaceholderService $placeholderService,
        private readonly SerialInsightService $serialInsightService,
    ) {}

    public function isEligible(Incident $incident): bool
    {
        return $this->ineligibilityReason($incident) === null;
    }

    public function ineligibilityReason(Incident $incident): ?string
    {
        if (! in_array($incident->status, IncidentStatus::operationallyActive(), true)) {
            return 'Service case is not open for serial correction requests.';
        }

        $incident->loadMissing('order');
        $order = $incident->order;

        if ($order === null) {
            return 'Service case is not linked to an order.';
        }

        if ($order->isProductOrder()) {
            return 'Product orders do not require serial correction requests.';
        }

        if ($order->isInquiryOrder()) {
            return 'Inquiry cases do not require serial correction requests.';
        }

        if (! filled($order->customer_phone)) {
            return 'Customer phone number is not available.';
        }

        if (! $this->serialNeedsCorrection($order)) {
            return 'Serial number does not need a correction request.';
        }

        return null;
    }

    public function canShowAction(Incident $incident): bool
    {
        return $this->isEligible($incident)
            && filled(config('interakt.templates.'.WhatsAppTemplate::RequestCorrectSerial->value.'.name'));
    }

    private function serialNeedsCorrection(Order $order): bool
    {
        $serial = trim((string) ($order->serial_number));

        if ($serial === '' || $this->placeholderService->isPlaceholder($serial)) {
            return false;
        }

        $insight = $this->serialInsightService->analyze($order);

        return in_array($insight->status, [
            SerialInsightStatus::Suspicious,
            SerialInsightStatus::Warning,
        ], true);
    }
}

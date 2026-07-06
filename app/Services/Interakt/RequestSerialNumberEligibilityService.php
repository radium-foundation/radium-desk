<?php

namespace App\Services\Interakt;

use App\Enums\IncidentStatus;
use App\Enums\SerialValidationSeverity;
use App\Enums\WhatsAppTemplate;
use App\Models\Incident;
use App\Models\Order;
use App\Services\SerialValidation\SerialPlaceholderService;
use App\Services\SerialValidation\SerialValidationService;

class RequestSerialNumberEligibilityService
{
    public function __construct(
        private readonly SerialPlaceholderService $placeholderService,
        private readonly SerialValidationService $serialValidationService,
    ) {}

    public function isEligible(Incident $incident): bool
    {
        return $this->ineligibilityReason($incident) === null;
    }

    public function ineligibilityReason(Incident $incident): ?string
    {
        if (! in_array($incident->status, IncidentStatus::operationallyActive(), true)) {
            return 'Service case is not open for WhatsApp requests.';
        }

        $incident->loadMissing('order');
        $order = $incident->order;

        if ($order === null) {
            return 'Service case is not linked to an order.';
        }

        if ($order->isProductOrder()) {
            return 'Product orders do not require serial number requests.';
        }

        if (! filled($order->customer_phone)) {
            return 'Customer phone number is not available.';
        }

        if (! $this->serialNeedsRequest($order)) {
            return 'Serial number is already available.';
        }

        return null;
    }

    public function canShowAction(Incident $incident): bool
    {
        return $this->isEligible($incident)
            && filled(config('interakt.templates.'.WhatsAppTemplate::RequestSerialNumber->value.'.name'));
    }

    private function serialNeedsRequest(Order $order): bool
    {
        $serial = $order->serial_number;

        if (! filled($serial) || $this->placeholderService->isPlaceholder($serial)) {
            return true;
        }

        $validation = $this->serialValidationService->validateForOrder((string) $serial, $order);

        return in_array($validation->severity, [
            SerialValidationSeverity::Fail,
        ], true) || $validation->status->isPending();
    }
}

<?php

namespace App\Services\CommunicationActions\BuyProduct;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Services\CommunicationActions\Commercial\CommercialCatalogSupportService;

class BuyProductEligibilityService
{
    public function __construct(
        private readonly CommercialCatalogSupportService $catalogSupportService,
    ) {}

    public function ineligibilityReason(Incident $incident): ?string
    {
        $incident->loadMissing('order');
        $order = $incident->order;

        if ($order === null) {
            return 'Link an order before sending product purchase information.';
        }

        if (! $this->isEligibleIncidentStatus($incident)) {
            return 'Product purchase information can be sent only while the service case is active or resolved.';
        }

        if (! $this->catalogSupportService->hasDeviceModel($order)) {
            return 'Assign a device model before sending product purchase information.';
        }

        if (! $this->catalogSupportService->hasBuyDeviceUrl($order)) {
            return 'No product purchase link is available for this device model.';
        }

        if (! $this->hasCustomerContact($order)) {
            return 'Customer contact details are required before sending product purchase information.';
        }

        return null;
    }

    private function isEligibleIncidentStatus(Incident $incident): bool
    {
        if (in_array($incident->status, [IncidentStatus::Resolved, IncidentStatus::Closed], true)) {
            return true;
        }

        return in_array($incident->status, IncidentStatus::operationallyActive(), true);
    }

    private function hasCustomerContact(Order $order): bool
    {
        $phone = trim((string) ($order->customer_phone ?? ''));
        $email = trim((string) ($order->customer_email ?? ''));

        if ($phone !== '') {
            return true;
        }

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

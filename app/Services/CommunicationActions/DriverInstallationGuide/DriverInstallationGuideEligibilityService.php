<?php

namespace App\Services\CommunicationActions\DriverInstallationGuide;

use App\Models\Incident;
use App\Models\Order;

class DriverInstallationGuideEligibilityService
{
    public function __construct(
        private readonly DriverInstallationGuideSupportService $supportService,
    ) {}

    public function ineligibilityReason(Incident $incident): ?string
    {
        $incident->loadMissing('order');
        $order = $incident->order;

        if ($order === null) {
            return 'Link an order before sending the driver installation guide.';
        }

        if (! $this->hasCustomerContact($order)) {
            return 'Customer contact details are required before sending the driver installation guide.';
        }

        if (! $this->supportService->hasDriverLink($order)) {
            return 'No driver download link is available for this device model.';
        }

        return null;
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

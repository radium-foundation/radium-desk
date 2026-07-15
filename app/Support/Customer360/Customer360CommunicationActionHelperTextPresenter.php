<?php

namespace App\Support\Customer360;

final class Customer360CommunicationActionHelperTextPresenter
{
    /**
     * @var array<string, string>
     */
    private const MESSAGE_MAP = [
        'Review requests can be sent after support work is completed or the service case is resolved.' => 'Available after the support request is completed.',
        'Refund confirmation can be sent only after a refund has been completed for this case.' => 'Available after the refund is completed.',
        'You do not have permission to run this communication action.' => 'You do not have permission for this action.',
        'No driver download link is available for this device model.' => 'No driver link is available for this device.',
        'Link an order before sending the driver installation guide.' => 'Link an order to send the driver guide.',
        'Customer contact details are required before sending the driver installation guide.' => 'Add customer contact details first.',
        'Product purchase information can be sent only while the service case is active or resolved.' => 'Available while the case is active or resolved.',
        'RD Service purchase information can be sent only while the service case is active or resolved.' => 'Available while the case is active or resolved.',
    ];

    public static function for(?string $statusLabel, string $status): ?string
    {
        if (! filled($statusLabel)) {
            return null;
        }

        if ($status === 'skipped') {
            return 'Skipped for this case.';
        }

        if ($status === 'sent') {
            return $statusLabel;
        }

        return self::MESSAGE_MAP[$statusLabel] ?? $statusLabel;
    }
}

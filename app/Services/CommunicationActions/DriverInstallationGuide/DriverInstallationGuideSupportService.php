<?php

namespace App\Services\CommunicationActions\DriverInstallationGuide;

use App\Models\DeviceModel;
use App\Models\Order;

class DriverInstallationGuideSupportService
{
    public function hasDriverLink(?Order $order): bool
    {
        return $this->resolveDriverDownloadLink($order) !== null;
    }

    public function resolveDriverDownloadLink(?Order $order): ?string
    {
        if ($order === null) {
            return null;
        }

        $order->loadMissing('deviceModel');

        $url = trim((string) ($order->deviceModel?->driver_download_url ?? ''));

        return $url !== '' ? $url : null;
    }

    public function resolveDriverDownloadLinkForDeviceModel(DeviceModel $deviceModel): ?string
    {
        $url = trim((string) ($deviceModel->driver_download_url ?? ''));

        return $url !== '' ? $url : null;
    }

    public function resolveModelNameForDeviceModel(DeviceModel $deviceModel): string
    {
        $modelName = trim((string) ($deviceModel->name ?? ''));

        return $modelName !== '' ? $modelName : 'your device';
    }

    public function resolveModelName(?Order $order): string
    {
        if ($order === null) {
            return 'your device';
        }

        $modelName = trim((string) ($order->displayDeviceModelName() ?? ''));

        if ($modelName !== '') {
            return $modelName;
        }

        $productName = trim((string) ($order->product_name ?? ''));

        return $productName !== '' ? $productName : 'your device';
    }

    public function supportContact(): string
    {
        return trim((string) config('communication_actions.support_contact', 'support@radiumbox.com'));
    }

    public function companyName(): string
    {
        return trim((string) config('communication_actions.company_name', 'Radium Box'));
    }

    public function restartInstructions(): string
    {
        return trim((string) config(
            'communication_actions.driver_installation_guide.restart_instructions',
            'Restart your computer after installing the driver, then reconnect the biometric device.',
        ));
    }
}

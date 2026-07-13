<?php

namespace App\Services\CommunicationActions\Commercial;

use App\Models\DeviceModel;
use App\Models\Order;

final class CommercialCatalogSupportService
{
    public function hasDeviceModel(?Order $order): bool
    {
        return $order !== null && $order->device_model_id !== null;
    }

    public function hasBuyRdServiceUrl(?Order $order): bool
    {
        return $this->resolveBuyRdServiceUrl($order) !== null;
    }

    public function hasBuyDeviceUrl(?Order $order): bool
    {
        return $this->resolveBuyDeviceUrl($order) !== null;
    }

    public function resolveBuyRdServiceUrl(?Order $order): ?string
    {
        if ($order === null) {
            return null;
        }

        $order->loadMissing('deviceModel');

        $url = trim((string) ($order->deviceModel?->buy_rd_service_url ?? ''));

        return $url !== '' ? $url : null;
    }

    public function resolveBuyRdServiceUrlForDeviceModel(DeviceModel $deviceModel): ?string
    {
        $url = trim((string) ($deviceModel->buy_rd_service_url ?? ''));

        return $url !== '' ? $url : null;
    }

    public function resolveBuyDeviceUrl(?Order $order): ?string
    {
        if ($order === null) {
            return null;
        }

        $order->loadMissing('deviceModel');

        $url = trim((string) ($order->deviceModel?->buy_device_url ?? ''));

        return $url !== '' ? $url : null;
    }

    public function resolveBuyDeviceUrlForDeviceModel(DeviceModel $deviceModel): ?string
    {
        $url = trim((string) ($deviceModel->buy_device_url ?? ''));

        return $url !== '' ? $url : null;
    }

    public function companyName(): string
    {
        return trim((string) config('communication_actions.company_name', 'Radium Box'));
    }

    public function supportContact(): string
    {
        return trim((string) config('communication_actions.support_contact', 'support@radiumbox.com'));
    }
}

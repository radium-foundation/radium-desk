<?php

namespace App\Enums;

enum ServiceCaseCloseResolutionType: string
{
    case DeviceWorking = 'device_working';
    case DriverInstalled = 'driver_installed';
    case RdServiceActivated = 'rd_service_activated';
    case ConfigurationChanged = 'configuration_changed';
    case GuidanceProvided = 'guidance_provided';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::DeviceWorking => 'Device Working',
            self::DriverInstalled => 'Driver Installed',
            self::RdServiceActivated => 'RD Service Activated',
            self::ConfigurationChanged => 'Configuration Changed',
            self::GuidanceProvided => 'Guidance Provided',
            self::Other => 'Other',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

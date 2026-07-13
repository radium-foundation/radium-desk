<?php

namespace Tests\Unit\CommunicationActions\DriverInstallationGuide;

use App\Models\DeviceModel;
use App\Models\Order;
use App\Services\CommunicationActions\DriverInstallationGuide\DriverInstallationGuideSupportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverInstallationGuideSupportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_driver_link_from_assigned_device_model(): void
    {
        $deviceModel = DeviceModel::query()->create([
            'name' => 'MFS 110',
            'driver_download_url' => 'https://radiumbox.com/drivers/mfs-110',
            'display_order' => 1,
            'is_active' => true,
        ]);

        $order = new Order([
            'device_model_id' => $deviceModel->id,
            'device_model' => 'MFS 110',
        ]);
        $order->setRelation('deviceModel', $deviceModel);

        $service = app(DriverInstallationGuideSupportService::class);

        $this->assertSame('https://radiumbox.com/drivers/mfs-110', $service->resolveDriverDownloadLink($order));
        $this->assertTrue($service->hasDriverLink($order));
    }

    public function test_returns_null_when_device_model_has_no_driver_url(): void
    {
        $deviceModel = DeviceModel::query()->create([
            'name' => 'Unknown Model',
            'display_order' => 1,
            'is_active' => true,
        ]);

        $order = new Order([
            'device_model_id' => $deviceModel->id,
            'device_model' => 'Unknown Model',
        ]);
        $order->setRelation('deviceModel', $deviceModel);

        $service = app(DriverInstallationGuideSupportService::class);

        $this->assertNull($service->resolveDriverDownloadLink($order));
        $this->assertFalse($service->hasDriverLink($order));
    }

    public function test_returns_null_when_order_has_no_device_model_assignment(): void
    {
        $order = new Order([
            'device_model' => 'Legacy Free Text Model',
        ]);

        $service = app(DriverInstallationGuideSupportService::class);

        $this->assertNull($service->resolveDriverDownloadLink($order));
        $this->assertFalse($service->hasDriverLink($order));
    }

    public function test_exposes_support_and_company_defaults(): void
    {
        config([
            'communication_actions.support_contact' => 'help@example.com',
            'communication_actions.company_name' => 'Example Co',
        ]);

        $service = app(DriverInstallationGuideSupportService::class);

        $this->assertSame('help@example.com', $service->supportContact());
        $this->assertSame('Example Co', $service->companyName());
    }
}

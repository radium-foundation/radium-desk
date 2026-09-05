<?php

namespace Tests;

use App\Models\SystemSetting;
use App\Services\Operations\WatchdogCriticalAlertGate;
use App\Services\SystemSettingsService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        WatchdogCriticalAlertGate::clearDurableForTests();

        parent::tearDown();
    }

    protected function enableTelegramNotifications(bool $enabled = true): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => 'notifications.telegram.enabled'],
            ['value' => $enabled ? '1' : '0'],
        );

        app(SystemSettingsService::class)->forget('notifications.telegram.enabled');
    }

    protected function disableTelegramNotifications(): void
    {
        $this->enableTelegramNotifications(false);
    }

    protected function configureLocationSellerIdentity(): void
    {
        config([
            'statutory_invoices.legal_name' => 'Phil Technologies (P) Limited',
            'statutory_invoices.gstin_scope' => '',
            'statutory_invoices.seller_address' => '',
            'statutory_invoices.seller_state' => '',
            'statutory_invoices.location_series.enabled' => true,
            'statutory_invoices.location_series.locations.delhi.gstin' => '07AAICP1128M1Z9',
            'statutory_invoices.location_series.locations.delhi.address' => 'Test-only Delhi registered address',
            'statutory_invoices.location_series.locations.delhi.state' => 'Delhi',
            'statutory_invoices.location_series.locations.mumbai.gstin' => '27AAICP1128M1Z7',
            'statutory_invoices.location_series.locations.mumbai.address' => 'Test-only Mumbai registered address',
            'statutory_invoices.location_series.locations.mumbai.state' => 'Maharashtra',
        ]);
    }

    protected function configuredSellerGstin(string $location): string
    {
        return (string) config('statutory_invoices.location_series.locations.'.$location.'.gstin');
    }
}

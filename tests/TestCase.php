<?php

namespace Tests;

use App\Models\SystemSetting;
use App\Services\Operations\WatchdogCriticalAlertGate;
use App\Services\SystemSettingsService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;

abstract class TestCase extends BaseTestCase
{
    /**
     * Map legacy dashboard queue/filter/view params to canonical workspace= for HTTP assertions.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function dashboardQuery(array $params = []): array
    {
        if (isset($params['workspace'])) {
            return $params;
        }

        $query = $params;

        if (isset($query['queue']) && $query['queue'] !== '') {
            $query['workspace'] = $query['queue'];
            unset($query['queue']);
        } elseif (isset($query['filter']) && $query['filter'] !== '') {
            $query['workspace'] = $query['filter'];
            unset($query['filter']);
        }

        unset($query['view']);

        return $query;
    }

    protected function disableRetiredAdminOrderFallback(): void
    {
        config([
            'radiumbox.admin_fallback_enabled' => false,
            'order_lookup.admin_fallback_enabled' => false,
            'radiumbox.enabled' => false,
            'radiumbox.base_url' => '',
        ]);
    }

    protected function enableRetiredAdminOrderFallback(): void
    {
        config([
            'radiumbox.admin_fallback_enabled' => true,
            'order_lookup.admin_fallback_enabled' => true,
        ]);
    }

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

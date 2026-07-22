<?php

namespace Tests;

use App\Models\SystemSetting;
use App\Services\SystemSettingsService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        \Illuminate\Support\Carbon::setTestNow();

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
}

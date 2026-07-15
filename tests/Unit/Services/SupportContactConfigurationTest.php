<?php

namespace Tests\Unit\Services;

use App\Services\SettingService;
use App\Services\SupportContactConfiguration;
use Tests\TestCase;

class SupportContactConfigurationTest extends TestCase
{
    public function test_apply_to_config_exposes_values_from_support_contact_config(): void
    {
        config([
            'support_contact.email' => 'config@example.com',
            'support_contact.phone' => '+91 7000000000',
            'support_contact.whatsapp' => '+91 6000000000',
            'support_contact.website' => 'https://radiumbox.com',
            'support_contact.contact' => 'config@example.com',
        ]);

        $settingService = $this->createMock(SettingService::class);
        $settingService->method('get')->willReturn(null);

        $configuration = new SupportContactConfiguration($settingService);
        $configuration->applyToConfig();

        $this->assertSame('config@example.com', config('support_contact.email'));
        $this->assertSame('+91 7000000000', config('support_contact.phone'));
        $this->assertSame('config@example.com', config('communication_actions.support_email'));
        $this->assertSame('+91 7000000000', config('communication_actions.support_phone'));
        $this->assertSame('config@example.com', config('communication_actions.support_contact'));
    }

    public function test_settings_override_env_backed_config_values(): void
    {
        config([
            'support_contact.email' => 'config@example.com',
            'support_contact.phone' => '+91 7000000000',
            'support_contact.whatsapp' => '',
            'support_contact.website' => '',
            'support_contact.contact' => 'config@example.com',
        ]);

        $settingService = $this->createMock(SettingService::class);
        $settingService->method('get')->willReturnMap([
            ['support.email', null, 'settings@example.com'],
            ['support.phone', null, '+91 8111111111'],
            ['support.whatsapp', null, null],
            ['support.website', null, null],
            ['support.contact', null, null],
        ]);

        $configuration = new SupportContactConfiguration($settingService);
        $configuration->applyToConfig();

        $this->assertSame('settings@example.com', config('support_contact.email'));
        $this->assertSame('+91 8111111111', config('support_contact.phone'));
        $this->assertSame('settings@example.com', config('communication_actions.support_email'));
    }
}

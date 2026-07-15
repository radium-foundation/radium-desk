<?php

namespace Tests\Unit\Notifications;

use App\Enums\NotificationType;
use App\Services\Notifications\NotificationMailTemplateRegistry;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuyRdServiceEmailTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
    }

    public function test_registry_uses_production_subject_and_view(): void
    {
        $definition = app(NotificationMailTemplateRegistry::class)
            ->resolve(NotificationType::BuyRdService);

        $this->assertNotNull($definition);
        $this->assertSame('Protect Your Device with RD Service', $definition->subject);
        $this->assertSame('emails.notifications.buy-rd-service', $definition->view);
        $this->assertSame(
            ['customer_name', 'company_name', 'buy_rd_service_url'],
            $definition->requiredVariables,
        );
    }

    public function test_template_renders_production_content_with_cta(): void
    {
        $html = view('emails.notifications.buy-rd-service', [
            'customer_name' => 'Jane Doe',
            'company_name' => 'Radium Box',
            'buy_rd_service_url' => 'https://radiumbox.com/rd-service/mfs-110',
            'support_contact' => 'support@radiumbox.com',
        ])->render();

        $this->assertStringContainsString('Learn more about RD Service for your device.', $html);
        $this->assertStringContainsString('Protect Your Device with RD Service', $html);
        $this->assertStringContainsString('Hello Jane Doe,', $html);
        $this->assertStringContainsString('Get RD Service', $html);
        $this->assertStringContainsString('https://radiumbox.com/rd-service/mfs-110', $html);
        $this->assertStringContainsString('background-color: #0d6efd', $html);
        $this->assertStringContainsString('Team Radium Box', $html);
        $this->assertStringContainsString('support@radiumbox.com', $html);
    }
}

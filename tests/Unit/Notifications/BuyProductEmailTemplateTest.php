<?php

namespace Tests\Unit\Notifications;

use App\Enums\NotificationType;
use App\Services\Notifications\NotificationMailTemplateRegistry;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuyProductEmailTemplateTest extends TestCase
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
            ->resolve(NotificationType::BuyProduct);

        $this->assertNotNull($definition);
        $this->assertSame('Recommended Product for Your Device', $definition->subject);
        $this->assertSame('emails.notifications.buy-product', $definition->view);
        $this->assertSame(
            ['customer_name', 'company_name', 'buy_device_url'],
            $definition->requiredVariables,
        );
    }

    public function test_template_renders_production_content_with_cta(): void
    {
        $html = view('emails.notifications.buy-product', [
            'customer_name' => 'Jane Doe',
            'company_name' => 'Radium Box',
            'buy_device_url' => 'https://radiumbox.com/shop/mfs-110',
            'support_contact' => 'support@radiumbox.com',
        ])->render();

        $this->assertStringContainsString('View the recommended product for your device.', $html);
        $this->assertStringContainsString('Recommended Product for Your Device', $html);
        $this->assertStringContainsString('Hello Jane Doe,', $html);
        $this->assertStringContainsString('View Product', $html);
        $this->assertStringContainsString('https://radiumbox.com/shop/mfs-110', $html);
        $this->assertStringContainsString('background-color: #0d6efd', $html);
        $this->assertStringContainsString('Team Radium Box', $html);
        $this->assertStringContainsString('support@radiumbox.com', $html);
    }
}

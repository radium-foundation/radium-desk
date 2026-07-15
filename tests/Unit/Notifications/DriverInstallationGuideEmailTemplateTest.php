<?php

namespace Tests\Unit\Notifications;

use App\Enums\NotificationType;
use App\Services\Notifications\NotificationMailTemplateRegistry;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverInstallationGuideEmailTemplateTest extends TestCase
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
            ->resolve(NotificationType::DriverInstallationGuide);

        $this->assertNotNull($definition);
        $this->assertSame('Driver Installation Guide for Your Device', $definition->subject);
        $this->assertSame('emails.notifications.driver-installation-guide', $definition->view);
        $this->assertSame(
            ['customer_name', 'driver_download_link', 'company_name'],
            $definition->requiredVariables,
        );
    }

    public function test_template_renders_branded_production_content_with_primary_cta(): void
    {
        $html = view('emails.notifications.driver-installation-guide', [
            'customer_name' => 'Jane Doe',
            'company_name' => 'Radium',
            'driver_download_link' => 'https://radiumbox.com/drivers/mfs-110',
            'support_contact' => 'support@radiumbox.com',
            'support_booking_link' => 'https://example.com/support-appointments/1/book?signature=test',
        ])->render();

        $this->assertStringContainsString('Download the latest driver and complete the installation in just a few simple steps.', $html);
        $this->assertStringContainsString('Driver Installation Guide', $html);
        $this->assertStringContainsString('Hello Jane Doe,', $html);
        $this->assertStringContainsString('Thank you for choosing Radium.', $html);
        $this->assertStringContainsString('To ensure your device works correctly', $html);
        $this->assertStringContainsString('Download Driver', $html);
        $this->assertStringContainsString('https://radiumbox.com/drivers/mfs-110', $html);
        $this->assertStringContainsString('background-color: #0d6efd', $html);
        $this->assertStringContainsString('After Installation', $html);
        $this->assertStringContainsString('Install the downloaded driver.', $html);
        $this->assertStringContainsString('Restart your computer.', $html);
        $this->assertStringContainsString('Reconnect your device.', $html);
        $this->assertStringContainsString('You are now ready to use your device.', $html);
        $this->assertStringContainsString('Book a Support Session', $html);
        $this->assertStringContainsString('https://example.com/support-appointments/1/book?signature=test', $html);
        $this->assertStringContainsString('Or simply reply to this email', $html);
        $this->assertStringContainsString('Kind regards,', $html);
        $this->assertStringContainsString('Team Radium', $html);
        $this->assertStringContainsString('Need Help?', $html);
        $this->assertStringContainsString('mailto:support@radiumbox.com', $html);
        $this->assertStringContainsString('support@radiumbox.com', $html);
        $this->assertStringContainsString('brand/icon.svg', $html);
    }

    public function test_template_hides_secondary_cta_when_support_booking_link_is_unavailable(): void
    {
        $html = view('emails.notifications.driver-installation-guide', [
            'customer_name' => 'Jane Doe',
            'company_name' => 'Radium',
            'driver_download_link' => 'https://radiumbox.com/drivers/mfs-110',
            'support_contact' => 'support@radiumbox.com',
            'support_booking_link' => '',
        ])->render();

        $this->assertStringNotContainsString('Book a Support Session', $html);
        $this->assertStringContainsString('Or simply reply to this email', $html);
        $this->assertStringContainsString('Download Driver', $html);
    }
}

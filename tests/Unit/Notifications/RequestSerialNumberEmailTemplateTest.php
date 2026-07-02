<?php

namespace Tests\Unit\Notifications;

use App\Enums\NotificationType;
use App\Services\Notifications\NotificationMailTemplateRegistry;
use Tests\TestCase;

class RequestSerialNumberEmailTemplateTest extends TestCase
{
    public function test_registry_uses_updated_subject_and_view(): void
    {
        $definition = app(NotificationMailTemplateRegistry::class)
            ->resolve(NotificationType::RequestSerialNumber);

        $this->assertNotNull($definition);
        $this->assertSame('Help Us Complete Your Device Setup', $definition->subject);
        $this->assertSame('emails.notifications.request-serial-number', $definition->view);
        $this->assertSame(['customer_name', 'booking_url'], $definition->requiredVariables);
    }

    public function test_template_renders_customer_facing_content(): void
    {
        $html = view('emails.notifications.request-serial-number', [
            'customer_name' => 'Jane Doe',
            'booking_url' => 'https://example.com/support-appointments/1/book?signature=test',
        ])->render();

        $this->assertStringContainsString('Help Us Complete Your Device Setup', $html);
        $this->assertStringContainsString('Dear Jane Doe,', $html);
        $this->assertStringContainsString('We are reaching out to provide dedicated technical support and get your biometric device set up successfully.', $html);
        $this->assertStringContainsString('please let us know a convenient time for us to call you between 9:00 AM and 6:00 PM.', $html);
        $this->assertStringContainsString('A clear photo of the back of your device showing the serial number, or', $html);
        $this->assertStringContainsString("A screenshot of the device's internal serial number.", $html);
        $this->assertStringContainsString("Once we receive the serial number, we'll proceed with your request right away.", $html);
        $this->assertStringContainsString('Looking forward to getting you successfully connected!', $html);
        $this->assertStringContainsString('support@radiumbox.com', $html);
        $this->assertStringContainsString('+91 XXXXX XXXXX', $html);
        $this->assertStringContainsString('Team Radium Box', $html);
        $this->assertStringContainsString('Radium Box', $html);
        $this->assertStringContainsString('Schedule Technical Support', $html);
        $this->assertStringContainsString('https://example.com/support-appointments/1/book?signature=test', $html);
    }
}

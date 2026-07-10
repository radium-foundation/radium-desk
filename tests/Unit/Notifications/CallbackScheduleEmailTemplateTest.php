<?php

namespace Tests\Unit\Notifications;

use App\Services\Notifications\NotificationMailTemplateRegistry;
use Tests\TestCase;

class CallbackScheduleEmailTemplateTest extends TestCase
{
    public function test_registry_uses_expected_subject_and_view(): void
    {
        $definition = app(NotificationMailTemplateRegistry::class)
            ->resolve(\App\Enums\NotificationType::CallbackSchedule);

        $this->assertNotNull($definition);
        $this->assertSame('We tried reaching you - schedule your callback', $definition->subject);
        $this->assertSame('emails.notifications.callback-schedule', $definition->view);
        $this->assertSame(['customer_name', 'reference', 'booking_url'], $definition->requiredVariables);
    }

    public function test_template_renders_customer_facing_content(): void
    {
        $html = view('emails.notifications.callback-schedule', [
            'customer_name' => 'Jane Doe',
            'reference' => 'INC-2026-00042',
            'booking_url' => 'https://example.com/support-appointments/1/book?signature=test',
        ])->render();

        $this->assertStringContainsString('We Tried Reaching You', $html);
        $this->assertStringContainsString('Hi Jane Doe,', $html);
        $this->assertStringContainsString('support request INC-2026-00042', $html);
        $this->assertStringContainsString('could not connect', $html);
        $this->assertStringContainsString('Schedule Callback', $html);
        $this->assertStringContainsString('https://example.com/support-appointments/1/book?signature=test', $html);
    }
}

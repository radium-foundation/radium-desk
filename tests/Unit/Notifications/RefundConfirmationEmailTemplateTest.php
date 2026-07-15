<?php

namespace Tests\Unit\Notifications;

use App\Enums\NotificationType;
use App\Services\Notifications\NotificationMailTemplateRegistry;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefundConfirmationEmailTemplateTest extends TestCase
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
            ->resolve(NotificationType::RefundConfirmation);

        $this->assertNotNull($definition);
        $this->assertSame('Your Refund Has Been Processed', $definition->subject);
        $this->assertSame('emails.notifications.refund-confirmation', $definition->view);
        $this->assertSame(
            ['customer_name', 'company_name', 'refund_amount', 'refund_reference'],
            $definition->requiredVariables,
        );
    }

    public function test_template_renders_production_refund_confirmation_content(): void
    {
        $html = view('emails.notifications.refund-confirmation', [
            'customer_name' => 'Jane Doe',
            'company_name' => 'Radium Box',
            'refund_amount' => '1,500.00',
            'refund_reference' => 'REF-2026-000400',
            'support_contact' => 'support@radiumbox.com',
        ])->render();

        $this->assertStringContainsString('Your refund has been successfully processed.', $html);
        $this->assertStringContainsString('Your Refund Has Been Processed', $html);
        $this->assertStringContainsString('Hello Jane Doe,', $html);
        $this->assertStringContainsString('Refund Amount', $html);
        $this->assertStringContainsString('1,500.00', $html);
        $this->assertStringContainsString('Reference Number', $html);
        $this->assertStringContainsString('REF-2026-000400', $html);
        $this->assertStringContainsString('simply reply to this email', $html);
        $this->assertStringContainsString('Kind regards,', $html);
        $this->assertStringContainsString('Team Radium Box', $html);
        $this->assertStringContainsString('support@radiumbox.com', $html);
        $this->assertStringContainsString('brand/icon.svg', $html);
    }
}

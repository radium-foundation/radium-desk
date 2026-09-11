<?php

namespace Tests\Unit\Notifications;

use App\Enums\NotificationType;
use App\Services\Notifications\NotificationMailTemplateRegistry;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceCaseClosedEmailTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
    }

    public function test_registry_uses_service_case_closed_subject_and_view(): void
    {
        $definition = app(NotificationMailTemplateRegistry::class)
            ->resolve(NotificationType::ServiceCaseClosed);

        $this->assertNotNull($definition);
        $this->assertSame('Your Service Case Is Complete', $definition->subject);
        $this->assertSame('emails.notifications.service-case-closed', $definition->view);
        $this->assertSame(['customer_name', 'reference'], $definition->requiredVariables);
    }

    public function test_template_renders_customer_facing_support_footer(): void
    {
        $html = view('emails.notifications.service-case-closed', [
            'customer_name' => 'Jane Doe',
            'reference' => 'SC52315',
        ])->render();

        $this->assertStringContainsString('Your Service Case Is Complete', $html);
        $this->assertStringContainsString('Hi Jane Doe,', $html);
        $this->assertStringContainsString('Repair work for case SC52315 is complete.', $html);
        $this->assertStringContainsString('Need Help?', $html);
        $this->assertStringContainsString('support@radiumbox.com', $html);
        $this->assertStringContainsString('+91 84343 84343', $html);
        $this->assertStringNotContainsString('+91 XXXXX XXXXX', $html);
        $this->assertStringContainsString('Team Radium Box', $html);
    }
}

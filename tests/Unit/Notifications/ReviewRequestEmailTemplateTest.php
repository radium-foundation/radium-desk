<?php

namespace Tests\Unit\Notifications;

use App\Enums\NotificationType;
use App\Services\Notifications\NotificationMailTemplateRegistry;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewRequestEmailTemplateTest extends TestCase
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
            ->resolve(NotificationType::ReviewRequest);

        $this->assertNotNull($definition);
        $this->assertSame('How Was Your Experience with Radium?', $definition->subject);
        $this->assertSame('emails.notifications.review-request', $definition->view);
        $this->assertSame(
            ['customer_name', 'company_name', 'review_url', 'support_contact'],
            $definition->requiredVariables,
        );
    }

    public function test_template_renders_branded_production_content_with_primary_cta(): void
    {
        $html = view('emails.notifications.review-request', [
            'customer_name' => 'Jane Doe',
            'company_name' => 'Radium Box',
            'review_url' => 'https://g.page/r/radiumbox/review',
            'support_contact' => 'support@radiumbox.com',
        ])->render();

        $this->assertStringContainsString('love your feedback.', $html);
        $this->assertStringContainsString('How Was Your Experience with Radium?', $html);
        $this->assertStringContainsString('Hello Jane Doe,', $html);
        $this->assertStringContainsString('Thank you for choosing Radium Box.', $html);
        $this->assertStringContainsString('share your experience on Google', $html);
        $this->assertStringContainsString('Leave a Review', $html);
        $this->assertStringContainsString('https://g.page/r/radiumbox/review', $html);
        $this->assertStringContainsString('background-color: #0d6efd', $html);
        $this->assertStringContainsString('Kind regards,', $html);
        $this->assertStringContainsString('Team Radium Box', $html);
        $this->assertStringContainsString('support@radiumbox.com', $html);
        $this->assertStringContainsString('brand/icon.svg', $html);
    }
}

<?php

namespace Tests\Unit\Notifications;

use App\Data\NotificationMessage;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\NotificationType;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Notifications\NotificationMailTemplateRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerWaitingFollowupEmailTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_registry_uses_updated_subject_and_view(): void
    {
        $definition = app(NotificationMailTemplateRegistry::class)
            ->resolve(NotificationType::CustomerWaitingFollowup);

        $this->assertNotNull($definition);
        $this->assertSame(
            'Support Reminder: Request {reference} waiting for your response',
            $definition->subject,
        );
        $this->assertSame('emails.notifications.customer-waiting-followup', $definition->view);
        $this->assertSame(['customer_name', 'reference', 'booking_url'], $definition->requiredVariables);
    }

    public function test_subject_interpolates_support_request_reference(): void
    {
        $message = $this->makeMessage('INC-2026-00042');

        $subject = app(NotificationMailTemplateRegistry::class)
            ->subjectFor(NotificationType::CustomerWaitingFollowup, $message);

        $this->assertSame(
            'Support Reminder: Request INC-2026-00042 waiting for your response',
            $subject,
        );
    }

    public function test_template_renders_customer_facing_content(): void
    {
        $html = view('emails.notifications.customer-waiting-followup', [
            'customer_name' => 'Jane Doe',
            'reference' => 'INC-2026-00042',
            'booking_url' => 'https://example.com/support-appointments/1/book?signature=test',
        ])->render();

        $this->assertStringContainsString('Support Reminder', $html);
        $this->assertStringContainsString('Hi Jane Doe,', $html);
        $this->assertStringContainsString('This is a reminder regarding your support request INC-2026-00042.', $html);
        $this->assertStringContainsString('We are waiting for the details requested earlier to continue your support.', $html);
        $this->assertStringContainsString('Need assistance?', $html);
        $this->assertStringContainsString('Book Support', $html);
        $this->assertStringContainsString('https://example.com/support-appointments/1/book?signature=test', $html);
        $this->assertStringContainsString('This request is paused until we receive your details.', $html);
        $this->assertStringContainsString('You can continue anytime by sharing the required information.', $html);
        $this->assertStringContainsString('Radium Support Desk', $html);
    }

    private function makeMessage(string $reference): NotificationMessage
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-CWF-'.uniqid(),
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Jane Doe',
            'customer_phone' => '9876543210',
            'customer_email' => 'jane.doe@example.com',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => $reference,
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Customer waiting follow-up case',
            'description' => 'Customer waiting follow-up case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return new NotificationMessage(
            type: NotificationType::CustomerWaitingFollowup,
            customer: $order,
            incident: $incident,
        );
    }
}

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
use stdClass;
use Tests\TestCase;

class NotificationMailTemplateRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_subject_interpolation_skips_non_scalar_variables(): void
    {
        $message = $this->makeMessage(
            NotificationType::CustomerWaitingFollowup,
            'INC-2026-00042',
            [
                'whatsapp_body_values' => ['Jane Doe', 'https://example.com/book'],
                'channel_metadata' => new stdClass,
            ],
        );

        $subject = app(NotificationMailTemplateRegistry::class)
            ->subjectFor(NotificationType::CustomerWaitingFollowup, $message);

        $this->assertSame(
            'Support Reminder: Request INC-2026-00042 waiting for your response',
            $subject,
        );
    }

    public function test_subject_interpolation_continues_replacing_scalar_variables(): void
    {
        $message = $this->makeMessage(
            NotificationType::CustomerWaitingFollowup,
            'INC-2026-00099',
        );

        $subject = app(NotificationMailTemplateRegistry::class)
            ->subjectFor(NotificationType::CustomerWaitingFollowup, $message);

        $this->assertSame(
            'Support Reminder: Request INC-2026-00099 waiting for your response',
            $subject,
        );
    }

    /**
     * @param  array<string, mixed>  $extraVariables
     */
    private function makeMessage(
        NotificationType $type,
        string $reference,
        array $extraVariables = [],
    ): NotificationMessage {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-NMR-'.uniqid(),
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
            'title' => 'Notification mail template registry case',
            'description' => 'Notification mail template registry case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return new NotificationMessage(
            type: $type,
            customer: $order,
            incident: $incident,
            variables: $extraVariables,
        );
    }
}

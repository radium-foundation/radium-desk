<?php

namespace Tests\Unit\CommunicationActions;

use App\Enums\CommunicationActionLifecycleStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Services\CommunicationActions\CommunicationActionLifecycleAuditService;
use App\Services\IncidentReferenceService;
use App\Services\Timeline\Mappers\CommunicationActionLifecycleTimelineEventMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunicationActionLifecycleTimelineEventMapperTest extends TestCase
{
    use RefreshDatabase;

    public function test_maps_sent_lifecycle_event_to_timeline_event(): void
    {
        $actor = User::factory()->create();
        $incident = $this->createIncident($actor);

        $auditLog = AuditLog::query()->create([
            'user_id' => $actor->id,
            'event' => CommunicationActionLifecycleAuditService::EVENT,
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'new_values' => [
                'action_key' => 'review_request',
                'action_label' => 'Review request sent',
                'status' => CommunicationActionLifecycleStatus::Sent->value,
                'execution_mode' => 'manual',
                'channels' => ['whatsapp'],
                'operator_name' => $actor->name,
            ],
        ]);

        $event = app(CommunicationActionLifecycleTimelineEventMapper::class)->fromAuditLog($auditLog);

        $this->assertNotNull($event);
        $this->assertSame('Review request sent', $event->title);
        $this->assertSame('Sent', $event->statusLabel);
        $this->assertSame('whatsapp', $event->summaryFields[0]['value']);
    }

    public function test_ignores_opened_and_completed_events_for_timeline(): void
    {
        $actor = User::factory()->create();
        $incident = $this->createIncident($actor);

        $mapper = app(CommunicationActionLifecycleTimelineEventMapper::class);

        foreach ([CommunicationActionLifecycleStatus::Opened, CommunicationActionLifecycleStatus::Completed] as $status) {
            $auditLog = AuditLog::query()->create([
                'event' => CommunicationActionLifecycleAuditService::EVENT,
                'auditable_type' => $incident->getMorphClass(),
                'auditable_id' => $incident->id,
                'new_values' => [
                    'action_key' => 'review_request',
                    'status' => $status->value,
                ],
            ]);

            $this->assertNull($mapper->fromAuditLog($auditLog));
        }
    }

    private function createIncident(User $actor): Incident
    {
        $order = Order::query()->create([
            'order_id' => 'RD-COMM-MAP',
            'serial_number' => 'SN-MAP',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'customer_email' => 'customer@example.com',
            'customer_name' => 'Test Customer',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Timeline mapper case',
            'description' => 'Timeline mapper case.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'assigned_to_user_id' => $actor->id,
        ]);
    }
}

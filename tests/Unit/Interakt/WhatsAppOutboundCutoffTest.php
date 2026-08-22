<?php

namespace Tests\Unit\Interakt;

use App\Data\NotificationMessage;
use App\Data\NotificationResult;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\NotificationChannelType;
use App\Enums\NotificationType;
use App\Enums\WaitingReason;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Interakt\WhatsAppOutboundCutoff;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppOutboundCutoffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_should_skip_is_false_when_cutoff_is_unset(): void
    {
        config(['interakt.outbound_not_before' => null]);

        $this->assertFalse(app(WhatsAppOutboundCutoff::class)->shouldSkip(
            $this->makeMessage(firstRequestedAt: '2026-08-18 08:35:00'),
        ));
    }

    public function test_should_skip_is_false_when_cutoff_is_empty(): void
    {
        config(['interakt.outbound_not_before' => '']);

        $this->assertFalse(app(WhatsAppOutboundCutoff::class)->shouldSkip(
            $this->makeMessage(firstRequestedAt: '2026-08-18 08:35:00'),
        ));
    }

    public function test_should_skip_is_false_when_cutoff_is_invalid(): void
    {
        config(['interakt.outbound_not_before' => 'not-a-timestamp']);

        $this->assertNull(app(WhatsAppOutboundCutoff::class)->cutoffAt());
        $this->assertFalse(app(WhatsAppOutboundCutoff::class)->shouldSkip(
            $this->makeMessage(firstRequestedAt: '2026-08-18 08:35:00'),
        ));
    }

    public function test_should_skip_is_true_for_pre_cutoff_missing_serial_journey(): void
    {
        config(['interakt.outbound_not_before' => '2026-08-22 09:54:00']);

        $this->assertTrue(app(WhatsAppOutboundCutoff::class)->shouldSkip(
            $this->makeMessage(firstRequestedAt: '2026-08-22 09:53:59'),
        ));
    }

    public function test_should_skip_is_false_at_exact_cutoff_boundary(): void
    {
        config(['interakt.outbound_not_before' => '2026-08-22 09:54:00']);

        $this->assertFalse(app(WhatsAppOutboundCutoff::class)->shouldSkip(
            $this->makeMessage(firstRequestedAt: '2026-08-22 09:54:00'),
        ));
    }

    public function test_should_skip_is_false_for_post_cutoff_journey(): void
    {
        config(['interakt.outbound_not_before' => '2026-08-22 09:54:00']);

        $this->assertFalse(app(WhatsAppOutboundCutoff::class)->shouldSkip(
            $this->makeMessage(firstRequestedAt: '2026-08-22 09:54:01'),
        ));
    }

    public function test_should_skip_is_false_when_journey_timestamp_is_absent(): void
    {
        config(['interakt.outbound_not_before' => '2026-08-22 09:54:00']);

        $this->assertFalse(app(WhatsAppOutboundCutoff::class)->shouldSkip($this->makeMessage()));
        $this->assertNull(app(WhatsAppOutboundCutoff::class)->journeyStartedAt($this->makeMessage()));
    }

    public function test_should_skip_uses_waiting_state_started_at(): void
    {
        config(['interakt.outbound_not_before' => '2026-08-22 09:54:00']);

        $message = $this->makeMessage();
        IncidentWaitingState::query()->create([
            'incident_id' => $message->incident->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => '2026-08-18 08:35:00',
            'sla_paused' => true,
            'created_by' => $message->actor?->id,
        ]);
        $message->incident->unsetRelation('activeWaitingState');

        $this->assertTrue(app(WhatsAppOutboundCutoff::class)->shouldSkip($message));
    }

    public function test_journey_started_at_uses_the_earliest_existing_timestamp(): void
    {
        $message = $this->makeMessage(firstRequestedAt: '2026-08-20 10:00:00');
        IncidentWaitingState::query()->create([
            'incident_id' => $message->incident->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => '2026-08-18 08:35:00',
            'sla_paused' => true,
            'created_by' => $message->actor?->id,
        ]);
        $message->incident->unsetRelation('activeWaitingState');

        $startedAt = app(WhatsAppOutboundCutoff::class)->journeyStartedAt($message);

        $this->assertNotNull($startedAt);
        $this->assertSame('2026-08-18 08:35:00', $startedAt->format('Y-m-d H:i:s'));
    }

    public function test_pre_cutoff_notification_result_is_skipped_and_does_not_count_toward_success(): void
    {
        $result = NotificationResult::success(
            channel: NotificationChannelType::WhatsApp,
            message: WhatsAppOutboundCutoff::SKIPPED_MESSAGE,
            metadata: ['status' => WhatsAppOutboundCutoff::SKIPPED_STATUS],
        );

        $this->assertTrue($result->isSkipped());
        $this->assertFalse($result->countsTowardSuccess());
        $this->assertSame(WhatsAppOutboundCutoff::SKIPPED_STATUS, $result->status());
    }

    private function makeMessage(?string $firstRequestedAt = null): NotificationMessage
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-WA-CUTOFF-'.uniqid(),
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
            'missing_serial_first_requested_at' => $firstRequestedAt,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'WhatsApp outbound cutoff case',
            'description' => 'WhatsApp outbound cutoff case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return new NotificationMessage(
            type: NotificationType::RequestSerialNumber,
            customer: $order,
            incident: $incident,
            actor: $agent,
        );
    }
}

<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OutboxEventStatus;
use App\Enums\SupportAppointmentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Models\Incident;
use App\Models\InteraktWebhookLog;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Interakt\InteraktFlowWebhookOutboxWriter;
use App\Services\Interakt\InteraktFlowWebhookProcessorService;
use App\Services\Interakt\InteraktWebhookSignatureVerifier;
use App\Services\Interakt\WhatsAppFlowService;
use App\Services\SupportScheduleAvailabilityService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithInteraktWebhooks;
use Tests\TestCase;

class InteraktFlowWebhookTest extends TestCase
{
    use InteractsWithInteraktWebhooks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'interakt.verify_signature' => true,
            'interakt.webhook_secret' => 'test-interakt-webhook-secret',
            'interakt.flow_id' => '2559716037790863',
            'interakt.flow_token_ttl_hours' => 24,
        ]);

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_flow_webhook_books_support_appointment_from_decoded_response_json(): void
    {
        [$incident, $flowToken] = $this->createIncidentWithFlowToken();

        $payload = $this->officialFlowResponsePayload([
            'flow_token' => $flowToken,
            'preferred_date' => app(SupportScheduleAvailabilityService::class)->nextBookableDate()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9876543210',
            'additional_notes' => 'Booked via WhatsApp Flow.',
        ]);

        $response = $this->postSignedInteraktFlowWebhook($payload);

        $response->assertOk()->assertExactJson(['status' => 'ok']);

        $this->assertDatabaseHas('support_appointments', [
            'incident_id' => $incident->id,
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9876543210',
            'additional_notes' => 'Booked via WhatsApp Flow.',
        ]);

        $this->assertDatabaseHas('interakt_webhook_logs', [
            'event_type' => 'message_api_flow_response',
            'processing_status' => InteraktFlowWebhookProcessorService::STATUS_PROCESSED,
        ]);

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => InteraktFlowWebhookOutboxWriter::EVENT_TYPE,
            'status' => OutboxEventStatus::Completed->value,
        ]);

        $this->assertSame(1, SupportAppointment::query()->count());
    }

    public function test_duplicate_webhook_processing_creates_single_appointment(): void
    {
        [$incident, $flowToken] = $this->createIncidentWithFlowToken();

        $payload = $this->officialFlowResponsePayload([
            'flow_token' => $flowToken,
            'preferred_date' => app(SupportScheduleAvailabilityService::class)->nextBookableDate()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9876543210',
            'additional_notes' => 'Booked via WhatsApp Flow.',
        ]);

        $this->postSignedInteraktFlowWebhook($payload)
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);

        $webhookLog = InteraktWebhookLog::query()->latest('id')->first();
        $this->assertNotNull($webhookLog);

        app(InteraktFlowWebhookProcessorService::class)->process($webhookLog->fresh());

        $this->assertSame(1, SupportAppointment::query()->count());
        $this->assertSame(1, SupportAppointment::query()->where('status', SupportAppointmentStatus::Scheduled)->count());
        $this->assertDatabaseHas('support_appointments', [
            'incident_id' => $incident->id,
            'status' => SupportAppointmentStatus::Scheduled->value,
        ]);
    }

    public function test_flow_webhook_books_support_appointment_from_string_response_json(): void
    {
        [$incident, $flowToken] = $this->createIncidentWithFlowToken();

        $payload = $this->officialFlowResponsePayload(
            responseJson: [
                'flow_token' => $flowToken,
                'preferred_date' => now()->addDays(2)->toDateString(),
                'preferred_time_slot' => SupportAppointmentTimeSlot::Evening->value,
                'phone_number' => '9123456789',
            ],
            encodeResponseJson: true,
        );

        $this->postSignedInteraktFlowWebhook($payload)
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);

        $this->assertDatabaseHas('support_appointments', [
            'incident_id' => $incident->id,
            'preferred_time_slot' => SupportAppointmentTimeSlot::Evening->value,
            'phone_number' => '9123456789',
        ]);
    }

    public function test_flow_webhook_rejects_invalid_signature(): void
    {
        $payload = $this->officialFlowResponsePayload([
            'flow_token' => 'invalid-token',
            'preferred_date' => app(SupportScheduleAvailabilityService::class)->nextBookableDate()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9876543210',
        ]);

        $this->call(
            'POST',
            '/api/webhooks/interakt/flow',
            [],
            [],
            [],
            [
                'HTTP_Interakt-Signature' => 'sha256=deadbeef',
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode($payload, JSON_THROW_ON_ERROR),
        )->assertUnauthorized();

        $log = InteraktWebhookLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame(InteraktWebhookLog::STATUS_FAILED, $log->processing_status);
        $this->assertSame(InteraktWebhookSignatureVerifier::ERROR_INVALID_SIGNATURE, $log->processing_error);
        $this->assertDatabaseCount('support_appointments', 0);
    }

    public function test_flow_webhook_rejects_invalid_flow_token(): void
    {
        $payload = $this->officialFlowResponsePayload([
            'flow_token' => 'not-a-valid-token',
            'preferred_date' => app(SupportScheduleAvailabilityService::class)->nextBookableDate()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9876543210',
        ]);

        $this->postSignedInteraktFlowWebhook($payload)
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->assertDatabaseHas('interakt_webhook_logs', [
            'processing_status' => InteraktFlowWebhookProcessorService::STATUS_FAILED,
        ]);

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => InteraktFlowWebhookOutboxWriter::EVENT_TYPE,
            'status' => OutboxEventStatus::Failed->value,
        ]);

        $this->assertDatabaseCount('support_appointments', 0);
    }

    public function test_flow_webhook_rejects_malformed_response_json(): void
    {
        $payload = $this->officialFlowResponsePayload([]);
        data_set($payload, 'data.message.message.nfm_reply.response_json', '{not-json');

        $this->postSignedInteraktFlowWebhook($payload)
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->assertDatabaseCount('support_appointments', 0);
        $this->assertDatabaseHas('interakt_webhook_logs', [
            'processing_status' => InteraktFlowWebhookProcessorService::STATUS_FAILED,
        ]);
    }

    public function test_flow_webhook_ignores_unsupported_event_type(): void
    {
        $payload = $this->officialIncomingMessagePayload();

        $this->postSignedInteraktFlowWebhook($payload)
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);

        $this->assertDatabaseHas('interakt_webhook_logs', [
            'event_type' => 'message_received',
            'processing_status' => InteraktFlowWebhookProcessorService::STATUS_IGNORED,
        ]);

        $this->assertDatabaseCount('support_appointments', 0);
        $this->assertSame(0, OutboxEvent::query()->where('event_type', InteraktFlowWebhookOutboxWriter::EVENT_TYPE)->count());
    }

    /**
     * @return array{0: Incident, 1: string}
     */
    private function createIncidentWithFlowToken(): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-FLOW-WH-'.uniqid(),
            'serial_number' => 'SN-FLOW-WH-'.uniqid(),
            'product_name' => 'MFS 110 E3',
            'device_model' => 'MFS 110 E3',
            'transaction_id' => 'TXN-FLOW-WH-'.uniqid(),
            'customer_name' => 'Flow Webhook Customer',
            'customer_email' => 'flow-webhook@example.com',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Flow webhook case',
            'description' => 'Flow webhook case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $flowToken = app(WhatsAppFlowService::class)->generateToken($incident);

        return [$incident, $flowToken];
    }
}

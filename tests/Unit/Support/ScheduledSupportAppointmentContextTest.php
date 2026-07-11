<?php

namespace Tests\Unit\Support;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\AI\IncidentAIContextBuilder;
use App\Services\AI\IRAExecutiveSummaryService;
use App\Services\IncidentReferenceService;
use App\Support\Customer360\ScheduledSupportAppointmentContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduledSupportAppointmentContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_rd3445088_closed_incident_with_completed_appointment_returns_context(): void
    {
        $agent = User::factory()->create(['name' => 'Support Agent']);
        $order = Order::query()->create([
            'order_id' => 'RD3445088',
            'serial_number' => '7881953',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => 'TXN-3445088',
            'customer_name' => 'RD3445088 Customer',
            'customer_phone' => '98765445088',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'RD3445088',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Closed after scheduled support',
            'description' => 'Support completed and case closed.',
            'status' => IncidentStatus::Closed,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->subDays(3)->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => '98765445088',
            'status' => SupportAppointmentStatus::Completed,
        ]);

        $context = app(ScheduledSupportAppointmentContext::class)->forIncident($incident->fresh());

        $this->assertNotNull($context, 'Closed incidents must still expose appointment context to IRA.');
        $this->assertTrue($context['is_completed']);
        $this->assertFalse($context['is_active']);

        $aiContext = app(IncidentAIContextBuilder::class)->build($incident->fresh(), snapshot: new \App\Data\AI\AIContextBuildSnapshot(
            waitingStateCard: [
                'reason_label' => 'serial number',
                'lifecycle_history' => [
                    'customer_waiting_since' => now()->subDays(5),
                    'waiting_reason_label' => 'serial number',
                ],
            ],
        ));

        $this->assertNotNull($aiContext->supportAppointment);

        $bundle = app(\App\Services\AI\AIService::class)->buildBundle($incident->fresh(), new \App\Data\AI\AIContextBuildSnapshot(
            waitingStateCard: [
                'reason_label' => 'serial number',
                'lifecycle_history' => [
                    'customer_waiting_since' => now()->subDays(5),
                    'waiting_reason_label' => 'serial number',
                ],
            ],
            supportAppointment: $context,
        ));

        $summary = app(IRAExecutiveSummaryService::class)->buildFromBundle($incident->fresh(), $bundle, new \App\Data\AI\AIContextBuildSnapshot(
            customerSummary: ['open_cases' => 0, 'closed_cases' => 1],
            waitingStateCard: [
                'reason_label' => 'serial number',
                'lifecycle_history' => [
                    'customer_waiting_since' => now()->subDays(5),
                    'waiting_reason_label' => 'serial number',
                ],
            ],
            supportAppointment: $context,
        ));

        $payload = implode(' ', $summary->executiveSummary);

        $this->assertStringContainsString('Customer journey:', $payload);
        $this->assertStringContainsString('Customer booked support', $payload);
        $this->assertStringContainsString('Support completed', $payload);
        $this->assertStringContainsString('Service case closed', $payload);
        $this->assertStringNotContainsString('Previously waited for serial', $payload);
    }

    public function test_returns_latest_completed_appointment_for_closed_incident(): void
    {
        $agent = User::factory()->create(['name' => 'Assigned Agent']);
        $order = Order::query()->create([
            'order_id' => 'RD-CTX-CLOSED',
            'serial_number' => '7881960',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => 'TXN-CTX',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Closed with completed appointment',
            'description' => 'Done.',
            'status' => IncidentStatus::Closed,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->subDays(2)->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => '9876500001',
            'status' => SupportAppointmentStatus::Cancelled,
        ]);

        $completed = SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->subDay()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Afternoon,
            'phone_number' => '9876500001',
            'status' => SupportAppointmentStatus::Completed,
        ]);

        $context = app(ScheduledSupportAppointmentContext::class)->forIncident($incident->fresh());

        $this->assertNotNull($context);
        $this->assertSame(SupportAppointmentStatus::Completed, $context['status']);
        $this->assertTrue($context['is_completed']);
        $this->assertFalse($context['is_active']);
        $this->assertSame($completed->preferred_date->toDateString(), $context['preferred_date']->toDateString());
        $this->assertSame(SupportAppointmentTimeSlot::Afternoon, $context['preferred_time_slot']);
        $this->assertNotNull($context['completed_at']);
        $this->assertSame('Assigned', $context['assignee_name']);
    }

    public function test_prefers_latest_scheduled_appointment_when_one_exists(): void
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-CTX-SCHED',
            'serial_number' => '7881961',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => 'TXN-SCHED',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Scheduled overrides completed',
            'description' => 'Active appointment.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->subDay()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => '9876500002',
            'status' => SupportAppointmentStatus::Completed,
        ]);

        $scheduled = SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->addDay()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Evening,
            'phone_number' => '9876500002',
            'status' => SupportAppointmentStatus::Scheduled,
        ]);

        $context = app(ScheduledSupportAppointmentContext::class)->forIncident($incident->fresh());

        $this->assertNotNull($context);
        $this->assertSame(SupportAppointmentStatus::Scheduled, $context['status']);
        $this->assertTrue($context['is_active']);
        $this->assertFalse($context['is_completed']);
        $this->assertSame($scheduled->preferred_date->toDateString(), $context['preferred_date']->toDateString());
        $this->assertSame(SupportAppointmentTimeSlot::Evening, $context['preferred_time_slot']);
    }

    public function test_returns_null_when_no_appointment_exists(): void
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-CTX-NONE',
            'serial_number' => '7881962',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => 'TXN-CTX-NONE',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'No appointment',
            'description' => 'No appointment.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $this->assertNull(app(ScheduledSupportAppointmentContext::class)->forIncident($incident));
    }
}

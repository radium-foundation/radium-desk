<?php

namespace Tests\Unit\Support;

use App\Enums\AI\CustomerJourneyConclusionType;
use App\Enums\AI\CustomerJourneyMilestoneType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\AI\AIService;
use App\Services\AI\IRAExecutiveSummaryService;
use App\Services\AI\IncidentAIContextBuilder;
use App\Services\IncidentReferenceService;
use App\Support\Customer360\Journey\CustomerJourneyBuilder;
use App\Support\Customer360\ScheduledSupportAppointmentContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerJourneyIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_rd3445088_complete_journey_after_support(): void
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
            'payment_date' => now()->subDays(10),
            'payment_amount' => 15000,
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

        $journey = app(CustomerJourneyBuilder::class)->forIncident($incident->fresh());
        $titles = $journey->milestoneTitles();

        $this->assertContains(CustomerJourneyMilestoneType::PaymentReceived->label(), $titles);
        $this->assertContains(CustomerJourneyMilestoneType::DeviceIdentified->label(), $titles);
        $this->assertContains(CustomerJourneyMilestoneType::SerialVerified->label(), $titles);
        $this->assertContains(CustomerJourneyMilestoneType::SupportAppointmentBooked->label(), $titles);
        $this->assertContains(CustomerJourneyMilestoneType::SupportCompleted->label(), $titles);
        $this->assertContains(CustomerJourneyMilestoneType::Closed->label(), $titles);
        $this->assertSame(CustomerJourneyConclusionType::Complete, $journey->conclusion->type);
        $this->assertGreaterThanOrEqual(75, $journey->confidence->score);

        $bundle = app(AIService::class)->buildBundle($incident->fresh());
        $summary = app(IRAExecutiveSummaryService::class)->buildFromBundle($incident->fresh(), $bundle);
        $payload = implode(' ', $summary->executiveSummary);

        $this->assertStringContainsString('Customer journey:', $payload);
        $this->assertStringContainsString('Customer booked support', $payload);
        $this->assertStringContainsString('Support completed', $payload);
        $this->assertStringContainsString('Service case closed', $payload);
        $this->assertStringContainsString('Journey confidence:', $payload);
        $this->assertStringNotContainsString('Previously waited for serial', $payload);
        $this->assertStringContainsString('Customer already received support', $summary->opinion);
        $this->assertStringContainsString('No customer reminder required', $summary->recommendation);
    }

    public function test_rd3443036_serial_verification_journey_is_blocked(): void
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD3443036',
            'serial_number' => 'MIS100V2',
            'product_name' => 'Mantra MIS100',
            'device_model' => 'Mantra MIS100',
            'transaction_id' => 'TXN-3443036',
            'customer_name' => 'RD3443036 Customer',
            'customer_phone' => '98765443036',
            'payment_date' => now()->subDays(7),
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'RD3443036',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Suspicious serial',
            'description' => 'Serial needs correction.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $journey = app(CustomerJourneyBuilder::class)->forIncident($incident->fresh());

        $this->assertTrue($journey->hasMilestone(CustomerJourneyMilestoneType::SerialCorrectionRequested));
        $this->assertSame(CustomerJourneyConclusionType::Blocked, $journey->conclusion->type);
        $this->assertStringContainsString('Serial verification requested', implode(' ', $journey->milestoneTitles()));
    }

    public function test_reopened_after_support_conclusion(): void
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-JOURNEY-REOPEN',
            'serial_number' => '7882001',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => 'TXN-REOPEN',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Reopened after support',
            'description' => 'Issue returned.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->subDays(2)->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => '9876500100',
            'status' => SupportAppointmentStatus::Completed,
        ]);

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'service_case.status_changed',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'old_values' => ['status' => IncidentStatus::Closed->value],
            'new_values' => ['status' => IncidentStatus::Open->value],
            'created_at' => now()->subDay(),
        ]);

        $journey = app(CustomerJourneyBuilder::class)->forIncident($incident->fresh());

        $this->assertTrue($journey->hasMilestone(CustomerJourneyMilestoneType::Reopened));
        $this->assertTrue($journey->hasMilestone(CustomerJourneyMilestoneType::SupportCompleted));
        $this->assertSame(CustomerJourneyConclusionType::Reopened, $journey->conclusion->type);
        $this->assertStringContainsString('engineer review', $journey->conclusion->recommendation);
    }

    public function test_cancelled_appointment_journey_is_interrupted(): void
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-JOURNEY-CANCEL',
            'serial_number' => '7882002',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => 'TXN-CANCEL',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Cancelled appointment',
            'description' => 'Appointment cancelled.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->addDay()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Afternoon,
            'phone_number' => '9876500101',
            'status' => SupportAppointmentStatus::Cancelled,
        ]);

        $journey = app(CustomerJourneyBuilder::class)->forIncident($incident->fresh());

        $this->assertSame(CustomerJourneyConclusionType::Interrupted, $journey->conclusion->type);
        $this->assertStringContainsString('cancelled', $journey->conclusion->detail);
        $this->assertStringContainsString('rebooking', $journey->conclusion->recommendation);
        $this->assertContains('Cancelled appointment', $journey->confidence->negativeSignals);
    }

    public function test_waiting_for_serial_journey_is_blocked_with_low_confidence(): void
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-JOURNEY-SERIAL',
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => 'TXN-SERIAL',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Waiting for serial',
            'description' => 'Missing serial.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $context = app(IncidentAIContextBuilder::class)->build($incident->fresh(), snapshot: new \App\Data\AI\AIContextBuildSnapshot(
            waitingStateCard: [
                'reason_label' => 'serial number',
                'customer_waiting_since' => now()->subDays(2),
            ],
        ));

        $journey = $context->customerJourney;

        $this->assertNotNull($journey);
        $this->assertSame(CustomerJourneyConclusionType::Blocked, $journey->conclusion->type);
        $this->assertContains('Missing serial', $journey->confidence->negativeSignals);
        $this->assertStringContainsString('Customer reminder still required', $journey->conclusion->recommendation);
    }

    public function test_legacy_order_journey_includes_import_milestone(): void
    {
        $agent = User::factory()->create(['name' => 'Legacy Agent']);
        $order = Order::query()->create([
            'order_id' => 'RD-JOURNEY-LEGACY',
            'serial_number' => '7882003',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => 'TXN-LEGACY',
            'legacy_source' => 'lio',
            'legacy_imported_at' => now()->subMonths(2),
            'legacy_imported_by_user_id' => $agent->id,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Legacy order case',
            'description' => 'Legacy import.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $journey = app(CustomerJourneyBuilder::class)->forIncident($incident->fresh());

        $this->assertTrue($journey->hasMilestone(CustomerJourneyMilestoneType::OrderImported));
        $this->assertStringContainsString('Legacy order imported', implode(' ', $journey->milestoneTitles()));
    }

    public function test_journey_milestones_are_chronological(): void
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-JOURNEY-ORDER',
            'serial_number' => '7882004',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => 'TXN-ORDER',
            'payment_date' => now()->subDays(5),
            'status' => 'active',
            'created_by' => $agent->id,
            'created_at' => now()->subDays(6),
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Ordered journey',
            'description' => 'Check order.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'created_at' => now()->subDays(4),
        ]);

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->subDay()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => '9876500102',
            'status' => SupportAppointmentStatus::Completed,
            'created_at' => now()->subDays(2),
        ]);

        $journey = app(CustomerJourneyBuilder::class)->forIncident($incident->fresh());
        $timestamps = array_map(
            fn ($milestone) => $milestone->timestamp->timestamp,
            $journey->milestones,
        );

        $sorted = $timestamps;
        sort($sorted);

        $this->assertSame($sorted, $timestamps);
    }
}

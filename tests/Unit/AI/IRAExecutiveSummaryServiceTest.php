<?php

namespace Tests\Unit\AI;

use App\Data\AI\OperationalIntelligenceDTO;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\ServiceCaseSlaStatus;
use App\Enums\SupportAppointmentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\AI\IRAExecutiveSummaryService;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\AIContextFactory;
use Tests\TestCase;

class IRAExecutiveSummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_builds_serial_pending_executive_summary(): void
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-EXEC-SERIAL',
            'serial_number' => null,
            'product_name' => 'FM220',
            'device_model' => 'FM220',
            'customer_name' => 'Serial Pending Customer',
            'customer_phone' => '9123456780',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Serial pending',
            'description' => 'Missing serial.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
            'created_at' => now()->subDays(4),
        ]);

        $context = AIContextFactory::make([
            'serialMissing' => true,
            'deviceModel' => 'FM220',
            'warrantyStatus' => 'Not Available',
            'customerSummary' => ['open_cases' => 1],
            'operationalIntelligence' => new OperationalIntelligenceDTO(
                waitingState: null,
                slaState: ServiceCaseSlaStatus::Overdue->label(),
                priority: 'Normal',
                assignment: null,
                queuePosition: null,
                automationHistory: [],
                automationStatus: 'Idle',
                timelineSummary: 'No recent activity.',
                internalRemarksSummary: 'No internal remarks.',
            ),
        ]);

        $response = app(\App\Services\AI\AIService::class)->buildBundle($incident)->response;
        $summary = app(IRAExecutiveSummaryService::class)->build(
            incident: $incident,
            response: $response,
            context: $context,
            customerSummary: ['open_cases' => 1],
        );

        $this->assertLessThanOrEqual(4, count($summary->executiveSummary));
        $this->assertStringContainsString('serial number is still missing', $summary->executiveSummary[0]);
        $this->assertStringContainsString('beyond SLA', implode(' ', $summary->executiveSummary));
        $this->assertStringContainsString('blocked until the device serial number', $summary->opinion);
        $this->assertSame('Request the serial number from the customer immediately.', $summary->recommendation);
    }

    public function test_builds_suspicious_serial_warning_in_executive_summary(): void
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-EXEC-BAD-SERIAL',
            'serial_number' => '54SAXXC5514586',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Bad Serial Customer',
            'customer_phone' => '9123456781',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Bad serial',
            'description' => 'Invalid serial.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $context = AIContextFactory::make([
            'serialMissing' => false,
            'deviceModel' => 'MFS 110',
            'warrantyStatus' => 'Not Available',
            'customerSummary' => ['open_cases' => 1],
        ]);

        $response = app(\App\Services\AI\AIService::class)->buildBundle($incident)->response;
        $summary = app(IRAExecutiveSummaryService::class)->build(
            incident: $incident,
            response: $response,
            context: $context,
            customerSummary: ['open_cases' => 1],
        );

        $this->assertNotNull($summary->serialInsight);
        $this->assertSame('suspicious', $summary->serialInsight->status->value);
        $this->assertStringContainsString('Serial number needs verification', $summary->executiveSummary[0]);
        $this->assertStringContainsString('MFS 110', $summary->executiveSummary[0]);
        $this->assertStringNotContainsString('product code', $summary->executiveSummary[0]);
        $this->assertStringContainsString('incorrect', $summary->opinion);
        $this->assertSame(
            'Request the correct serial number from the customer before closing this case.',
            $summary->recommendation,
        );
    }

    public function test_english_output_contains_no_hindi_text(): void
    {
        $summary = $this->buildSuspiciousSerialSummary();

        $payload = implode("\n", [
            ...$summary->executiveSummary,
            $summary->opinion,
            $summary->recommendation,
            (string) $summary->serialInsight?->explanation,
            (string) $summary->serialInsight?->suggestedAction,
        ]);

        $this->assertDoesNotMatchRegularExpression('/[\x{0900}-\x{097F}]/u', $payload);
    }

    public function test_duplicate_ira_signals_are_merged(): void
    {
        $summary = $this->buildSuspiciousSerialSummary();

        $this->assertCount(1, $summary->executiveSummary);
        $this->assertStringNotContainsString('product code', implode(' ', $summary->executiveSummary));
        $this->assertStringNotContainsString('WhatsApp', $summary->recommendation);
        $this->assertStringNotContainsString('serial number needs verification', Str::lower($summary->opinion));
    }

    public function test_opinion_and_recommendation_are_single_sentence_actions(): void
    {
        $summary = $this->buildSuspiciousSerialSummary();

        $this->assertSame(1, substr_count($summary->opinion, '.'));
        $this->assertSame(1, substr_count($summary->recommendation, '.'));
        $this->assertLessThanOrEqual(4, count($summary->executiveSummary));
    }

    public function test_translation_service_translates_executive_summary_payload(): void
    {
        $translated = app(\App\Services\AI\IRAExecutiveSummaryTranslationService::class)
            ->translatePayloadToHindi([
                'executive_summary' => [
                    'Customer purchased an FM220 and currently has one active repair.',
                    'The device serial number is still missing, causing service delay.',
                ],
                'opinion' => 'This appears to be a straightforward serial-number pending case. Obtaining the serial should unblock warranty validation and allow engineering to proceed.',
                'recommendation' => 'Request the serial immediately, verify warranty once received, and proactively update the customer regarding SLA.',
            ]);

        $this->assertStringContainsString('ग्राहक ने खरीदा', $translated['executive_summary'][0]);
        $this->assertStringContainsString('सीरियल-नंबर लंबित केस', $translated['opinion']);
        $this->assertStringContainsString('तुरंत सीरियल माँगें', $translated['recommendation']);
    }

    public function test_scheduled_appointment_appears_in_executive_summary(): void
    {
        $agent = User::factory()->create(['name' => 'Support Agent']);
        $order = Order::query()->create([
            'order_id' => 'RD-EXEC-APPT',
            'serial_number' => '7881953',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => 'TXN-APPT',
            'customer_name' => 'Appointment Customer',
            'customer_phone' => '9123456790',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Scheduled support',
            'description' => 'Customer booked appointment.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->addDay()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => '9123456790',
            'status' => SupportAppointmentStatus::Scheduled,
        ]);

        $context = AIContextFactory::make([
            'deviceModel' => 'MFS 110',
            'warrantyStatus' => 'Active',
            'customerSummary' => ['open_cases' => 1],
            'supportAppointment' => $this->appointmentContext(
                preferredDate: now()->addDay(),
                status: SupportAppointmentStatus::Scheduled,
                slot: SupportAppointmentTimeSlot::Morning,
                assigneeName: 'Support',
            ),
        ]);

        $response = app(\App\Services\AI\AIService::class)->buildBundle($incident)->response;
        $summary = app(IRAExecutiveSummaryService::class)->build(
            incident: $incident,
            response: $response,
            context: $context,
            customerSummary: ['open_cases' => 1],
        );

        $payload = implode(' ', $summary->executiveSummary);

        $this->assertStringContainsString('scheduled support appointment', $payload);
        $this->assertStringContainsString('Morning (9 AM – 12 PM)', $payload);
        $this->assertSame('Await scheduled support.', $summary->opinion);
    }

    public function test_recommendation_changes_when_appointment_exists(): void
    {
        $agent = User::factory()->create(['name' => 'Ravi Agent']);
        $order = Order::query()->create([
            'order_id' => 'RD-EXEC-REC',
            'serial_number' => '7881953',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => 'TXN-REC',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Appointment case',
            'description' => 'Appointment case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $context = AIContextFactory::make([
            'serialMissing' => false,
            'supportAppointment' => $this->appointmentContext(
                preferredDate: now()->addDay(),
                status: SupportAppointmentStatus::Scheduled,
                slot: SupportAppointmentTimeSlot::Afternoon,
                assigneeName: 'Ravi',
            ),
        ]);

        $response = app(\App\Services\AI\AIService::class)->buildBundle($incident)->response;
        $summary = app(IRAExecutiveSummaryService::class)->build(
            incident: $incident,
            response: $response,
            context: $context,
            customerSummary: ['open_cases' => 1],
        );

        $this->assertStringContainsString('contact the customer as scheduled', Str::lower($summary->recommendation));
        $this->assertStringContainsString('Ravi', $summary->recommendation);
        $this->assertStringNotContainsString('Review incident details', $summary->recommendation);
        $this->assertSame('Await scheduled support.', $summary->opinion);
    }

    public function test_completed_appointment_on_closed_incident_appears_in_executive_summary(): void
    {
        $agent = User::factory()->create(['name' => 'Support Agent']);
        $order = Order::query()->create([
            'order_id' => 'RD-EXEC-COMPLETE',
            'serial_number' => '7881954',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => 'TXN-COMPLETE',
            'customer_name' => 'Completed Customer',
            'customer_phone' => '9123456791',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Completed support',
            'description' => 'Support completed.',
            'status' => IncidentStatus::Closed,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $preferredDate = now()->subDay();

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => $preferredDate->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => '9123456791',
            'status' => SupportAppointmentStatus::Completed,
        ]);

        $context = AIContextFactory::make([
            'incidentStatus' => IncidentStatus::Closed->label(),
            'deviceModel' => 'MFS 110',
            'customerSummary' => ['open_cases' => 0, 'closed_cases' => 1],
            'supportAppointment' => $this->appointmentContext(
                preferredDate: $preferredDate,
                status: SupportAppointmentStatus::Completed,
                slot: SupportAppointmentTimeSlot::Morning,
                assigneeName: 'Support',
            ),
        ]);

        $response = app(\App\Services\AI\AIService::class)->buildBundle($incident)->response;
        $summary = app(IRAExecutiveSummaryService::class)->build(
            incident: $incident,
            response: $response,
            context: $context,
            customerSummary: ['open_cases' => 0, 'closed_cases' => 1],
        );

        $payload = implode(' ', $summary->executiveSummary);

        $this->assertStringContainsString('Customer booked support on', $payload);
        $this->assertStringContainsString('Support completed', $payload);
        $this->assertStringContainsString('Service case is now closed', $payload);
        $this->assertSame(
            'Customer has already received scheduled support. No further appointment reminders are required.',
            $summary->opinion,
        );
        $this->assertSame(
            'Review support outcome. Reopen only if customer reports the issue persists.',
            $summary->recommendation,
        );
    }

    public function test_cancelled_appointment_is_explained_in_executive_summary(): void
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-EXEC-CANCEL',
            'serial_number' => '7881955',
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

        $preferredDate = now()->addDay();
        $context = AIContextFactory::make([
            'supportAppointment' => $this->appointmentContext(
                preferredDate: $preferredDate,
                status: SupportAppointmentStatus::Cancelled,
                slot: SupportAppointmentTimeSlot::Afternoon,
            ),
        ]);

        $response = app(\App\Services\AI\AIService::class)->buildBundle($incident)->response;
        $summary = app(IRAExecutiveSummaryService::class)->build(
            incident: $incident,
            response: $response,
            context: $context,
            customerSummary: ['open_cases' => 1],
        );

        $payload = implode(' ', $summary->executiveSummary);

        $this->assertStringContainsString('cancelled the support appointment', $payload);
        $this->assertStringContainsString('confirm whether the customer still needs assistance', Str::lower($summary->opinion));
        $this->assertStringContainsString('rebook', Str::lower($summary->recommendation));
    }

    public function test_waiting_state_is_ignored_when_completed_appointment_exists(): void
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-EXEC-WAIT',
            'serial_number' => '7881956',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => 'TXN-WAIT',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Completed with waiting history',
            'description' => 'Completed support after waiting.',
            'status' => IncidentStatus::Closed,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $context = AIContextFactory::make([
            'incidentStatus' => IncidentStatus::Closed->label(),
            'waitingState' => [
                'reason_label' => 'serial number',
                'lifecycle_history' => [
                    'customer_waiting_since' => now()->subDays(3),
                    'waiting_reason_label' => 'serial number',
                ],
            ],
            'supportAppointment' => $this->appointmentContext(
                preferredDate: now()->subDay(),
                status: SupportAppointmentStatus::Completed,
                slot: SupportAppointmentTimeSlot::Morning,
            ),
        ]);

        $response = app(\App\Services\AI\AIService::class)->buildBundle($incident)->response;
        $summary = app(IRAExecutiveSummaryService::class)->build(
            incident: $incident,
            response: $response,
            context: $context,
            customerSummary: ['open_cases' => 0, 'closed_cases' => 1],
        );

        $payload = implode(' ', $summary->executiveSummary);

        $this->assertStringNotContainsString('Previously waited for serial', $payload);
        $this->assertStringContainsString('Support completed', $payload);
    }

    public function test_legacy_case_without_appointment_still_uses_waiting_state(): void
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-EXEC-LEGACY',
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => 'TXN-LEGACY',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Legacy waiting case',
            'description' => 'Waiting for serial.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $waitingSince = now()->subDays(2);
        $context = AIContextFactory::make([
            'serialMissing' => true,
            'waitingState' => [
                'reason_label' => 'serial number',
                'customer_waiting_since' => $waitingSince,
            ],
            'supportAppointment' => null,
        ]);

        $response = app(\App\Services\AI\AIService::class)->buildBundle($incident)->response;
        $summary = app(IRAExecutiveSummaryService::class)->build(
            incident: $incident,
            response: $response,
            context: $context,
            customerSummary: ['open_cases' => 1],
        );

        $payload = implode(' ', $summary->executiveSummary);

        $this->assertStringContainsString('Waiting for serial number since', $payload);
        $this->assertStringContainsString('blocked until the device serial number', $summary->opinion);
    }

    /**
     * @return array{
     *     status: SupportAppointmentStatus,
     *     preferred_date: \Illuminate\Support\Carbon,
     *     preferred_time_slot: SupportAppointmentTimeSlot,
     *     time_slot_label: string,
     *     created_at: \Illuminate\Support\Carbon,
     *     updated_at: \Illuminate\Support\Carbon,
     *     completed_at: ?\Illuminate\Support\Carbon,
     *     assignee_name: ?string,
     *     is_active: bool,
     *     is_completed: bool,
     * }
     */
    private function appointmentContext(
        \Illuminate\Support\Carbon $preferredDate,
        SupportAppointmentStatus $status,
        SupportAppointmentTimeSlot $slot,
        ?string $assigneeName = null,
    ): array {
        $isCompleted = $status === SupportAppointmentStatus::Completed;
        $timestamp = now();

        return [
            'status' => $status,
            'preferred_date' => $preferredDate,
            'preferred_time_slot' => $slot,
            'time_slot_label' => $slot->label(),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'completed_at' => $isCompleted ? $timestamp : null,
            'assignee_name' => $assigneeName,
            'is_active' => $status === SupportAppointmentStatus::Scheduled,
            'is_completed' => $isCompleted,
        ];
    }

    private function buildSuspiciousSerialSummary(): \App\Data\AI\IRAExecutiveSummaryDTO
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-EXEC-ENGLISH',
            'serial_number' => '54SAXXC5514586',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'English Output Customer',
            'customer_phone' => '9123456789',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'English output case',
            'description' => 'Bad serial.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $context = AIContextFactory::make([
            'serialMissing' => false,
            'deviceModel' => 'MFS 110',
            'warrantyStatus' => 'Not Available',
            'customerSummary' => ['open_cases' => 1],
        ]);

        $response = app(\App\Services\AI\AIService::class)->buildBundle($incident)->response;

        return app(IRAExecutiveSummaryService::class)->build(
            incident: $incident,
            response: $response,
            context: $context,
            customerSummary: ['open_cases' => 1],
        );
    }
}

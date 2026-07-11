<?php

namespace Tests\Feature;

use App\Enums\BonvoiceCallLinkType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\WaitingReason;
use App\Models\AuditLog;
use App\Models\BonvoiceCallEvent;
use App\Models\Incident;
use App\Models\IncidentBonvoiceCallLink;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\User;
use App\Services\Customer360\CustomerContactAttemptEvidenceService;
use App\Services\IncidentReferenceService;
use App\Services\Interakt\CustomerNotRespondingEligibilityService;
use App\Services\Interakt\RequestCorrectSerialEligibilityService;
use App\Services\Interakt\RequestSerialNumberEligibilityService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class Customer360ActionVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'interakt.templates.request_serial_number.name' => 'order_update_request_serial',
            'interakt.templates.request_correct_serial.name' => 'order_update_request_correct_serial',
            'interakt.templates.callback_schedule.name' => 'callback_schedule',
        ]);

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_fresh_case_does_not_show_customer_not_responding(): void
    {
        [$agent, $incident] = $this->createAssignedIncident([
            'serial_number' => '9620545',
        ]);

        $html = $this->customer360Html($agent, $incident);

        $this->assertStringNotContainsString('Customer unreachable', $html);
        $this->assertStringNotContainsString('Customer Not Responding', $html);
        $this->assertFalse(app(CustomerNotRespondingEligibilityService::class)->canShowAction($incident));
    }

    public function test_failed_call_shows_customer_not_responding(): void
    {
        [$agent, $incident] = $this->createAssignedIncident([
            'serial_number' => '9620545',
            'customer_phone' => '9123456782',
        ]);

        $this->seedUnreachableBonvoiceCall($incident, 'NOANSWER');

        $html = $this->customer360Html($agent, $incident->fresh());

        $this->assertStringContainsString('Customer Not Responding', $html);
        $this->assertStringContainsString('data-workspace-trigger="customer-not-responding"', $html);
        $this->assertTrue(app(CustomerNotRespondingEligibilityService::class)->canShowAction($incident->fresh()));
    }

    public function test_manual_call_attempt_log_shows_customer_not_responding(): void
    {
        [$agent, $incident] = $this->createAssignedIncident([
            'serial_number' => '9620545',
        ]);

        AuditLog::query()->create([
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'event' => CustomerContactAttemptEvidenceService::MANUAL_CALL_ATTEMPT_EVENT,
            'user_id' => $agent->id,
            'new_values' => ['channel' => 'phone'],
        ]);

        $this->assertTrue(app(CustomerNotRespondingEligibilityService::class)->canShowAction($incident->fresh()));
    }

    public function test_waiting_case_hides_recommended_actions(): void
    {
        [$agent, $incident] = $this->createAssignedIncident([
            'serial_number' => null,
        ]);

        IncidentWaitingState::query()->create([
            'incident_id' => $incident->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => Carbon::parse('2026-07-10 09:00:00'),
            'sla_paused' => true,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $html = $this->customer360Html($agent, $incident->fresh(['activeWaitingState']));

        $this->assertStringContainsString('Waiting for customer response', $html);
        $this->assertStringContainsString('Serial Number', $html);
        $this->assertStringNotContainsString('Recommended Actions', $html);
        $this->assertStringNotContainsString('Serial number missing', $html);
        $this->assertStringNotContainsString('Customer unreachable', $html);
        $this->assertStringNotContainsString('Customer Not Responding', $html);
        $this->assertStringContainsString('data-customer-360-section="quick-actions"', $html);
        $this->assertStringContainsString('Call', $html);
    }

    public function test_request_serial_only_appears_when_serial_is_missing(): void
    {
        [$agent, $missingSerialIncident] = $this->createAssignedIncident([
            'order_id' => 'RD-VIS-MISSING',
            'serial_number' => null,
            'cashfree_payment_id' => 'cf_pay_vis_missing',
            'payment_date' => now()->subHour(),
        ]);

        $this->assertTrue(app(RequestSerialNumberEligibilityService::class)->canShowAction($missingSerialIncident));

        $missingSerialHtml = $this->customer360Html($agent, $missingSerialIncident);
        $this->assertStringContainsString('data-workspace-trigger="request-serial"', $missingSerialHtml);
        $this->assertStringContainsString('Request Serial', $missingSerialHtml);

        [$agent, $resolvedSerialIncident] = $this->createAssignedIncident([
            'order_id' => 'RD-VIS-RESOLVED',
            'serial_number' => '9620545',
        ]);

        $this->assertFalse(app(RequestSerialNumberEligibilityService::class)->canShowAction($resolvedSerialIncident));
        $this->assertStringNotContainsString(
            'Serial number missing',
            $this->customer360Html($agent, $resolvedSerialIncident),
        );
    }

    public function test_production_rd3443036_product_code_serial_shows_request_correct_serial_only(): void
    {
        [$agent, $incident] = $this->createAssignedIncident([
            'order_id' => 'RD3443036',
            'serial_number' => 'MIS100V2',
            'product_name' => 'Mantra MIS100',
            'device_model' => 'Mantra MIS100',
        ]);

        $this->assertFalse(app(RequestSerialNumberEligibilityService::class)->canShowAction($incident));
        $this->assertTrue(app(RequestCorrectSerialEligibilityService::class)->canShowAction($incident));

        $html = $this->customer360Html($agent, $incident);
        $this->assertStringContainsString('data-workspace-trigger="request-correct-serial"', $html);
        $this->assertStringContainsString('Request Correct Serial', $html);
        $this->assertStringNotContainsString('data-workspace-trigger="request-serial"', $html);
        $this->assertStringNotContainsString('Serial number missing', $html);
    }

    public function test_request_correct_serial_only_appears_for_suspicious_serial(): void
    {
        [$agent, $incident] = $this->createAssignedIncident([
            'serial_number' => '54SAXXC5514586',
        ]);

        $this->assertFalse(app(RequestSerialNumberEligibilityService::class)->canShowAction($incident));
        $this->assertTrue(app(RequestCorrectSerialEligibilityService::class)->canShowAction($incident));

        $html = $this->customer360Html($agent, $incident);
        $this->assertStringContainsString('data-workspace-trigger="request-correct-serial"', $html);
        $this->assertStringContainsString('Request Correct Serial', $html);
        $this->assertStringNotContainsString('data-workspace-trigger="request-serial"', $html);
        $this->assertStringNotContainsString('Serial number missing', $html);

        [$agent, $validIncident] = $this->createAssignedIncident([
            'serial_number' => '9620545',
        ]);

        $this->assertFalse(app(RequestCorrectSerialEligibilityService::class)->canShowAction($validIncident));
        $this->assertStringNotContainsString(
            'Serial number needs verification',
            $this->customer360Html($agent, $validIncident),
        );
    }

    public function test_needs_verification_warning_serial_maps_to_request_correct_serial_action(): void
    {
        [$agent, $incident] = $this->createAssignedIncident([
            'order_id' => 'RD-VIS-WARNING',
            'serial_number' => 'B47C11929',
            'product_name' => 'Access FM220 L1',
            'device_model' => 'Access FM220 L1',
        ]);

        $insight = app(\App\Services\SerialValidation\SerialInsightService::class)->analyze($incident->order);
        $this->assertSame('warning', $insight->status->value);
        $this->assertSame('Needs verification', $insight->status->label());
        $this->assertFalse(app(RequestSerialNumberEligibilityService::class)->canShowAction($incident));
        $this->assertTrue(app(RequestCorrectSerialEligibilityService::class)->canShowAction($incident));

        $html = $this->customer360Html($agent, $incident);
        $this->assertStringContainsString('data-workspace-trigger="request-correct-serial"', $html);
        $this->assertStringContainsString('Request Correct Serial', $html);
        $this->assertStringNotContainsString('data-workspace-trigger="request-serial"', $html);
        $this->assertStringNotContainsString('Serial number missing', $html);
    }

    public function test_needs_verification_hides_recommended_action_while_waiting_for_customer(): void
    {
        [$agent, $incident] = $this->createAssignedIncident([
            'order_id' => 'RD-VIS-WAITING-WARN',
            'serial_number' => 'B47C11929',
            'product_name' => 'Access FM220 L1',
            'device_model' => 'Access FM220 L1',
        ]);

        IncidentWaitingState::query()->create([
            'incident_id' => $incident->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => Carbon::parse('2026-07-10 09:00:00'),
            'sla_paused' => true,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $incident = $incident->fresh(['activeWaitingState', 'order']);

        $this->assertTrue(app(RequestCorrectSerialEligibilityService::class)->canShowAction($incident));
        $this->assertFalse(app(\App\Services\Customer360\Customer360ActionVisibilityService::class)->forIncident($incident)['canRequestCorrectSerial']);

        $html = $this->customer360Html($agent, $incident);
        $this->assertStringNotContainsString('Recommended Actions', $html);
        $this->assertStringNotContainsString('Request Correct Serial', $html);
    }

    /**
     * @param  array<string, mixed>  $orderOverrides
     * @return array{0: User, 1: Incident}
     */
    private function createAssignedIncident(array $orderOverrides = []): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create(array_merge([
            'order_id' => 'RD-VIS-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Visibility Customer',
            'customer_email' => 'visibility@example.com',
            'customer_phone' => '9123456782',
            'status' => 'active',
            'created_by' => $agent->id,
        ], $orderOverrides));

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Visibility case',
            'description' => 'Visibility case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return [$agent, $incident];
    }

    private function seedUnreachableBonvoiceCall(Incident $incident, string $status): void
    {
        $incident->loadMissing('order');
        $phone = $incident->order?->customer_phone;

        $event = BonvoiceCallEvent::query()->create([
            'call_id' => 'call-vis-'.uniqid(),
            'leg' => 'A',
            'event_id' => 'evt-vis-'.uniqid(),
            'status' => $status,
            'direction' => 'Outbound',
            'customer_phone' => $phone,
            'started_at' => now(),
            'payload' => [],
        ]);

        IncidentBonvoiceCallLink::query()->create([
            'incident_id' => $incident->id,
            'bonvoice_call_event_id' => $event->id,
            'call_id' => $event->call_id,
            'link_type' => BonvoiceCallLinkType::Missed,
            'linked_at' => now(),
        ]);
    }

    private function customer360Html(User $agent, Incident $incident): string
    {
        return $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->getContent();
    }
}

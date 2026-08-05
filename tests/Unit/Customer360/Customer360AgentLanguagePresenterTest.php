<?php

namespace Tests\Unit\Customer360;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\Customer360\Intelligence\CaseIntelligenceEngine;
use App\Services\IncidentReferenceService;
use App\Support\Customer360\Customer360AgentLanguagePresenter;
use App\Support\Customer360\Customer360IraPanelPresenter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Customer360AgentLanguagePresenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['ira.case_intelligence_engine.enabled' => true]);
    }

    public function test_response_overdue_compact_and_tooltip(): void
    {
        $from = now()->subHours(2);

        $this->assertSame('RO 2h', Customer360AgentLanguagePresenter::responseOverdueCompact($from));
        $this->assertStringStartsWith('Response overdue by ', Customer360AgentLanguagePresenter::responseOverdueTooltip($from));
    }

    public function test_agent_priority_label_mapping(): void
    {
        $this->assertSame('High', Customer360AgentLanguagePresenter::agentPriorityLabel('critical'));
        $this->assertSame('Medium', Customer360AgentLanguagePresenter::agentPriorityLabel('high'));
        $this->assertSame('Normal', Customer360AgentLanguagePresenter::agentPriorityLabel('medium'));
        $this->assertSame('Low', Customer360AgentLanguagePresenter::agentPriorityLabel('low'));
        $this->assertSame('Medium', Customer360AgentLanguagePresenter::agentPriorityLabel('normal', highPriorityFlag: true));
    }

    public function test_current_stage_and_appointment_condition_separate_location_from_state(): void
    {
        [$incident, , $agent] = $this->createIncident();
        $this->actingAs($agent);

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->subDays(3)->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => $incident->order->customer_phone,
            'normalized_phone' => $incident->order->customer_phone,
            'status' => SupportAppointmentStatus::Scheduled,
        ]);

        $snapshot = app(CaseIntelligenceEngine::class)->build($incident->fresh(['order', 'assignee', 'activeWaitingState']), true);
        $this->assertNotNull($snapshot);

        $panel = app(Customer360IraPanelPresenter::class)->present(
            snapshot: $snapshot,
            incident: $incident->fresh(['assignee', 'order']),
        );

        $brief = collect($panel['executive_brief'])->keyBy('label');

        $this->assertSame('Support Appointment', $brief['Current Stage']['value']);
        $this->assertStringStartsWith('Overdue (', $brief['Appointment']['value']);
        $this->assertStringNotContainsString('SLA', implode(' ', array_column($panel['executive_brief'], 'label')));
        $this->assertArrayNotHasKey('SLA', $brief->all());
    }

    public function test_executive_brief_uses_assigned_to_and_case_delay(): void
    {
        [$incident, , $agent] = $this->createIncident(['high_priority' => true]);
        $agent->forceFill(['name' => 'Jayram'])->save();
        $incident->forceFill([
            'assigned_to_user_id' => $agent->id,
            'created_at' => now()->subHours(2),
        ])->save();

        $this->actingAs($agent);

        $snapshot = app(CaseIntelligenceEngine::class)->build($incident->fresh(['order', 'assignee']), true);
        $this->assertNotNull($snapshot);

        $panel = app(Customer360IraPanelPresenter::class)->present(
            snapshot: $snapshot,
            incident: $incident->fresh(['assignee', 'order']),
        );

        $labels = array_column($panel['executive_brief'], 'label');
        $this->assertContains('Assigned To', $labels);
        $this->assertContains('Current Stage', $labels);
        $this->assertNotContains('Current Status', $labels);
        $this->assertNotContains('Current Owner', $labels);
        $this->assertSame('Assigned To', $panel['case_contributors'][0]['role']);

        $priorityRow = collect($panel['executive_brief'])->firstWhere('label', 'Priority');
        $this->assertNotNull($priorityRow);
        $this->assertSame(
            Customer360AgentLanguagePresenter::agentPriorityLabel(
                $snapshot->priorityLevel,
                (bool) $incident->high_priority,
            ),
            $priorityRow['value'],
        );
    }

    public function test_overdue_attention_chip_prefers_appointment_over_verification(): void
    {
        [$incident] = $this->createIncident();

        $appointment = [
            'is_active' => true,
            'is_completed' => false,
            'preferred_date' => now()->subDay(),
        ];

        $chip = Customer360AgentLanguagePresenter::overdueAttentionChip($incident, $appointment);

        $this->assertSame('Appointment Overdue', $chip['label'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $incidentOverrides
     * @return array{0: Incident, 1: Order, 2: User}
     */
    private function createIncident(array $incidentOverrides = []): array
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-AGENT-LANG',
            'serial_number' => null,
            'product_name' => 'FM220',
            'device_model' => 'FM220',
            'customer_name' => 'Agent Lang Customer',
            'customer_phone' => '9123456703',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create(array_merge([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Agent language case',
            'description' => 'Missing serial.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ], $incidentOverrides));

        return [$incident->fresh(['order', 'assignee', 'activeWaitingState']), $order, $agent];
    }
}

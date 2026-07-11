<?php

namespace Tests\Unit;

use App\Data\AI\CustomerJourneyConclusionDTO;
use App\Data\AI\CustomerJourneyConfidenceDTO;
use App\Data\AI\CustomerJourneyDTO;
use App\Enums\AI\CustomerJourneyConclusionType;
use App\Enums\AI\AIConfidenceLevel;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\WaitingReason;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Support\Customer360\Customer360IraAdvisorPresenter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Customer360IraAdvisorPresenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_present_recommends_wait_when_customer_is_waiting(): void
    {
        [$incident, $order] = $this->createIncident();

        IncidentWaitingState::query()->create([
            'incident_id' => $incident->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => now()->subDay(),
            'sla_paused' => true,
            'created_by' => $incident->created_by,
        ]);

        $viewModel = app(Customer360IraAdvisorPresenter::class)->present(
            $this->baseContext($incident, $order, [
                'isWaitingForCustomer' => true,
            ], [
                'reason_label' => 'Serial Number',
                'waiting_duration_label' => '1 day',
                'sla_paused' => true,
            ]),
        );

        $this->assertNotNull($viewModel);
        $this->assertSame('wait', $viewModel['recommended_action']['key']);
        $this->assertSame('high', $viewModel['confidence']['level']);
        $this->assertSame('active_waiting_state', $viewModel['rule_context']['matched_rule']);
        $this->assertStringContainsString('Serial Number', implode(' ', $viewModel['reasons']));
    }

    public function test_present_recommends_request_serial_when_serial_is_missing(): void
    {
        [$incident, $order] = $this->createIncident(serialNumber: null);

        $viewModel = app(Customer360IraAdvisorPresenter::class)->present(
            $this->baseContext($incident, $order, [
                'canRequestSerialNumber' => true,
            ]),
        );

        $this->assertNotNull($viewModel);
        $this->assertSame('request_serial', $viewModel['recommended_action']['key']);
        $this->assertSame('serial_missing', $viewModel['rule_context']['matched_rule']);
    }

    public function test_present_recommends_verify_identity_when_correction_is_available(): void
    {
        [$incident, $order] = $this->createIncident();

        $viewModel = app(Customer360IraAdvisorPresenter::class)->present(
            $this->baseContext($incident, $order, [
                'canRequestCorrectSerial' => true,
            ]),
        );

        $this->assertNotNull($viewModel);
        $this->assertSame('verify_identity', $viewModel['recommended_action']['key']);
        $this->assertSame('identity_verification_required', $viewModel['rule_context']['matched_rule']);
    }

    public function test_present_returns_null_for_closed_cases(): void
    {
        [$incident, $order] = $this->createIncident(status: IncidentStatus::Closed);

        $viewModel = app(Customer360IraAdvisorPresenter::class)->present(
            $this->baseContext($incident, $order),
        );

        $this->assertNull($viewModel);
    }

    public function test_present_includes_secondary_actions_and_rule_context_for_future_llm(): void
    {
        [$incident, $order] = $this->createIncident();

        $viewModel = app(Customer360IraAdvisorPresenter::class)->present(
            $this->baseContext($incident, $order, canEscalate: true),
        );

        $this->assertNotNull($viewModel);
        $this->assertNotEmpty($viewModel['secondary_actions']);
        $this->assertArrayHasKey('matched_rule', $viewModel['rule_context']);
        $this->assertArrayHasKey('signals', $viewModel['rule_context']);
        $this->assertArrayHasKey('journey_conclusion', $viewModel['rule_context']);
    }

    /**
     * @return array{0: Incident, 1: Order}
     */
    private function createIncident(
        ?string $serialNumber = 'SN-IRA-ADVISOR',
        IncidentStatus $status = IncidentStatus::Open,
    ): array {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-IRA-ADVISOR',
            'serial_number' => $serialNumber,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876502222',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'IRA advisor test case',
            'description' => 'IRA advisor test case.',
            'status' => $status,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return [$incident->fresh(), $order];
    }

    /**
     * @param  array<string, bool>  $visibilityOverrides
     * @param  ?array<string, mixed>  $waitingStateCard
     * @return array<string, mixed>
     */
    private function baseContext(
        Incident $incident,
        Order $order,
        array $visibilityOverrides = [],
        ?array $waitingStateCard = null,
        bool $canEscalate = false,
    ): array {
        return [
            'incident' => $incident,
            'order' => $order,
            'customerSummary' => [
                'total_orders' => 1,
                'open_cases' => 1,
                'closed_cases' => 0,
            ],
            'healthCardViewModel' => [
                'preferred_channel' => 'WhatsApp',
                'total_appointments' => 0,
                'missed_appointments' => 0,
            ],
            'waitingStateCard' => $waitingStateCard,
            'supportAppointment' => null,
            'customerJourney' => new CustomerJourneyDTO(
                milestones: [],
                conclusion: new CustomerJourneyConclusionDTO(
                    type: CustomerJourneyConclusionType::InProgress,
                    headline: CustomerJourneyConclusionType::InProgress->label(),
                    detail: 'Service case is progressing through standard handling.',
                    recommendation: 'Review incident details and contact the customer with the next update.',
                ),
                confidence: new CustomerJourneyConfidenceDTO(
                    score: 70,
                    level: AIConfidenceLevel::Medium,
                    positiveSignals: [],
                    negativeSignals: [],
                ),
            ),
            'slaMetrics' => null,
            'operationsAdvisorInsights' => [],
            'actionVisibility' => array_merge([
                'isWaitingForCustomer' => false,
                'hideWorkflowActions' => false,
                'canRequestSerialNumber' => false,
                'canRequestCorrectSerial' => false,
                'canCustomerNotResponding' => false,
                'canLinkOrder' => false,
                'canCorrectCustomerDetails' => false,
                'canCorrectSerialNumber' => false,
                'hasRecommendedActions' => false,
            ], $visibilityOverrides),
            'canEscalate' => $canEscalate,
        ];
    }
}

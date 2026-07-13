<?php

namespace Tests\Unit\CommunicationActions;

use App\Data\CommunicationActions\CommunicationActionExecutionContext;
use App\Enums\CommunicationActionExecutionMode;
use App\Enums\CommunicationActionKey;
use App\Enums\CommunicationActionTriggerSource;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\NotificationChannelType;
use App\Enums\WhatsAppTemplateTriggerSource;
use App\Enums\WorkspaceContext;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\CommunicationActions\CommunicationActionExecutionContextFactory;
use App\Services\CommunicationActions\CommunicationActionRegistry;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CommunicationActionExecutionContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_context_is_immutable_and_supports_with_methods(): void
    {
        $agent = User::factory()->create();
        [$incident, $definition] = $this->createIncidentWithDefinition($agent);

        $context = CommunicationActionExecutionContext::initial(
            action: $definition,
            incident: $incident,
            operator: $agent,
            executionMode: CommunicationActionExecutionMode::Manual,
            triggerSource: CommunicationActionTriggerSource::Customer360,
            operatorInput: ['refund_amount' => '100'],
            timestamp: Carbon::parse('2026-07-13 10:00:00'),
        );

        $updated = $context
            ->withEligibleChannels([NotificationChannelType::WhatsApp])
            ->withSelectedChannels([NotificationChannelType::WhatsApp])
            ->withResolvedVariables(['customer_name' => 'Test Customer'])
            ->withMetadata(['trace_id' => 'abc-123']);

        $this->assertSame([], $context->eligibleChannels);
        $this->assertSame([], $context->resolvedVariables);
        $this->assertArrayNotHasKey('trace_id', $context->metadata);

        $this->assertSame([NotificationChannelType::WhatsApp], $updated->eligibleChannels);
        $this->assertSame(['customer_name' => 'Test Customer'], $updated->resolvedVariables);
        $this->assertSame('abc-123', $updated->metadata['trace_id']);
        $this->assertSame(['refund_amount' => '100'], $updated->operatorInput());
    }

    public function test_to_notification_metadata_preserves_customer360_manual_dispatch_shape(): void
    {
        $agent = User::factory()->create();
        [$incident, $definition] = $this->createIncidentWithDefinition($agent);

        $context = CommunicationActionExecutionContext::initial(
            action: $definition,
            incident: $incident,
            operator: $agent,
            executionMode: CommunicationActionExecutionMode::Manual,
            triggerSource: CommunicationActionTriggerSource::Customer360,
        );

        $metadata = $context->toNotificationMetadata();

        $this->assertSame('customer360', $metadata['source']);
        $this->assertSame('review_request', $metadata['communication_action_key']);
        $this->assertSame('Review request sent', $metadata['communication_action_label']);
        $this->assertSame(WhatsAppTemplateTriggerSource::Manual->value, $metadata['trigger_source']);
        $this->assertSame('customer360', $metadata['communication_action_trigger_source']);
        $this->assertSame('manual', $metadata['communication_action_execution_mode']);
    }

    public function test_factory_builds_workspace_execution_context(): void
    {
        $agent = User::factory()->create();
        [$incident, $definition] = $this->createIncidentWithDefinition($agent);

        $factory = app(CommunicationActionExecutionContextFactory::class);

        $context = $factory->forWorkspaceExecution(
            action: $definition,
            incident: $incident,
            operator: $agent,
            workspaceContext: WorkspaceContext::Customer,
            operatorInput: [],
            selectedChannelValues: ['whatsapp'],
        );

        $this->assertSame(CommunicationActionTriggerSource::Customer360, $context->triggerSource);
        $this->assertSame(CommunicationActionExecutionMode::Manual, $context->executionMode);
        $this->assertSame($incident->order?->id, $context->customer?->id);
        $this->assertSame([NotificationChannelType::WhatsApp], $context->selectedChannels);
        $this->assertSame('customer', $context->metadata['workspace_context']);
    }

    public function test_trigger_source_maps_workspace_contexts(): void
    {
        $this->assertSame(
            CommunicationActionTriggerSource::Customer360,
            CommunicationActionTriggerSource::fromWorkspaceContext(WorkspaceContext::Customer),
        );

        $this->assertSame(
            CommunicationActionTriggerSource::Api,
            CommunicationActionTriggerSource::fromWorkspaceContext(WorkspaceContext::Api),
        );

        $this->assertSame(
            CommunicationActionTriggerSource::Ira,
            CommunicationActionTriggerSource::fromWorkspaceContext(WorkspaceContext::Ai),
        );

        $this->assertSame(
            CommunicationActionTriggerSource::Workspace,
            CommunicationActionTriggerSource::fromWorkspaceContext(WorkspaceContext::ServiceCase),
        );
    }

    public function test_factory_supports_automation_and_ira_entry_points(): void
    {
        $agent = User::factory()->create();
        [$incident, $definition] = $this->createIncidentWithDefinition($agent);
        $factory = app(CommunicationActionExecutionContextFactory::class);

        $automationContext = $factory->forAutomation($definition, $incident);
        $iraContext = $factory->forIra($definition, $incident, $agent);

        $this->assertSame(CommunicationActionTriggerSource::Automation, $automationContext->triggerSource);
        $this->assertSame(CommunicationActionExecutionMode::Automatic, $automationContext->executionMode);
        $this->assertSame(CommunicationActionTriggerSource::Ira, $iraContext->triggerSource);
        $this->assertSame(CommunicationActionExecutionMode::SemiAutomatic, $iraContext->executionMode);
    }

    /**
     * @return array{0: Incident, 1: \App\Data\CommunicationActions\CommunicationActionDefinition}
     */
    private function createIncidentWithDefinition(User $actor): array
    {
        $order = Order::query()->create([
            'order_id' => 'RD-COMM-CTX',
            'serial_number' => 'SN-CTX',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'customer_email' => 'customer@example.com',
            'customer_name' => 'Test Customer',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Execution context case',
            'description' => 'Execution context case.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'assigned_to_user_id' => $actor->id,
        ]);

        $definition = app(CommunicationActionRegistry::class)->get(CommunicationActionKey::ReviewRequest);

        return [$incident, $definition];
    }
}

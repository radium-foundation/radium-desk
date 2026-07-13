<?php

namespace Tests\Unit\CommunicationActions;

use App\Data\CommunicationActions\CommunicationActionExecutionContext;
use App\Enums\CommunicationActionExecutionMode;
use App\Enums\CommunicationActionKey;
use App\Enums\CommunicationActionLifecycleStatus;
use App\Enums\CommunicationActionTriggerSource;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\CommunicationActions\CommunicationActionLifecycleAuditService;
use App\Services\CommunicationActions\CommunicationActionLifecycleService;
use App\Services\CommunicationActions\CommunicationActionRegistry;
use App\Services\CommunicationActions\CommunicationActionVariableResolver;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunicationActionExecutionContextAdaptersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_variable_resolver_accepts_execution_context(): void
    {
        $agent = User::factory()->create();
        [$incident, $context] = $this->createContext($agent);

        $variables = app(CommunicationActionVariableResolver::class)->resolveFromContext($context);

        $this->assertSame('Test Customer', $variables['customer_name']);
        $this->assertArrayHasKey('review_url', $variables);
    }

    public function test_lifecycle_service_accepts_execution_context(): void
    {
        $agent = User::factory()->create();
        [$incident, $context] = $this->createContext($agent);

        $service = app(CommunicationActionLifecycleService::class);
        $result = $service->recordSuccessfulExecutionFromContext($context, ['whatsapp']);

        $this->assertSame('sent', $result['sent']->new_values['status']);
        $this->assertSame('completed', $result['completed']->new_values['status']);

        $opened = $service->recordOpenedFromContext($context);
        $this->assertSame(CommunicationActionLifecycleStatus::Opened->value, $opened->new_values['status']);
    }

    public function test_lifecycle_audit_service_record_from_context_matches_legacy_shape(): void
    {
        $agent = User::factory()->create();
        [$incident, $context] = $this->createContext($agent);

        $auditLog = app(CommunicationActionLifecycleAuditService::class)->recordFromContext(
            status: CommunicationActionLifecycleStatus::Sent,
            context: $context,
            channels: ['whatsapp'],
        );

        $this->assertInstanceOf(AuditLog::class, $auditLog);
        $this->assertSame('review_request', $auditLog->new_values['action_key']);
        $this->assertSame('sent', $auditLog->new_values['status']);
        $this->assertSame('manual', $auditLog->new_values['execution_mode']);
        $this->assertSame(['whatsapp'], $auditLog->new_values['channels']);
    }

    /**
     * @return array{0: Incident, 1: CommunicationActionExecutionContext}
     */
    private function createContext(User $actor): array
    {
        $order = Order::query()->create([
            'order_id' => 'RD-COMM-ADP',
            'serial_number' => 'SN-ADP',
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
            'title' => 'Context adapter case',
            'description' => 'Context adapter case.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'assigned_to_user_id' => $actor->id,
        ]);

        $definition = app(CommunicationActionRegistry::class)->get(CommunicationActionKey::ReviewRequest);

        $context = CommunicationActionExecutionContext::initial(
            action: $definition,
            incident: $incident,
            operator: $actor,
            executionMode: CommunicationActionExecutionMode::Manual,
            triggerSource: CommunicationActionTriggerSource::Customer360,
        );

        return [$incident, $context];
    }
}

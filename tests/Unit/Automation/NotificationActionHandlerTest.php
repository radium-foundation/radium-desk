<?php

namespace Tests\Unit\Automation;

use App\Data\Automation\PlannedAutomationAction;
use App\Data\NotificationDispatchResult;
use App\Data\NotificationMessage;
use App\Data\NotificationResult;
use App\Enums\AutomationPolicyActionType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\NotificationChannelType;
use App\Enums\NotificationType;
use App\Enums\WaitingReason;
use App\Enums\WhatsAppTemplateTriggerSource;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\User;
use App\Services\Automation\AutomationNotificationTypeResolver;
use App\Services\Automation\Handlers\NotificationActionHandler;
use App\Services\IncidentReferenceService;
use App\Services\Notifications\NotificationDispatcher;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class NotificationActionHandlerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_supports_whatsapp_template_policy_actions(): void
    {
        $handler = app(NotificationActionHandler::class);

        $this->assertTrue($handler->supports(AutomationPolicyActionType::WhatsAppTemplate));
        $this->assertFalse($handler->supports(AutomationPolicyActionType::NotifyTeam));
    }

    public function test_delegates_to_notification_dispatcher_with_automation_context(): void
    {
        [$plannedAction] = $this->makePlannedAction('request_serial_number');

        $notificationDispatcher = Mockery::mock(NotificationDispatcher::class);
        $notificationDispatcher->shouldReceive('send')
            ->once()
            ->withArgs(function (NotificationType $type, NotificationMessage $message): bool {
                return $type === NotificationType::RequestSerialNumber
                    && $message->metadata['source'] === 'automation_runtime'
                    && $message->metadata['trigger_source'] === WhatsAppTemplateTriggerSource::Automation->value
                    && $message->metadata['action_key'] === 'request_serial_number'
                    && $message->metadata['policy_key'] === 'serial_number_default'
                    && $message->metadata['schedule_step'] === 0;
            })
            ->andReturn(NotificationDispatchResult::fromResults([
                NotificationResult::success(
                    channel: NotificationChannelType::WhatsApp,
                    externalId: 'msg-handler-001',
                    message: 'WhatsApp template sent successfully.',
                ),
                NotificationResult::failure(
                    channel: NotificationChannelType::Email,
                    message: 'Customer email address is not available.',
                ),
            ]));

        $handler = new NotificationActionHandler(
            $notificationDispatcher,
            app(AutomationNotificationTypeResolver::class),
            app(\App\Services\Notifications\NotificationDeliverySummaryFormatter::class),
        );

        $result = $handler->handle($plannedAction);

        $this->assertTrue($result->success);
        $this->assertSame('msg-handler-001', $result->externalId);
        $this->assertSame(NotificationType::RequestSerialNumber->value, $result->metadata['notification_type']);
        $this->assertCount(2, $result->metadata['channel_results']);
    }

    public function test_returns_failure_when_notification_mapping_is_missing(): void
    {
        [$plannedAction] = $this->makePlannedAction('unknown_template_key');

        $notificationDispatcher = Mockery::mock(NotificationDispatcher::class);
        $notificationDispatcher->shouldNotReceive('send');

        $handler = new NotificationActionHandler(
            $notificationDispatcher,
            app(AutomationNotificationTypeResolver::class),
            app(\App\Services\Notifications\NotificationDeliverySummaryFormatter::class),
        );

        $result = $handler->handle($plannedAction);

        $this->assertFalse($result->success);
        $this->assertSame(
            'No notification mapping exists for action key [unknown_template_key].',
            $result->errorMessage,
        );
    }

    /**
     * @return array{0: PlannedAutomationAction}
     */
    private function makePlannedAction(string $actionKey): array
    {
        Carbon::setTestNow('2026-07-01 09:00:00');

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-AUTO-NH-'.uniqid(),
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Notification handler case',
            'description' => 'Notification handler case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $waitingState = IncidentWaitingState::query()->create([
            'incident_id' => $incident->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => Carbon::parse('2026-07-01 09:00:00'),
            'sla_paused' => true,
            'reminder_policy_key' => 'serial_number_default',
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $waitingState->setRelation('incident', $incident);
        $incident->setRelation('order', $order);

        return [
            new PlannedAutomationAction(
                waitingState: $waitingState,
                policyKey: 'serial_number_default',
                scheduleStep: 0,
                actionType: AutomationPolicyActionType::WhatsAppTemplate,
                actionKey: $actionKey,
                channel: null,
                scheduledAt: Carbon::parse('2026-07-01 09:00:00'),
            ),
        ];
    }
}

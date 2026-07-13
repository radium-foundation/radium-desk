<?php

namespace Tests\Unit\CommunicationActions;

use App\Enums\CommunicationActionKey;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RefundStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\CommunicationActions\CommunicationActionEligibilityService;
use App\Services\CommunicationActions\CommunicationActionRegistry;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunicationActionEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_agent_can_run_review_request_but_not_refund_confirmation(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        $service = app(CommunicationActionEligibilityService::class);
        $registry = app(CommunicationActionRegistry::class);

        $this->assertTrue($service->canShowAction(
            $registry->get(CommunicationActionKey::ReviewRequest),
            $incident,
            $agent,
        ));

        $this->assertFalse($service->canShowAction(
            $registry->get(CommunicationActionKey::RefundConfirmation),
            $incident,
            $agent,
        ));
    }

    public function test_admin_can_run_refund_confirmation(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        [$incident] = $this->createIncident($admin);

        RefundRequest::query()->create([
            'order_id' => $incident->order_id,
            'incident_id' => $incident->id,
            'reference_no' => 'REF-2026-000600',
            'amount' => 1200,
            'reason' => 'Approved refund for admin eligibility.',
            'status' => RefundStatus::Approved,
            'requested_by' => $admin->id,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'refund_transaction_id' => 'RFTX-600',
        ]);

        $service = app(CommunicationActionEligibilityService::class);
        $registry = app(CommunicationActionRegistry::class);

        $this->assertTrue($service->canShowAction(
            $registry->get(CommunicationActionKey::RefundConfirmation),
            $incident,
            $admin,
        ));
    }

    public function test_menu_items_hide_ineligible_actions(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent);

        $items = app(CommunicationActionEligibilityService::class)->menuItems($incident, $agent);

        $refund = collect($items)->firstWhere('key', CommunicationActionKey::RefundConfirmation->value);

        $this->assertNotNull($refund);
        $this->assertFalse($refund['eligible']);
        $this->assertNotNull($refund['disabled_reason']);
    }

    public function test_closed_incident_is_allowed_when_action_opts_in(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent, IncidentStatus::Closed);

        $service = app(CommunicationActionEligibilityService::class);
        $registry = app(CommunicationActionRegistry::class);

        $this->assertTrue($registry->get(CommunicationActionKey::ReviewRequest)->allowedOnClosedIncident);
        $this->assertNull($service->ineligibilityReason(
            $registry->get(CommunicationActionKey::ReviewRequest),
            $incident,
            $agent,
        ));
    }

    public function test_closed_incident_is_denied_when_action_does_not_opt_in(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        [$incident] = $this->createIncident($agent, IncidentStatus::Closed);

        $actions = config('communication_actions.actions');
        $actions['review_request']['allowed_on_closed_incident'] = false;
        config(['communication_actions.actions' => $actions]);
        $this->app->forgetInstance(CommunicationActionRegistry::class);

        $reason = app(CommunicationActionEligibilityService::class)->ineligibilityReason(
            app(CommunicationActionRegistry::class)->get(CommunicationActionKey::ReviewRequest),
            $incident,
            $agent,
        );

        $this->assertSame('Communication actions are unavailable on closed service cases.', $reason);
    }

    /**
     * @return array{0: Incident}
     */
    private function createIncident(User $actor, IncidentStatus $status = IncidentStatus::Resolved): array
    {
        $order = Order::query()->create([
            'order_id' => 'RD-COMM-001',
            'serial_number' => 'SN-001',
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
            'title' => 'Communication action case',
            'description' => 'Communication action case.',
            'status' => $status,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'assigned_to_user_id' => $actor->id,
        ]);

        return [$incident];
    }
}

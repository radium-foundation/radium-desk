<?php

namespace Tests\Unit\Bonvoice;

use App\Data\Bonvoice\BonvoiceClickToCallContext;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Bonvoice\BonvoiceClickToCallContextResolver;
use App\Services\Bonvoice\BonvoiceClickToCallService;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BonvoiceClickToCallContextResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_resolve_from_incident_authorizes_and_returns_order_context(): void
    {
        [$agent, $incident] = $this->createAssignedIncident();

        $context = app(BonvoiceClickToCallContextResolver::class)->resolve(
            user: $agent,
            orderId: null,
            incidentId: $incident->id,
        );

        $this->assertSame($incident->order_id, $context->order->id);
        $this->assertSame($incident->id, $context->incident?->id);
        $this->assertSame('9123456782', $context->customerDialable);
    }

    public function test_resolve_from_order_authorizes_and_includes_latest_incident(): void
    {
        [$agent, $incident] = $this->createAssignedIncident();

        $context = app(BonvoiceClickToCallContextResolver::class)->resolve(
            user: $agent,
            orderId: $incident->order_id,
            incidentId: null,
        );

        $this->assertSame($incident->order_id, $context->order->id);
        $this->assertSame($incident->id, $context->incident?->id);
        $this->assertSame('9123456782', $context->customerDialable);
    }

    public function test_resolve_from_order_denies_unauthorized_user(): void
    {
        [, $incident] = $this->createAssignedIncident();
        $otherUser = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(BonvoiceClickToCallContextResolver::class)->resolve(
            user: $otherUser,
            orderId: $incident->order_id,
            incidentId: null,
        );
    }

    public function test_callback_params_include_event_id_and_context_ids(): void
    {
        [$agent, $incident] = $this->createAssignedIncident();

        $context = new BonvoiceClickToCallContext(
            order: $incident->order,
            incident: $incident,
            customerPhone: '9123456782',
            customerDialable: '9123456782',
        );

        $params = $context->callbackParams($agent, 'A1B2C3D4E5F67890');

        $this->assertSame('A1B2C3D4E5F67890', $params['event_id']);
        $this->assertSame($incident->id, $params['incident_id']);
        $this->assertSame($incident->order_id, $params['order_id']);
        $this->assertSame($agent->id, $params['user_id']);
        $this->assertSame('radium_desk', $params['source']);
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createAssignedIncident(): array
    {
        $agent = User::factory()->create([
            'bonvoice_extension' => '9846098460',
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-C2C-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Click To Call Customer',
            'customer_email' => 'c2c@example.com',
            'customer_phone' => '9123456782',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => \App\Enums\IncidentSource::Call,
            'title' => 'Click-to-call case',
            'description' => 'Click-to-call case.',
            'status' => \App\Enums\IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $incident->setRelation('order', $order);

        return [$agent, $incident];
    }
}

<?php

namespace Tests\Unit\Interakt;

use App\Data\Interakt\WhatsAppFlowContext;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\DeviceModel;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Interakt\Exceptions\WhatsAppFlowTokenException;
use App\Services\Interakt\WhatsAppFlowService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WhatsAppFlowServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['interakt.flow_token_ttl_hours' => 24]);
    }

    public function test_generate_token_and_validate_round_trip(): void
    {
        $incident = $this->createIncident();

        $service = app(WhatsAppFlowService::class);
        $token = $service->generateToken($incident);
        $context = $service->validateToken($token);

        $this->assertSame($incident->id, $context->incident_id);
        $this->assertSame($incident->display_reference, $context->incident_reference);
        $this->assertSame('RD-FLOW-TOKEN', $context->order_id);
        $this->assertSame('Flow Customer', $context->customer_name);
        $this->assertSame('9876500001', $context->customer_phone);
        $this->assertSame('Mantra', $context->brand);
        $this->assertSame('MFS 110 E3', $context->model);
        $this->assertSame('SN-FLOW-TOKEN', $context->serial_number);
        $this->assertNotEmpty($context->booking_url);
        $this->assertFalse($context->expires_at->isPast());
    }

    public function test_resolve_incident_from_valid_token(): void
    {
        $incident = $this->createIncident();

        $service = app(WhatsAppFlowService::class);
        $token = $service->generateToken($incident);

        $resolved = $service->resolveIncident($token);

        $this->assertTrue($resolved->is($incident));
    }

    public function test_build_context_payload_matches_dto(): void
    {
        $incident = $this->createIncident();

        $service = app(WhatsAppFlowService::class);

        $this->assertSame(
            $service->buildContext($incident)->toArray(),
            $service->generateFlowContextPayload($incident),
        );
    }

    public function test_validate_token_rejects_tampered_signature(): void
    {
        $incident = $this->createIncident();
        $service = app(WhatsAppFlowService::class);
        $token = $service->generateToken($incident);

        $this->expectException(WhatsAppFlowTokenException::class);
        $this->expectExceptionMessage('Invalid flow token signature.');

        $service->validateToken($token.'tampered');
    }

    public function test_validate_token_rejects_expired_token(): void
    {
        Carbon::setTestNow('2026-07-01 10:00:00');

        $context = new WhatsAppFlowContext(
            incident_id: 1,
            incident_reference: 'SC00001',
            order_id: 'RD-EXPIRED',
            customer_name: 'Expired',
            customer_phone: '9000000002',
            brand: null,
            model: 'MFS 110',
            serial_number: null,
            booking_url: 'https://desk.example.test/book',
            expires_at: now()->addHour(),
        );

        $service = app(WhatsAppFlowService::class);
        $token = $service->generateTokenFromContext($context);

        Carbon::setTestNow('2026-07-01 12:00:00');

        $this->expectException(WhatsAppFlowTokenException::class);
        $this->expectExceptionMessage('Flow token has expired.');

        $service->validateToken($token);

        Carbon::setTestNow();
    }

    public function test_resolve_incident_rejects_missing_incident(): void
    {
        $context = new WhatsAppFlowContext(
            incident_id: 999999,
            incident_reference: 'SC99999',
            order_id: 'RD-MISSING',
            customer_name: 'Missing',
            customer_phone: '9000000003',
            brand: null,
            model: 'MFS 110',
            serial_number: null,
            booking_url: 'https://desk.example.test/book',
            expires_at: now()->addHour(),
        );

        $service = app(WhatsAppFlowService::class);
        $token = $service->generateTokenFromContext($context);

        $this->expectException(WhatsAppFlowTokenException::class);
        $this->expectExceptionMessage('Incident not found for flow token.');

        $service->resolveIncident($token);
    }

    private function createIncident(): Incident
    {
        $actor = User::factory()->create();

        $deviceModel = DeviceModel::query()->create([
            'name' => 'MFS 110 E3',
            'code' => 'MFS110E3',
            'brand' => 'Mantra',
            'display_order' => 1,
        ]);

        $order = Order::query()->create([
            'order_id' => 'RD-FLOW-TOKEN',
            'serial_number' => 'SN-FLOW-TOKEN',
            'product_name' => 'MFS 110 E3',
            'device_model' => 'MFS 110 E3',
            'device_model_id' => $deviceModel->id,
            'customer_name' => 'Flow Customer',
            'customer_phone' => '9876500001',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Flow token case',
            'description' => 'Flow token case.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
        ]);
    }
}

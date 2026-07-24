<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BonvoiceClickToCallTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'bonvoice.click_to_call.enabled' => true,
            'bonvoice.click_to_call.base_url' => 'https://backend.pbx.bonvoice.com',
            'bonvoice.click_to_call.username' => 'api-user',
            'bonvoice.click_to_call.password' => 'api-pass',
            'bonvoice.click_to_call.did' => '8040837125',
        ]);
    }

    public function test_click_to_call_initiates_bonvoice_bridge_call_with_incident_id(): void
    {
        Http::fake([
            'backend.pbx.bonvoice.com/usermanagement/external-auth/*' => Http::response([
                'status' => '1',
                'data' => ['token' => 'auth-token'],
            ], 200),
            'backend.pbx.bonvoice.com/autoDialManagement/autoCallBridging/*' => Http::response([
                'responseCode' => 200,
                'responseDescription' => 'Success',
                'responseType' => 'Success',
            ], 200),
        ]);

        [$agent, $incident] = $this->createAssignedIncident();

        $response = $this->actingAs($agent)
            ->postJson(route('bonvoice.click-to-call'), [
                'incident_id' => $incident->id,
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Calling your registered mobile...',
                'fallback_available' => true,
            ])
            ->assertJsonStructure(['event_id', 'fallback_tel']);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/autoDialManagement/autoCallBridging/'));
    }

    public function test_click_to_call_initiates_bonvoice_bridge_call_with_order_id(): void
    {
        Http::fake([
            'backend.pbx.bonvoice.com/usermanagement/external-auth/*' => Http::response([
                'status' => '1',
                'data' => ['token' => 'auth-token'],
            ], 200),
            'backend.pbx.bonvoice.com/autoDialManagement/autoCallBridging/*' => Http::response([
                'responseCode' => 200,
                'responseDescription' => 'Success',
                'responseType' => 'Success',
            ], 200),
        ]);

        [$agent, $incident] = $this->createAssignedIncident();

        $response = $this->actingAs($agent)
            ->postJson(route('bonvoice.click-to-call'), [
                'order_id' => $incident->order_id,
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Calling your registered mobile...',
            ]);
    }

    public function test_click_to_call_rejects_both_order_and_incident_id(): void
    {
        [$agent, $incident] = $this->createAssignedIncident();

        $this->actingAs($agent)
            ->postJson(route('bonvoice.click-to-call'), [
                'order_id' => $incident->order_id,
                'incident_id' => $incident->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['order_id', 'incident_id']);
    }

    public function test_click_to_call_rejects_missing_context_id(): void
    {
        [$agent] = $this->createAssignedIncident();

        $this->actingAs($agent)
            ->postJson(route('bonvoice.click-to-call'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['order_id', 'incident_id']);
    }

    public function test_click_to_call_returns_failure_without_auto_fallback_when_service_disabled(): void
    {
        config(['bonvoice.click_to_call.enabled' => false]);

        [$agent, $incident] = $this->createAssignedIncident();

        $this->actingAs($agent)
            ->postJson(route('bonvoice.click-to-call'), [
                'incident_id' => $incident->id,
            ])
            ->assertStatus(503)
            ->assertJson([
                'success' => false,
                'message' => 'Automatic calling failed.',
                'failure_code' => 'disabled',
                'fallback_available' => true,
                'retriable' => false,
            ])
            ->assertJsonStructure(['correlation_id'])
            ->assertJsonMissing(['use_fallback' => true]);
    }

    public function test_click_to_call_returns_retriable_failure_on_api_error(): void
    {
        Http::fake([
            'backend.pbx.bonvoice.com/usermanagement/external-auth/*' => Http::response([
                'status' => '1',
                'data' => ['token' => 'auth-token'],
            ], 200),
            'backend.pbx.bonvoice.com/autoDialManagement/autoCallBridging/*' => Http::response([], 500),
        ]);

        [$agent, $incident] = $this->createAssignedIncident();

        $this->actingAs($agent)
            ->postJson(route('bonvoice.click-to-call'), [
                'incident_id' => $incident->id,
            ])
            ->assertStatus(500)
            ->assertJson([
                'success' => false,
                'message' => 'Automatic calling failed.',
                'failure_code' => 'provider_http',
                'retriable' => true,
                'fallback_available' => true,
            ])
            ->assertJsonStructure(['correlation_id', 'event_id'])
            ->assertJsonMissing(['use_fallback' => true]);
    }

    public function test_click_to_call_returns_agent_phone_failure_code_when_extension_missing(): void
    {
        [$agent, $incident] = $this->createAssignedIncident();
        $agent->forceFill(['bonvoice_extension' => null])->save();

        $response = $this->actingAs($agent)
            ->postJson(route('bonvoice.click-to-call'), [
                'incident_id' => $incident->id,
            ])
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'failure_code' => 'agent_phone',
                'retriable' => false,
                'fallback_available' => true,
            ]);

        $this->assertStringContainsString(
            'call mobile number is not configured',
            (string) $response->json('message'),
        );
        $this->assertNotEmpty($response->json('correlation_id'));
    }

    public function test_click_to_call_requires_customer_phone(): void
    {
        [$agent, $incident] = $this->createAssignedIncident([
            'customer_phone' => null,
        ]);

        $this->actingAs($agent)
            ->postJson(route('bonvoice.click-to-call'), [
                'incident_id' => $incident->id,
            ])
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'fallback_available' => false,
            ]);
    }

    public function test_click_to_call_requires_authentication(): void
    {
        [$agent, $incident] = $this->createAssignedIncident();

        $this->postJson(route('bonvoice.click-to-call'), [
            'incident_id' => $incident->id,
        ])->assertUnauthorized();
    }

    public function test_customer_360_drawer_renders_api_call_button_when_enabled(): void
    {
        [$agent, $incident] = $this->createAssignedIncident();

        $html = $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-bonvoice-click-to-call', $html);
        $this->assertStringContainsString(route('bonvoice.click-to-call'), $html);
        $this->assertStringContainsString('data-bonvoice-incident-id="'.$incident->id.'"', $html);
        $this->assertStringContainsString('data-bonvoice-order-id="'.$incident->order_id.'"', $html);
    }

    public function test_customer_360_drawer_renders_tel_fallback_when_disabled(): void
    {
        config(['bonvoice.click_to_call.enabled' => false]);

        [$agent, $incident] = $this->createAssignedIncident();

        $html = $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-bonvoice-click-to-call', $html);
        $this->assertStringContainsString('tel:9123456782', $html);
    }

    /**
     * @param  array<string, mixed>  $orderOverrides
     * @return array{0: User, 1: Incident}
     */
    private function createAssignedIncident(array $orderOverrides = []): array
    {
        $agent = User::factory()->create([
            'bonvoice_extension' => '9846098460',
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create(array_merge([
            'order_id' => 'RD-C2C-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Click To Call Customer',
            'customer_email' => 'c2c@example.com',
            'customer_phone' => '9123456782',
            'status' => 'active',
            'created_by' => $agent->id,
        ], $orderOverrides));

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Click-to-call case',
            'description' => 'Click-to-call case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return [$agent, $incident];
    }
}

<?php

namespace Tests\Unit\Bonvoice;

use App\Data\Bonvoice\BonvoiceClickToCallContext;
use App\Enums\BonvoiceClickToCallFailureCode;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Bonvoice\BonvoiceClickToCallMetrics;
use App\Services\Bonvoice\BonvoiceClickToCallService;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BonvoiceClickToCallServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bonvoice.click_to_call.enabled' => true,
            'bonvoice.click_to_call.base_url' => 'https://backend.pbx.bonvoice.com',
            'bonvoice.click_to_call.username' => 'api-user',
            'bonvoice.click_to_call.password' => 'api-pass',
            'bonvoice.click_to_call.did' => '8040837125',
        ]);

        Cache::flush();
    }

    public function test_normalize_dialable_phone_accepts_common_indian_formats(): void
    {
        $service = app(BonvoiceClickToCallService::class);

        $this->assertSame('9846098460', $service->normalizeDialablePhone('9846098460'));
        $this->assertSame('9846098460', $service->normalizeDialablePhone('+919846098460'));
        $this->assertSame('9846098460', $service->normalizeDialablePhone('09846098460'));
        $this->assertNull($service->normalizeDialablePhone('12345'));
    }

    public function test_generate_event_id_is_sixteen_character_hex_string(): void
    {
        $service = app(BonvoiceClickToCallService::class);

        $eventId = $service->generateEventId();

        $this->assertMatchesRegularExpression('/^[A-F0-9]{16}$/', $eventId);
    }

    public function test_initiate_call_fails_when_agent_extension_missing(): void
    {
        [$agent, $context] = $this->createContext(agentExtension: null);

        $result = app(BonvoiceClickToCallService::class)->initiateCall(
            agent: $agent,
            context: $context,
        );

        $this->assertFalse($result->success);
        $this->assertSame(BonvoiceClickToCallFailureCode::AgentPhone, $result->failureCode);
        $this->assertStringContainsString('BonVoice mobile number is not configured', (string) $result->errorMessage);
        $this->assertNotEmpty($result->correlationId);
    }

    public function test_initiate_call_posts_expected_payload_to_bonvoice(): void
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

        [$agent, $context] = $this->createContext();

        $result = app(BonvoiceClickToCallService::class)->initiateCall(
            agent: $agent,
            context: $context,
        );

        $this->assertTrue($result->success);
        $this->assertNotNull($result->eventId);
        $this->assertMatchesRegularExpression('/^[A-F0-9]{16}$/', (string) $result->eventId);
        $this->assertSame('Calling your registered mobile...', $result->message);

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/autoDialManagement/autoCallBridging/')) {
                return false;
            }

            $body = $request->data();

            return $request->hasHeader('Authorization', 'Token auth-token')
                && ($body['autocallType'] ?? null) === '3'
                && ($body['destination'] ?? null) === '8448423017'
                && ($body['legBDestination'] ?? null) === '9846098461'
                && ($body['legACallerID'] ?? null) === '8040837125'
                && ($body['legBCallerID'] ?? null) === '8040837125'
                && preg_match('/^[A-F0-9]{16}$/', (string) ($body['eventID'] ?? '')) === 1
                && ($body['callBackParams']['incident_id'] ?? null) > 0
                && ($body['callBackParams']['order_id'] ?? null) > 0
                && ($body['callBackParams']['event_id'] ?? null) === $body['eventID']
                && ($body['callBackParams']['source'] ?? null) === 'radium_desk';
        });
    }

    public function test_initiate_call_retries_once_after_unauthorized_response(): void
    {
        Http::fake([
            'backend.pbx.bonvoice.com/usermanagement/external-auth/*' => Http::sequence()
                ->push(['status' => '1', 'data' => ['token' => 'stale-token']], 200)
                ->push(['status' => '1', 'data' => ['token' => 'fresh-token']], 200),
            'backend.pbx.bonvoice.com/autoDialManagement/autoCallBridging/*' => Http::sequence()
                ->push(['message' => 'Unauthorized'], 401)
                ->push([
                    'responseCode' => 200,
                    'responseDescription' => 'Success',
                    'responseType' => 'Success',
                ], 200),
        ]);

        [$agent, $context] = $this->createContext();

        $result = app(BonvoiceClickToCallService::class)->initiateCall(
            agent: $agent,
            context: $context,
        );

        $this->assertTrue($result->success);
        Http::assertSentCount(4);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/autoDialManagement/autoCallBridging/')
            && ($request->header('Authorization')[0] ?? null) === 'Token fresh-token');
    }

    public function test_initiate_call_returns_retriable_failure_on_connection_error(): void
    {
        Http::fake([
            'backend.pbx.bonvoice.com/usermanagement/external-auth/*' => Http::response([
                'status' => '1',
                'data' => ['token' => 'auth-token'],
            ], 200),
            'backend.pbx.bonvoice.com/autoDialManagement/autoCallBridging/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'),
        ]);

        [$agent, $context] = $this->createContext(agentExtension: '9846098460');

        $result = app(BonvoiceClickToCallService::class)->initiateCall(
            agent: $agent,
            context: $context,
        );

        $this->assertFalse($result->success);
        $this->assertTrue($result->retriable);
        $this->assertSame(BonvoiceClickToCallFailureCode::Connection, $result->failureCode);
        $this->assertSame('Automatic calling failed.', $result->errorMessage);
        $this->assertNotEmpty($result->eventId);
        $this->assertSame($result->eventId, $result->correlationId);
    }

    public function test_initiate_call_returns_failure_when_bonvoice_rejects_request(): void
    {
        Http::fake([
            'backend.pbx.bonvoice.com/usermanagement/external-auth/*' => Http::response([
                'status' => '1',
                'data' => ['token' => 'auth-token'],
            ], 200),
            'backend.pbx.bonvoice.com/autoDialManagement/autoCallBridging/*' => Http::response([
                'responseCode' => 400,
                'responseDescription' => 'Invalid destination',
                'responseType' => 'Error',
            ], 200),
        ]);

        [$agent, $context] = $this->createContext(agentExtension: '9846098460');

        $result = app(BonvoiceClickToCallService::class)->initiateCall(
            agent: $agent,
            context: $context,
        );

        $this->assertFalse($result->success);
        $this->assertSame(BonvoiceClickToCallFailureCode::ProviderResponse, $result->failureCode);
        $this->assertSame('Automatic calling failed.', $result->errorMessage);
        $this->assertFalse($result->retriable);
        $this->assertNotEmpty($result->eventId);
    }

    public function test_initiate_call_maps_auth_failure_to_auth_failure_code(): void
    {
        Http::fake([
            'backend.pbx.bonvoice.com/usermanagement/external-auth/*' => Http::response([
                'message' => 'Invalid credentials',
            ], 401),
        ]);

        [$agent, $context] = $this->createContext(agentExtension: '9846098460');

        $result = app(BonvoiceClickToCallService::class)->initiateCall(
            agent: $agent,
            context: $context,
        );

        $this->assertFalse($result->success);
        $this->assertSame(BonvoiceClickToCallFailureCode::Auth, $result->failureCode);
        $this->assertTrue($result->retriable);
        $this->assertSame(503, $result->httpStatus);

        $summary = app(BonvoiceClickToCallMetrics::class)->todaySummary();
        $this->assertSame(1, $summary['failure']);
        $this->assertSame(1, $summary['by_failure_code']['auth'] ?? 0);
    }

    /**
     * @return array{0: User, 1: BonvoiceClickToCallContext}
     */
    private function createContext(?string $agentExtension = '08448423017'): array
    {
        $this->seed(RolePermissionSeeder::class);

        $agent = User::factory()->create([
            'bonvoice_extension' => $agentExtension,
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-C2C-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Click To Call Customer',
            'customer_email' => 'c2c@example.com',
            'customer_phone' => '+91 98460 98461',
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

        $context = new BonvoiceClickToCallContext(
            order: $order,
            incident: $incident,
            customerPhone: '+91 98460 98461',
            customerDialable: '9846098461',
        );

        return [$agent, $context];
    }
}

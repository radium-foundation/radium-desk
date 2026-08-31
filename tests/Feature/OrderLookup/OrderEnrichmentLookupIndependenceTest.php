<?php

namespace Tests\Feature\OrderLookup;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\OrderIdentityRepairService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class OrderEnrichmentLookupIndependenceTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-desk-order-token';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        config([
            'radiumbox.enabled' => true,
            'radiumbox.base_url' => 'https://admin.radiumbox.com',
            'rdservice.enabled' => false,
            'rdservice.token' => '',
            'rdservice.base_url' => 'https://rdservice.net',
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);

        Sleep::fake();
        Cache::forget(OrderIdentityRepairService::RESUME_CACHE_KEY);
    }

    public function test_intake_search_default_uses_admin_without_rdservice_http(): void
    {
        Http::fake([
            'https://rdservice.net/*' => Http::response($this->rdServicePayload('RD3395988'), 200),
            'admin.radiumbox.com/api/search/order*' => Http::response($this->adminPreviewPayload('RD3395988'), 200),
        ]);

        $agent = $this->agent();

        $this->actingAs($agent)
            ->postJson(route('service-requests.intake.search'), [
                'order_id' => 'RD3395988',
            ])
            ->assertOk()
            ->assertJsonPath('classification', 'legacy')
            ->assertJsonPath('legacy_preview.serial_number', 'ADMIN-SN');

        $this->assertRdServiceNotCalled();
        $this->assertAdminCalled();
    }

    public function test_intake_search_prefers_rdservice_when_enabled(): void
    {
        $this->enableRdService();

        Http::fake([
            'https://rdservice.net/api/integrations/v1/rd-orders/*' => Http::response($this->rdServicePayload('RD3395988'), 200),
            'admin.radiumbox.com/api/search/order*' => Http::response($this->adminPreviewPayload('RD3395988'), 200),
        ]);

        $agent = $this->agent();

        $this->actingAs($agent)
            ->postJson(route('service-requests.intake.search'), [
                'order_id' => 'RD3395988',
            ])
            ->assertOk()
            ->assertJsonPath('classification', 'legacy')
            ->assertJsonPath('legacy_preview.serial_number', 'SN1')
            ->assertJsonPath('legacy_preview.gst_number', '07ABCDE1234F1Z5');

        $this->assertRdServiceCalled();
        $this->assertAdminNotCalled();
    }

    public function test_global_search_fallback_prefers_rdservice_when_enabled(): void
    {
        $this->enableRdService();

        Http::fake([
            'https://rdservice.net/api/integrations/v1/rd-orders/*' => Http::response($this->rdServicePayload('RD3395988'), 200),
            'admin.radiumbox.com/api/search/order*' => Http::response($this->adminPreviewPayload('RD3395988'), 200),
        ]);

        $agent = $this->agent();

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'RD3395988']))
            ->assertOk()
            ->assertJsonPath('match_count', 0)
            ->assertJsonPath('intake.classification', 'legacy')
            ->assertJsonPath('intake.legacy_preview.serial_number', 'SN1');

        $this->assertRdServiceCalled();
        $this->assertAdminNotCalled();
    }

    public function test_desk_native_order_skips_remote_lookup(): void
    {
        Http::fake();
        Queue::fake();

        $agent = $this->agent();
        Order::query()->create([
            'order_id' => 'RD3395988',
            'customer_phone' => '9111111111',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->postJson(route('service-requests.intake.search'), [
                'order_id' => 'RD3395988',
            ])
            ->assertOk()
            ->assertJsonPath('classification', 'legacy')
            ->assertJsonPath('legacy_preview', null);

        Http::assertNothingSent();
    }

    public function test_legacy_import_prefers_rdservice_when_enabled(): void
    {
        $this->enableRdService();

        Http::fake([
            'https://rdservice.net/api/integrations/v1/rd-orders/*' => Http::response($this->rdServicePayload('RD3395988'), 200),
            'admin.radiumbox.com/api/search/order*' => Http::response($this->adminPreviewPayload('RD3395988'), 200),
        ]);

        $agent = $this->agent('Import Agent');

        $this->actingAs($agent)
            ->post(route('service-requests.quick.store'), [
                'action' => 'legacy_import',
                'legacy_order_id' => 'RD3395988',
                'source' => IncidentSource::Call->value,
                'notes' => 'Imported via RDService preference.',
            ])
            ->assertRedirect(route('dashboard'));

        $order = Order::query()->where('order_id', 'RD3395988')->first();
        $this->assertNotNull($order);
        $this->assertSame('SN1', $order->serial_number);
        $this->assertSame('07ABCDE1234F1Z5', $order->gst_number);
        $this->assertSame('INV-1', $order->invoice_number);
        $this->assertSame('Payer', $order->customer_name);
        $this->assertRdServiceCalled();
        $this->assertAdminNotCalled();
    }

    public function test_identity_repair_prefers_rdservice_when_enabled(): void
    {
        $this->enableRdService();

        Http::fake([
            'https://rdservice.net/api/integrations/v1/rd-orders/*' => Http::response(
                $this->rdServicePayload('RD3000099', serialNumber: '7881953', productName: 'MFS 110'),
                200,
            ),
            'admin.radiumbox.com/api/search/order*' => Http::response($this->adminPreviewPayload('RD3000099'), 200),
        ]);

        $actor = User::factory()->create();
        $this->createActiveIncident($actor, [
            'order_id' => 'RD3000099',
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
        ]);

        $this->artisan('orders:repair-identity --force')
            ->assertSuccessful()
            ->expectsOutputToContain('Repaired: 1');

        $order = Order::query()->where('order_id', 'RD3000099')->firstOrFail();
        $this->assertSame('7881953', $order->serial_number);
        $this->assertSame('MFS 110', $order->device_model);
        $this->assertRdServiceCalled();
        $this->assertAdminNotCalled();
    }

    public function test_hardware_intake_stays_on_admin_when_rdservice_is_enabled(): void
    {
        $this->enableRdService();

        Http::fake([
            'https://rdservice.net/*' => Http::response($this->rdServicePayload('RDE1001'), 200),
            'admin.radiumbox.com/api/search/order*' => Http::response($this->adminPreviewPayload('RDE1001'), 200),
        ]);

        $agent = $this->agent();

        $this->actingAs($agent)
            ->postJson(route('service-requests.intake.search'), [
                'order_id' => 'RDE1001',
            ])
            ->assertOk()
            ->assertJsonPath('legacy_preview.serial_number', 'ADMIN-SN');

        $this->assertRdServiceNotCalled();
        $this->assertAdminCalled();
    }

    public function test_inq_intake_stays_on_admin_when_rdservice_is_enabled(): void
    {
        $this->enableRdService();

        Http::fake([
            'https://rdservice.net/*' => Http::response($this->rdServicePayload('INQ-SC1001'), 200),
            'admin.radiumbox.com/api/search/order*' => Http::response($this->adminPreviewPayload('INQ-SC1001'), 200),
        ]);

        $agent = $this->agent();

        $this->actingAs($agent)
            ->postJson(route('service-requests.intake.search'), [
                'order_id' => 'INQ-SC1001',
            ])
            ->assertOk()
            ->assertJsonPath('legacy_preview.serial_number', 'ADMIN-SN');

        $this->assertRdServiceNotCalled();
        $this->assertAdminCalled();
    }

    private function enableRdService(): void
    {
        config([
            'rdservice.enabled' => true,
            'rdservice.token' => self::TOKEN,
            'rdservice.base_url' => 'https://rdservice.net',
        ]);
    }

    private function agent(string $name = 'Desk Agent'): User
    {
        $agent = User::factory()->create(['name' => $name]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $agent;
    }

    /**
     * @param  array<string, mixed>  $orderAttributes
     */
    private function createActiveIncident(User $actor, array $orderAttributes): Incident
    {
        $order = Order::query()->create([
            'status' => 'active',
            'created_by' => $actor->id,
            ...$orderAttributes,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Identity repair fixture',
            'description' => 'Identity repair fixture.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
            'assigned_to_user_id' => $actor->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    private function rdServicePayload(
        string $orderId,
        string $serialNumber = 'SN1',
        string $productName = 'MFS110',
    ): array {
        $userDetails = json_encode([
            'name' => 'Payer',
            'phone' => '9999999999',
            'email' => 'payer@example.com',
            'gst_no' => '07ABCDE1234F1Z5',
        ]);

        return [
            'status' => 200,
            'data' => [
                'correlation' => ['rdorderid' => $orderId],
                'rd_order' => [
                    'rdorderid' => $orderId,
                    'order_id' => $orderId,
                    'product_name' => $productName,
                    'rd_service_name' => '1 Year',
                    'serial_no' => $serialNumber,
                    'gst_no' => '07ABCDE1234F1Z5',
                    'status' => 'Processing',
                    'created_at' => '2022-06-15 10:00:00',
                    'userdetails' => $userDetails,
                ],
                'order' => [
                    'invoicecode' => 'INV-1',
                    'orderdate' => '2022-06-15 10:00:00',
                    'userdetails' => $userDetails,
                    'gst_no' => '07ABCDE1234F1Z5',
                    'status' => 'Pending',
                ],
                'snapshot' => [
                    'serial_number' => $serialNumber,
                    'invoice_number' => 'INV-1',
                    'gst_number' => '07ABCDE1234F1Z5',
                    'customer_name' => 'Payer',
                    'phone' => '9999999999',
                    'email' => 'payer@example.com',
                    'model' => $productName,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminPreviewPayload(string $orderId): array
    {
        return [
            'status' => 200,
            'data' => [
                'rd_order' => [
                    'order_id' => $orderId,
                    'serial_no' => 'ADMIN-SN',
                    'product_name' => 'Admin Model',
                    'rd_service_name' => 'Admin Service',
                    'customer_name' => 'Admin Customer',
                    'mobile' => '9000000000',
                ],
            ],
        ];
    }

    private function assertRdServiceCalled(): void
    {
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'rdservice.net/api/integrations/v1/rd-orders/'));
    }

    private function assertRdServiceNotCalled(): void
    {
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'rdservice.net'));
    }

    private function assertAdminCalled(): void
    {
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'admin.radiumbox.com/api/search/order'));
    }

    private function assertAdminNotCalled(): void
    {
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'admin.radiumbox.com'));
    }
}

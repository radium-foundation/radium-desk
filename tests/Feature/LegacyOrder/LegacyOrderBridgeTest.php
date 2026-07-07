<?php

namespace Tests\Feature\LegacyOrder;

use App\Enums\IncidentSource;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SettingSource;
use App\Models\User;
use App\Services\ServiceCaseActivityTimelineService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LegacyOrderBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        config([
            'radiumbox.enabled' => true,
            'radiumbox.base_url' => 'https://admin.radiumbox.com',
            'radiumbox.timeout_seconds' => 5,
            'radiumbox.connect_timeout_seconds' => 3,
            'service_case_assignment.automation_grace_period_enabled' => false,
        ]);

        $dayAdmin = User::factory()->create(['email' => 'legacy-day-admin@test.com']);
        $dayAdmin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $nightAdmin = User::factory()->create(['email' => 'legacy-night-admin@test.com']);
        $nightAdmin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        app(\App\Services\SettingService::class)->setMany([
            'assignment.timezone' => config('app.timezone'),
            'assignment.day_shift_start' => '09:00',
            'assignment.day_shift_end' => '18:30',
            'assignment.day_shift_admin_user_id' => (string) $dayAdmin->id,
            'assignment.night_shift_admin_user_id' => (string) $nightAdmin->id,
            'assignment.fallback_admin_1_user_id' => '',
            'assignment.fallback_admin_2_user_id' => '',
        ]);
    }

    public function test_existing_desk_order_does_not_call_radiumbox_api(): void
    {
        Http::fake();

        $agent = User::factory()->create(['name' => 'Desk Agent']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
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
            ->assertJsonPath('matches.0.id', $order->id)
            ->assertJsonPath('requires_confirmation', false)
            ->assertJsonPath('legacy_preview', null);

        Http::assertNothingSent();
    }

    public function test_missing_desk_order_returns_legacy_preview_from_api(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response($this->legacyOrderApiResponse()),
        ]);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->postJson(route('service-requests.intake.search'), [
                'order_id' => 'RD3395988',
            ])
            ->assertOk()
            ->assertJsonPath('classification', 'legacy')
            ->assertJsonPath('requires_confirmation', true)
            ->assertJsonPath('legacy_preview_message', 'Legacy order found. Create service case?')
            ->assertJsonPath('legacy_preview.order_id', 'RD3395988')
            ->assertJsonPath('legacy_preview.customer_name', 'Satyam Test')
            ->assertJsonPath('legacy_preview.mobile', '9876543210')
            ->assertJsonPath('legacy_preview.email', 'test@example.com')
            ->assertJsonPath('legacy_preview.product_model', 'MFS 110')
            ->assertJsonPath('legacy_preview.serial_number', 'SN123456')
            ->assertJsonPath('legacy_preview.gst_number', 'GSTIN123')
            ->assertJsonPath('legacy_preview.invoice_number', 'INV-9988')
            ->assertJsonPath('legacy_preview.purchase_year', '2022')
            ->assertJsonPath('legacy_preview.amc_status', 'Active')
            ->assertJsonPath('legacy_preview.amc_year', '2025')
            ->assertJsonPath('legacy_preview.legacy_order_status', 'Completed');

        Http::assertSentCount(1);
    }

    public function test_legacy_import_creates_order_and_service_case(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response($this->legacyOrderApiResponse()),
        ]);

        $agent = User::factory()->create(['name' => 'Import Agent']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->post(route('service-requests.quick.store'), [
                'action' => 'legacy_import',
                'legacy_order_id' => 'RD3395988',
                'source' => IncidentSource::Call->value,
                'notes' => 'Imported legacy order.',
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status', 'service-case-created');

        $order = Order::query()->where('order_id', 'RD3395988')->first();
        $this->assertNotNull($order);
        $this->assertSame('Satyam Test', $order->customer_name);
        $this->assertSame('9876543210', $order->customer_phone);
        $this->assertSame('test@example.com', $order->customer_email);
        $this->assertSame('SN123456', $order->serial_number);
        $this->assertSame('GSTIN123', $order->gst_number);
        $this->assertSame('INV-9988', $order->invoice_number);
        $this->assertSame('2022', $order->purchase_year);
        $this->assertSame('Active', $order->amc_status);
        $this->assertSame('2025', $order->amc_year);
        $this->assertSame('Completed', $order->legacy_order_status);
        $this->assertSame('radiumbox', $order->legacy_source);
        $this->assertSame($agent->id, $order->legacy_imported_by_user_id);
        $this->assertNotNull($order->legacy_imported_at);

        $incident = Incident::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($incident);
        $this->assertSame('Imported legacy order.', $incident->description);
    }

    public function test_legacy_import_json_response_returns_incident_details(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response($this->legacyOrderApiResponse()),
        ]);

        $agent = User::factory()->create(['name' => 'JSON Import Agent']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $response = $this->actingAs($agent)
            ->postJson(route('service-requests.quick.store'), [
                'action' => 'legacy_import',
                'legacy_order_id' => 'RD3395988',
                'source' => IncidentSource::Call->value,
            ])
            ->assertOk();

        $incident = Incident::query()->first();
        $this->assertNotNull($incident);

        $response
            ->assertJsonPath('incident_id', $incident->id)
            ->assertJsonPath('display_reference', $incident->display_reference)
            ->assertJsonPath('message', 'Service Case '.$incident->display_reference.' created')
            ->assertJsonPath('customer_360_url', route('dashboard.service-cases.customer-360', $incident));
    }

    public function test_legacy_import_json_rejects_duplicate_order(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response($this->legacyOrderApiResponse()),
        ]);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        Order::query()->create([
            'order_id' => 'RD3395988',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $response = $this->actingAs($agent)
            ->postJson(route('service-requests.quick.store'), [
                'action' => 'legacy_import',
                'legacy_order_id' => 'RD3395988',
                'source' => IncidentSource::Call->value,
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.legacy_order_id.0', 'This order already exists in Radium Desk.');
    }

    public function test_legacy_import_accepts_admin_ui_source_key(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response($this->legacyOrderApiResponse()),
        ]);

        SettingSource::query()->updateOrCreate(
            ['key' => 'admin'],
            [
                'label' => 'Admin UI',
                'icon' => 'bi-person-gear',
                'sort_order' => 0,
                'is_enabled' => true,
            ],
        );

        $agent = User::factory()->create(['name' => 'Admin UI Agent']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->post(route('service-requests.quick.store'), [
                'action' => 'legacy_import',
                'legacy_order_id' => 'RD3395988',
                'source' => 'admin',
                'notes' => 'Imported from admin UI quick create.',
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status', 'service-case-created');

        $incident = Incident::query()->first();
        $this->assertNotNull($incident);
        $this->assertSame(IncidentSource::Internal, $incident->source);
    }

    public function test_legacy_import_assigns_importing_agent(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response($this->legacyOrderApiResponse()),
        ]);

        $agent = User::factory()->create(['name' => 'Assigned Agent']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->post(route('service-requests.quick.store'), [
                'action' => 'legacy_import',
                'legacy_order_id' => 'RD3395988',
                'source' => IncidentSource::Call->value,
            ])
            ->assertRedirect(route('dashboard'));

        $incident = Incident::query()->first();
        $this->assertNotNull($incident);
        $this->assertSame($agent->id, $incident->assigned_to_user_id);
    }

    public function test_rd3421021_production_style_legacy_import_preserves_fidelity(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response($this->rd3421021ProductionStylePayload()),
        ]);

        $agent = User::factory()->create(['name' => 'RD3421021 Agent']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->postJson(route('service-requests.intake.search'), [
                'order_id' => 'RD3421021',
            ])
            ->assertOk()
            ->assertJsonPath('requires_confirmation', true)
            ->assertJsonPath('legacy_preview.order_id', 'RD3421021')
            ->assertJsonPath('legacy_preview.invoice_number', 'INV6731025')
            ->assertJsonPath('legacy_preview.product_model', 'MFS110')
            ->assertJsonPath('legacy_preview.serial_number', '9321909')
            ->assertJsonPath('legacy_preview.service_history.0', 'regular')
            ->assertJsonPath('legacy_preview.amc_details_display', '1 Year Standard')
            ->assertJsonPath('legacy_preview.legacy_order_date', '17 Jun 2026, 10:45 AM');

        $this->actingAs($agent)
            ->post(route('service-requests.quick.store'), [
                'action' => 'legacy_import',
                'legacy_order_id' => 'RD3421021',
                'source' => IncidentSource::Call->value,
                'notes' => 'Imported RD3421021 legacy order.',
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status', 'service-case-created');

        $order = Order::query()->where('order_id', 'RD3421021')->first();
        $this->assertNotNull($order);
        $this->assertSame('INV6731025', $order->invoice_number);
        $this->assertSame('MFS110', $order->product_name);
        $this->assertSame('9321909', $order->serial_number);
        $this->assertSame(['regular'], $order->service_history);
        $this->assertSame(['service_name' => '1 Year Standard'], $order->amc_details);
        $this->assertNotNull($order->legacy_order_date);
        $this->assertSame('2026-06-17 10:45:00', $order->legacy_order_date?->format('Y-m-d H:i:s'));
        $this->assertNotNull($order->legacy_imported_at);
        $this->assertTrue($order->legacy_imported_at->greaterThan($order->legacy_order_date));

        $incident = Incident::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($incident);
    }

    public function test_legacy_import_returns_validation_error_for_duplicate_serial_instead_of_server_error(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response($this->rd3421021ProductionStylePayload()),
        ]);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        Order::query()->create([
            'order_id' => 'RD-EXISTING-SERIAL',
            'serial_number' => '9321909',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->from(route('dashboard'))
            ->post(route('service-requests.quick.store'), [
                'action' => 'legacy_import',
                'legacy_order_id' => 'RD3421021',
                'source' => IncidentSource::Call->value,
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors('legacy_order_id');

        $this->assertNull(Order::query()->where('order_id', 'RD3421021')->first());
    }

    public function test_legacy_import_creates_timeline_event(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response($this->legacyOrderApiResponse()),
        ]);

        $agent = User::factory()->create(['name' => 'Timeline Agent']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->post(route('service-requests.quick.store'), [
                'action' => 'legacy_import',
                'legacy_order_id' => 'RD3395988',
                'source' => IncidentSource::Call->value,
            ])
            ->assertRedirect(route('dashboard'));

        $incident = Incident::query()->first();
        $this->assertNotNull($incident);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'legacy_order.imported',
            'auditable_type' => $incident->order->getMorphClass(),
            'auditable_id' => $incident->order_id,
        ]);

        $timeline = app(ServiceCaseActivityTimelineService::class)->forIncident($incident->fresh(['order', 'creator', 'assignee']));
        $titles = $timeline->pluck('title')->all();

        $this->assertContains('Legacy order imported by Timeline', $titles);
    }

    public function test_legacy_import_reuses_customer_id_from_phone_match(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response($this->legacyOrderApiResponse()),
        ]);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        Order::query()->create([
            'order_id' => 'RD-EXISTING-001',
            'customer_id' => 'CUST-12345',
            'customer_phone' => '9876543210',
            'customer_name' => 'Existing Customer',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->post(route('service-requests.quick.store'), [
                'action' => 'legacy_import',
                'legacy_order_id' => 'RD3395988',
                'source' => IncidentSource::Call->value,
            ])
            ->assertRedirect(route('dashboard'));

        $imported = Order::query()->where('order_id', 'RD3395988')->first();
        $this->assertNotNull($imported);
        $this->assertSame('CUST-12345', $imported->customer_id);
        $this->assertSame('9876543210', $imported->customer_phone);
    }

    public function test_legacy_radiumbox_path_still_creates_service_case(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'order_id' => 'RD-LEGACY-OLD-001',
                        'serial_no' => 'OLD-SERIAL-1',
                        'product_name' => 'MFS 110',
                    ],
                ],
            ]),
        ]);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->post(route('service-requests.quick.store'), [
                'action' => 'legacy_radiumbox',
                'legacy_order_id' => 'RD-LEGACY-OLD-001',
                'source' => IncidentSource::Call->value,
                'notes' => 'Legacy radiumbox compatibility path.',
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status', 'service-case-created');

        $order = Order::query()->where('order_id', 'RD-LEGACY-OLD-001')->first();
        $this->assertNotNull($order);
        $this->assertSame('OLD-SERIAL-1', $order->serial_number);
        $this->assertNull($order->legacy_imported_at);

        $incident = Incident::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($incident);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'intake.legacy_customer_matched',
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyOrderApiResponse(string $orderId = 'RD3395988'): array
    {
        $userDetails = json_encode([
            'name' => 'Satyam Test',
            'phone' => '9876543210',
            'email' => 'test@example.com',
            'gst_no' => 'GSTIN123',
        ]);

        return [
            'status' => 200,
            'data' => [
                'order' => [
                    'invoicecode' => 'INV-9988',
                    'orderdate' => '2022-06-15 10:00:00',
                    'userdetails' => $userDetails,
                    'gst_no' => 'GSTIN123',
                    'status' => 'Completed',
                ],
                'rd_order' => [
                    'rdorderid' => $orderId,
                    'product_name' => 'MFS 110',
                    'serial_no' => 'SN123456',
                    'userdetails' => $userDetails,
                    'activation_year' => '2022',
                    'service_history' => ['2023', '2024'],
                    'amc_status' => 'Active',
                    'amc_year' => '2025',
                    'amc_details' => ['plan' => 'Gold'],
                    'rd_service_name' => '1 Year Unlimited',
                    'status' => 'Completed',
                    'created_at' => '2022-06-15 10:00:00',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rd3421021ProductionStylePayload(): array
    {
        $userDetails = json_encode([
            'name' => 'RD3421021 Customer',
            'phone' => '9876543210',
            'email' => 'rd3421021@example.com',
        ]);

        return [
            'status' => 200,
            'data' => [
                'order' => [
                    'invoicecode' => 'INV6731025',
                    'orderdate' => '17-06-2026 10:45 AM',
                    'userdetails' => $userDetails,
                    'status' => 'Completed',
                ],
                'rd_order' => [
                    'rdorderid' => 'RD3421021',
                    'product_name' => 'MFS110',
                    'serial_no' => '9321909',
                    'userdetails' => $userDetails,
                    'rd_service_name' => 'regular',
                    'amc_details' => '{"service_name":"1 Year Standard"}',
                    'status' => 'Completed',
                    'created_at' => '2026-06-17 10:45:00',
                ],
            ],
        ];
    }
}

<?php

namespace Tests\Feature\RadiumBoxRead;

use App\Enums\RadiumBoxReadIdentifierType;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\RadiumBoxRead\RadiumBoxReadRepository;
use App\Services\RadiumBoxRead\RadiumBoxReadWriteAttemptException;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\RadiumBoxRead\Support\RadiumBoxReadSqliteSchema;
use Tests\TestCase;

class RadiumBoxReadApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        Http::fake();
    }

    public function test_guest_cannot_lookup(): void
    {
        $this->getJson(route('api.v1.radiumbox.orders.index', [
            'identifier_type' => 'ordercode',
            'identifier' => 'RD318017',
        ]))->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_orders_view_is_not_sufficient(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $this->assertTrue($agent->can('orders.view'));
        $this->assertFalse($agent->can(RolePermissionSeeder::PERMISSION_RADIUMBOX_READ));

        $this->actingAs($agent)
            ->getJson(route('api.v1.radiumbox.orders.index', [
                'identifier_type' => 'ordercode',
                'identifier' => 'RD318017',
            ]))
            ->assertForbidden()
            ->assertJson(['error' => 'forbidden']);
    }

    public function test_disabled_feature_returns_503(): void
    {
        $admin = $this->adminWithReadPermission();

        $this->actingAs($admin)
            ->getJson(route('api.v1.radiumbox.orders.index', [
                'identifier_type' => 'ordercode',
                'identifier' => 'RD318017',
            ]))
            ->assertStatus(503)
            ->assertJson(['error' => 'radiumbox_read_disabled']);

        Http::assertNothingSent();
    }

    public function test_enabled_without_database_is_misconfigured(): void
    {
        config(['radiumbox_read.enabled' => true]);
        $admin = $this->adminWithReadPermission();

        $this->actingAs($admin)
            ->getJson(route('api.v1.radiumbox.orders.index', [
                'identifier_type' => 'ordercode',
                'identifier' => 'RD318017',
            ]))
            ->assertStatus(503)
            ->assertJson(['error' => 'radiumbox_read_misconfigured']);
    }

    public function test_lookup_by_ordercode_returns_allowlisted_fields(): void
    {
        $this->enableSqliteReplica();
        $this->seedReplica();
        $admin = $this->adminWithReadPermission();

        $response = $this->actingAs($admin)
            ->getJson(route('api.v1.radiumbox.orders.index', [
                'identifier_type' => RadiumBoxReadIdentifierType::Ordercode->value,
                'identifier' => 'RD318017',
            ]))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.identifier_column', 'orders.ordercode')
            ->assertJsonPath('data.0.commercial.id', 318017)
            ->assertJsonPath('data.0.commercial.ordercode', 'RD318017')
            ->assertJsonPath('data.0.commercial.rdservice_order_id', '3510864')
            ->assertJsonPath('data.0.rd_order.id', 3510864)
            ->assertJsonPath('data.0.rd_order.rdorderid', 'RD3510864')
            ->assertJsonPath('data.0.customer.phone', '9999999999')
            ->assertJsonPath('data.0.invoices.0.invoice_number', '1888')
            ->assertJsonPath('data.0.lines.0.product_name', 'Mantra MFS 110')
            ->assertJsonPath('data.0.history.0.status', 'Created');

        $payload = $response->json('data.0');
        $this->assertArrayNotHasKey('userdetails', $payload['commercial']);
        $this->assertArrayNotHasKey('paid_amount', $payload['rd_order']);
        $this->assertArrayNotHasKey('label', $payload['lines'][0]);

        Http::assertNothingSent();
        $this->assertSame(0, Http::recorded()->count());

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'radiumbox.read.lookup',
            'user_id' => $admin->id,
        ]);
        $audit = AuditLog::query()->where('event', 'radiumbox.read.lookup')->first();
        $this->assertSame('ordercode', $audit?->new_values['identifier_type'] ?? null);
        $this->assertSame('RD318017', $audit?->new_values['identifier'] ?? null);
        $this->assertArrayNotHasKey('userdetails', $audit?->new_values ?? []);
    }

    public function test_identifier_types_are_not_interchangeable(): void
    {
        $this->enableSqliteReplica();
        $this->seedReplica();
        $admin = $this->adminWithReadPermission();

        $this->actingAs($admin)
            ->getJson(route('api.v1.radiumbox.orders.index', [
                'identifier_type' => RadiumBoxReadIdentifierType::Ordercode->value,
                'identifier' => 'RD3510864',
            ]))
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->actingAs($admin)
            ->getJson(route('api.v1.radiumbox.orders.index', [
                'identifier_type' => RadiumBoxReadIdentifierType::Rdorderid->value,
                'identifier' => 'RD318017',
            ]))
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->actingAs($admin)
            ->getJson(route('api.v1.radiumbox.orders.index', [
                'identifier_type' => RadiumBoxReadIdentifierType::Rdorderid->value,
                'identifier' => 'RD3510864',
            ]))
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.rd_order.id', 3510864);
    }

    public function test_duplicate_rdservice_order_id_returns_all_commercials(): void
    {
        $this->enableSqliteReplica();
        $this->seedReplica();
        $admin = $this->adminWithReadPermission();

        $this->actingAs($admin)
            ->getJson(route('api.v1.radiumbox.orders.index', [
                'identifier_type' => RadiumBoxReadIdentifierType::RdserviceOrderId->value,
                'identifier' => '3510864',
            ]))
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.commercial.id', 318017)
            ->assertJsonPath('data.1.commercial.id', 318099);
    }

    public function test_pagination(): void
    {
        $this->enableSqliteReplica();
        $this->seedReplica();
        $admin = $this->adminWithReadPermission();

        $this->actingAs($admin)
            ->getJson(route('api.v1.radiumbox.orders.index', [
                'identifier_type' => RadiumBoxReadIdentifierType::RdserviceOrderId->value,
                'identifier' => '3510864',
                'page' => 2,
                'per_page' => 1,
            ]))
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.page', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.commercial.id', 318099);
    }

    public function test_show_by_commercial_id(): void
    {
        $this->enableSqliteReplica();
        $this->seedReplica();
        $admin = $this->adminWithReadPermission();

        $this->actingAs($admin)
            ->getJson(route('api.v1.radiumbox.orders.show', 318017))
            ->assertOk()
            ->assertJsonPath('data.commercial.id', 318017)
            ->assertJsonPath('data.matched_identifier_column', 'orders.id');

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'radiumbox.read.show',
            'user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->getJson(route('api.v1.radiumbox.orders.show', 1))
            ->assertNotFound();
    }

    public function test_invalid_identifier_is_rejected(): void
    {
        $admin = $this->adminWithReadPermission();

        $this->actingAs($admin)
            ->getJson(route('api.v1.radiumbox.orders.index', [
                'identifier_type' => 'ordercode',
                'identifier' => "RD318017' OR 1=1",
            ]))
            ->assertStatus(422);

        $this->actingAs($admin)
            ->getJson(route('api.v1.radiumbox.orders.index', [
                'identifier_type' => 'not_a_type',
                'identifier' => 'RD318017',
            ]))
            ->assertStatus(422);
    }

    public function test_queries_never_use_select_star_or_writes(): void
    {
        $this->enableSqliteReplica();
        $this->seedReplica();

        $sql = [];
        DB::connection('radiumbox_read')->listen(function ($event) use (&$sql): void {
            $sql[] = $event->sql;
        });

        $this->app->make(RadiumBoxReadRepository::class)->lookup(
            RadiumBoxReadIdentifierType::CommercialId,
            '318017',
            1,
            15,
        );

        $this->assertNotEmpty($sql);
        foreach ($sql as $statement) {
            $this->assertDoesNotMatchRegularExpression('/select\s+\*/i', $statement);
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|drop|alter|replace)\b/i', $statement);
        }

        $countBefore = DB::connection('radiumbox_read')->table('orders')->count();

        try {
            DB::connection('radiumbox_read')->table('orders')->insert([
                'id' => 1,
                'ordercode' => 'RD1',
            ]);
            $this->fail('Write guard should reject INSERT.');
        } catch (RadiumBoxReadWriteAttemptException) {
            // expected — the write must not execute
        }

        $this->assertSame($countBefore, DB::connection('radiumbox_read')->table('orders')->count());
    }

    private function adminWithReadPermission(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $admin;
    }

    private function enableSqliteReplica(): void
    {
        config([
            'radiumbox_read.enabled' => true,
            'radiumbox_read.connection' => 'radiumbox_read',
            'database.connections.radiumbox_read' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);

        DB::purge('radiumbox_read');
        RadiumBoxReadRepository::resetWriteGuardsForTests();
        RadiumBoxReadSqliteSchema::migrate();
    }

    private function seedReplica(): void
    {
        $read = DB::connection('radiumbox_read');

        $read->table('users')->insert([
            'id' => 549084,
            'name' => 'History Customer',
            'phone' => '9999999999',
            'email' => 'history@example.com',
            'gst_no' => '07AAICP1128M1Z9',
            'company_name' => 'Example Co',
        ]);

        $read->table('order_rdservice')->insert([
            'id' => 3510864,
            'rdorderid' => 'RD3510864',
            'userid' => 549084,
            'gst_no' => '07AAICP1128M1Z9',
            'product_name' => 'Mantra MFS 110',
            'serial_no' => '10215344',
            'status' => 'Completed',
            'website' => 'rdservice.net',
            'amc_service_name' => '1 Year AMC',
            'rd_service_name' => '1 Year RD',
            'created_at' => '2026-09-04 15:27:50',
        ]);

        $read->table('orders')->insert([
            [
                'id' => 318017,
                'ordercode' => 'RD318017',
                'ordertype' => 'rdservice',
                'rdservice_order_id' => '3510864',
                'invoicecode' => 'INV1888',
                'userid' => '549084',
                'gst_no' => '07AAICP1128M1Z9',
                'payment_status' => 'Paid',
                'status' => 'Completed',
                'orderdate' => '2026-09-04',
                'branch' => 'radium_delhi',
                'created_at' => '2026-09-04 15:27:50',
            ],
            [
                'id' => 318099,
                'ordercode' => 'RD318099',
                'ordertype' => 'rdservice',
                'rdservice_order_id' => '3510864',
                'invoicecode' => null,
                'userid' => '549084',
                'gst_no' => null,
                'payment_status' => 'Paid',
                'status' => 'Completed',
                'orderdate' => '2026-09-04',
                'branch' => 'radium_delhi',
                'created_at' => '2026-09-04 15:28:00',
            ],
        ]);

        $read->table('invoice')->insert([
            'id' => 261302,
            'orderid' => '318017',
            'invoice_number' => '1888',
            'branch' => 'radium_delhi',
            'service_type' => 'rdservice',
            'created_at' => '2026-09-04 15:27:58',
        ]);

        $read->table('order_details')->insert([
            'id' => 1,
            'orderid' => '318017',
            'product_name' => 'Mantra MFS 110',
            'productid' => '946',
            'invoicecode' => 'INV1888',
            'created_at' => '2026-09-04 15:27:50',
        ]);

        $read->table('order_history')->insert([
            'id' => 1,
            'orderid' => 318017,
            'updated_by' => 'system',
            'status' => 'Created',
            'description' => 'Order created',
            'created_at' => '2026-09-04 15:27:50',
        ]);
    }
}

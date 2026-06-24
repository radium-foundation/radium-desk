<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function createAuditLog(User $user, array $overrides = []): AuditLog
    {
        return AuditLog::query()->create(array_merge([
            'user_id' => $user->id,
            'event' => 'created',
            'auditable_type' => Order::class,
            'auditable_id' => 42,
            'new_values' => ['order_id' => 'RD1000001'],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ], $overrides));
    }

    public function test_agent_cannot_access_audit_logs(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $log = $this->createAuditLog($agent);

        $this->actingAs($agent)->get(route('audit-logs.index'))->assertForbidden();
        $this->actingAs($agent)->get(route('audit-logs.show', $log))->assertForbidden();
    }

    public function test_admin_can_view_audit_log_list_and_detail(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $log = $this->createAuditLog($admin, [
            'event' => 'approved',
            'old_values' => ['status' => 'pending'],
            'new_values' => ['status' => 'approved'],
        ]);

        $this->actingAs($admin)
            ->get(route('audit-logs.index'))
            ->assertOk()
            ->assertSee('Audit Logs')
            ->assertSee($admin->name)
            ->assertSee('Order')
            ->assertSee('#42')
            ->assertSee('127.0.0.1');

        $this->actingAs($admin)
            ->get(route('audit-logs.show', $log))
            ->assertOk()
            ->assertSee('Audit Log Detail')
            ->assertSee('Old Values')
            ->assertSee('New Values')
            ->assertSee('"status": "pending"')
            ->assertSee('"status": "approved"')
            ->assertSee('PHPUnit');
    }

    public function test_superadmin_can_view_audit_logs(): void
    {
        $superadmin = User::factory()->create();
        $superadmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->actingAs($superadmin)
            ->get(route('audit-logs.index'))
            ->assertOk();
    }

    public function test_index_supports_filters_and_search(): void
    {
        $admin = User::factory()->create(['name' => 'Audit Admin']);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $otherUser = User::factory()->create(['name' => 'Other User']);
        $otherUser->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $matchingLog = $this->createAuditLog($admin, [
            'event' => 'incident_linked',
            'auditable_type' => \App\Models\ApprovalNumber::class,
            'auditable_id' => 99,
            'created_at' => now()->subDay(),
        ]);

        $this->createAuditLog($otherUser, [
            'event' => 'deleted',
            'auditable_type' => Order::class,
            'auditable_id' => 7,
            'created_at' => now()->subMonth(),
        ]);

        $this->actingAs($admin)
            ->get(route('audit-logs.index', [
                'user_id' => $admin->id,
                'event' => 'incident_linked',
                'module' => 'ApprovalNumber',
                'date_from' => now()->subDays(2)->toDateString(),
                'date_to' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('incident linked')
            ->assertSee('ApprovalNumber')
            ->assertSee('#99')
            ->assertDontSee('#7');

        $this->actingAs($admin)
            ->get(route('audit-logs.index', ['q' => '99']))
            ->assertOk()
            ->assertSee('#99');

        $this->actingAs($admin)
            ->get(route('audit-logs.index', ['q' => 'Audit Admin']))
            ->assertOk()
            ->assertSee('Audit Admin');
    }
}

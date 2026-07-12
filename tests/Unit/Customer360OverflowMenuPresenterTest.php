<?php

namespace Tests\Unit;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Support\Customer360\Customer360OverflowMenuPresenter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Customer360OverflowMenuPresenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_build_orders_groups_and_places_close_case_last_with_divider(): void
    {
        [$agent, $incident, $order] = $this->createFixture(RolePermissionSeeder::ROLE_AGENT, IncidentStatus::Open);

        $menu = app(Customer360OverflowMenuPresenter::class)->build(
            $incident,
            $agent,
            $order,
        );

        $labels = collect($menu['groups'])->pluck('label')->all();

        $this->assertContains('Communication', $labels);
        $this->assertContains('Case', $labels);
        $this->assertContains('Appointments', $labels);
        $this->assertLessThan(
            array_search('Case', $labels, true),
            array_search('Communication', $labels, true),
        );

        $caseItems = collect($menu['groups'])->firstWhere('label', 'Case')['items'];
        $this->assertSame(
            ['Assign Engineer', 'Close Case'],
            collect($caseItems)->pluck('label')->all(),
        );
        $this->assertTrue((bool) ($caseItems[1]['dividerBefore'] ?? false));
        $this->assertTrue((bool) ($caseItems[1]['destructive'] ?? false));
        $this->assertContains('Related', $labels);

        $relatedLabels = collect(
            collect($menu['groups'])->firstWhere('label', 'Related')['items'],
        )->pluck('label')->all();

        $this->assertSame(['Open Order', 'Open Case'], $relatedLabels);
        $this->assertFalse(
            collect($menu['paletteActions'])->contains(fn (array $item): bool => $item['id'] === 'correct-customer'),
        );
    }

    public function test_build_includes_escalate_when_user_can_escalate(): void
    {
        [$agent, $incident, $order] = $this->createFixture(RolePermissionSeeder::ROLE_AGENT, IncidentStatus::Open);

        $specialist = User::factory()->create([
            'email' => 'escalation-specialist@example.com',
            'is_active' => true,
        ]);
        $specialist->assignRole(RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST);

        config([
            'service_case_assignment.escalation.level_1_email' => $specialist->email,
        ]);

        $menu = app(Customer360OverflowMenuPresenter::class)->build(
            $incident->fresh(),
            $agent,
            $order,
        );

        $caseLabels = collect(
            collect($menu['groups'])->firstWhere('label', 'Case')['items'],
        )->pluck('label')->all();

        $this->assertContains('Escalate', $caseLabels);

        $escalate = collect(collect($menu['groups'])->firstWhere('label', 'Case')['items'])
            ->firstWhere('id', 'escalate-case');

        $this->assertSame('escalate', $escalate['workspaceActionType'] ?? null);
        $this->assertSame('warning', $escalate['accent'] ?? null);
    }

    public function test_build_includes_reopen_case_when_closed(): void
    {
        [$admin, $incident, $order] = $this->createFixture(RolePermissionSeeder::ROLE_ADMIN, IncidentStatus::Closed);

        $menu = app(Customer360OverflowMenuPresenter::class)->build(
            $incident,
            $admin,
            $order,
        );

        $caseLabels = collect(
            collect($menu['groups'])->firstWhere('label', 'Case')['items'],
        )->pluck('label')->all();

        $this->assertSame(['Reopen Case'], $caseLabels);
    }

    public function test_build_includes_finance_refund_when_user_can_create_refunds(): void
    {
        [$admin, $incident, $order] = $this->createFixture(RolePermissionSeeder::ROLE_ADMIN, IncidentStatus::Open);

        $menu = app(Customer360OverflowMenuPresenter::class)->build(
            $incident,
            $admin,
            $order,
        );

        $financeLabels = collect(
            collect($menu['groups'])->firstWhere('label', 'Finance')['items'],
        )->pluck('label')->all();

        $this->assertSame(['Refund'], $financeLabels);

        $refund = collect(collect($menu['groups'])->firstWhere('label', 'Finance')['items'])
            ->firstWhere('id', 'refund');

        $this->assertTrue((bool) ($refund['destructive'] ?? false));
    }

    public function test_build_includes_schedule_appointment_in_appointments_group(): void
    {
        [$admin, $incident, $order] = $this->createFixture(RolePermissionSeeder::ROLE_ADMIN, IncidentStatus::Open);

        $menu = app(Customer360OverflowMenuPresenter::class)->build(
            $incident,
            $admin,
            $order,
        );

        $appointmentLabels = collect(
            collect($menu['groups'])->firstWhere('label', 'Appointments')['items'],
        )->pluck('label')->all();

        $this->assertContains('Schedule Appointment', $appointmentLabels);
    }

    /**
     * @return array{0: User, 1: Incident, 2: Order}
     */
    private function createFixture(string $role, IncidentStatus $status): array
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        $order = Order::query()->create([
            'order_id' => 'RD-OVERFLOW-'.uniqid(),
            'serial_number' => 'SN-OVERFLOW',
            'serial_entered_at' => now(),
            'serial_entered_by_user_id' => $user->id,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Overflow Customer',
            'customer_email' => 'overflow@example.com',
            'customer_phone' => '9123456783',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Overflow menu case',
            'description' => 'Overflow menu case.',
            'status' => $status,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'assigned_to_user_id' => $user->id,
        ]);

        return [$user, $incident, $order];
    }
}

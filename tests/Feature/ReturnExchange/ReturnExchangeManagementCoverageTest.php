<?php

namespace Tests\Feature\ReturnExchange;

use App\Enums\InventorySerialStatus;
use App\Enums\ReturnExchangeHybridApprovalDecision;
use App\Enums\ReturnExchangeHybridApprovalType;
use App\Enums\ReturnExchangeReplacementCategory;
use App\Models\InventorySerial;
use App\Models\InventoryUserBranch;
use App\Models\ReturnExchangeCase;
use App\Models\User;
use App\Services\ReturnExchange\ReturnExchangeCaseWorkflowReadinessService;
use App\Support\Navigation\NavigationContextResolver;
use App\Support\Navigation\NavigationMenu;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class ReturnExchangeManagementCoverageTest extends ReturnExchangeManagementTest
{
    /**
     * @return array<string, array{0: ReturnExchangeReplacementCategory, 1: list<string>}>
     */
    public static function categoryApprovalExpectationsProvider(): array
    {
        return [
            'warranty' => [
                ReturnExchangeReplacementCategory::Warranty,
                ['Business category', 'Statutory document treatment'],
            ],
            'commercial_return' => [
                ReturnExchangeReplacementCategory::CommercialReturn,
                ['Business category', 'Statutory document treatment', 'Finance reconciliation'],
            ],
            'paid_upgrade' => [
                ReturnExchangeReplacementCategory::PaidUpgrade,
                ['Business category', 'Statutory document treatment', 'Price difference'],
            ],
            'goodwill' => [
                ReturnExchangeReplacementCategory::Goodwill,
                ['Business category', 'Statutory document treatment', 'Goodwill exception'],
            ],
            'customer_requested_exchange' => [
                ReturnExchangeReplacementCategory::CustomerRequestedExchange,
                ['Business category', 'Statutory document treatment', 'Price difference'],
            ],
        ];
    }

    #[DataProvider('categoryApprovalExpectationsProvider')]
    public function test_each_category_shows_required_approval_labels(
        ReturnExchangeReplacementCategory $category,
        array $expectedLabels,
    ): void {
        [$sale, $returnSerial] = $this->fourSerialPosSale();
        $admin = $this->centralAdmin();
        InventoryUserBranch::query()->create(['user_id' => $admin->id, 'branch_id' => $this->branchA->id]);

        $this->actingAs($admin)->post(route('return-exchange-management.store'), [
            'inventory_sale_id' => $sale->id,
            'replacement_category' => $category->value,
            'reason' => 'Category coverage '.$category->value,
            'lines' => [[
                'description' => 'Return',
                'qty' => 1,
                'serial_number' => $returnSerial,
            ]],
        ])->assertRedirect();

        $case = ReturnExchangeCase::query()->latest('id')->firstOrFail();
        $this->assertSame($category, $case->replacement_category);

        $response = $this->actingAs($admin)->get(route('return-exchange-management.show', $case));
        $response->assertOk();
        foreach ($expectedLabels as $label) {
            $response->assertSee($label, false);
        }
    }

    public function test_business_approval_denied_without_permission(): void
    {
        [$sale, $returnSerial] = $this->fourSerialPosSale();
        $manager = User::factory()->create(['is_active' => true]);
        $manager->givePermissionTo([
            RolePermissionSeeder::PERMISSION_RETURNS_CASES_MANAGE,
            RolePermissionSeeder::PERMISSION_RETURNS_CASES_VIEW,
            RolePermissionSeeder::PERMISSION_RETURNS_CASES_CREATE,
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        InventoryUserBranch::query()->create(['user_id' => $manager->id, 'branch_id' => $this->branchA->id]);

        $this->actingAs($manager)->post(route('return-exchange-management.store'), [
            'inventory_sale_id' => $sale->id,
            'replacement_category' => ReturnExchangeReplacementCategory::Warranty->value,
            'reason' => 'Warranty',
            'lines' => [[
                'description' => 'Return',
                'qty' => 1,
                'serial_number' => $returnSerial,
            ]],
        ]);

        $case = ReturnExchangeCase::query()->latest('id')->firstOrFail();

        $this->actingAs($manager)->post(route('return-exchange-management.approvals.store', $case), [
            'approval_type' => ReturnExchangeHybridApprovalType::BusinessCategory->value,
            'decision' => ReturnExchangeHybridApprovalDecision::Approved->value,
            'reason' => 'Approved',
        ])->assertForbidden();
    }

    public function test_business_approval_allowed_with_permission(): void
    {
        [$sale, $returnSerial] = $this->fourSerialPosSale();
        $admin = $this->centralAdmin();
        InventoryUserBranch::query()->create(['user_id' => $admin->id, 'branch_id' => $this->branchA->id]);

        $this->actingAs($admin)->post(route('return-exchange-management.store'), [
            'inventory_sale_id' => $sale->id,
            'replacement_category' => ReturnExchangeReplacementCategory::Warranty->value,
            'reason' => 'Warranty',
            'lines' => [[
                'description' => 'Return',
                'qty' => 1,
                'serial_number' => $returnSerial,
            ]],
        ]);

        $case = ReturnExchangeCase::query()->latest('id')->firstOrFail();

        $this->actingAs($admin)->post(route('return-exchange-management.approvals.store', $case), [
            'approval_type' => ReturnExchangeHybridApprovalType::BusinessCategory->value,
            'decision' => ReturnExchangeHybridApprovalDecision::Approved->value,
            'reason' => 'Business category confirmed',
        ])->assertRedirect();

        $case->refresh();
        $approval = $case->hybridApprovals->firstWhere('approval_type', ReturnExchangeHybridApprovalType::BusinessCategory);
        $this->assertNotNull($approval);
        $this->assertSame(ReturnExchangeHybridApprovalDecision::Approved, $approval->decision);
    }

    public function test_repeated_store_requests_create_only_one_open_case(): void
    {
        [$sale, $returnSerial] = $this->fourSerialPosSale();
        $admin = $this->centralAdmin();
        InventoryUserBranch::query()->create(['user_id' => $admin->id, 'branch_id' => $this->branchA->id]);

        $payload = [
            'inventory_sale_id' => $sale->id,
            'replacement_category' => ReturnExchangeReplacementCategory::CustomerRequestedExchange->value,
            'reason' => 'Concurrent intake',
            'lines' => [[
                'description' => 'Return',
                'qty' => 1,
                'serial_number' => $returnSerial,
            ]],
        ];

        $before = ReturnExchangeCase::query()->count();

        for ($i = 0; $i < 8; $i++) {
            $response = $this->actingAs($admin)->post(route('return-exchange-management.store'), $payload);
            if ($i === 0) {
                $response->assertRedirect();
            }
        }

        $this->assertSame($before + 1, ReturnExchangeCase::query()->count());
    }

    public function test_approval_idempotency_key_does_not_duplicate_rows(): void
    {
        [$sale, $returnSerial] = $this->fourSerialPosSale();
        $admin = $this->centralAdmin();
        InventoryUserBranch::query()->create(['user_id' => $admin->id, 'branch_id' => $this->branchA->id]);

        $this->actingAs($admin)->post(route('return-exchange-management.store'), [
            'inventory_sale_id' => $sale->id,
            'replacement_category' => ReturnExchangeReplacementCategory::Warranty->value,
            'reason' => 'Warranty',
            'lines' => [[
                'description' => 'Return',
                'qty' => 1,
                'serial_number' => $returnSerial,
            ]],
        ]);

        $case = ReturnExchangeCase::query()->latest('id')->firstOrFail();
        $payload = [
            'approval_type' => ReturnExchangeHybridApprovalType::Statutory->value,
            'decision' => ReturnExchangeHybridApprovalDecision::Approved->value,
            'reason' => 'Statutory path agreed',
            'idempotency_key' => 'idem-stat-'.uniqid('', true),
        ];

        $this->actingAs($admin)->post(route('return-exchange-management.approvals.store', $case), $payload);
        $this->actingAs($admin)->post(route('return-exchange-management.approvals.store', $case), $payload);

        $count = $case->hybridApprovals()->where('approval_type', ReturnExchangeHybridApprovalType::Statutory)->count();
        $this->assertSame(1, $count);
    }

    public function test_planned_replacement_does_not_mark_serial_sold(): void
    {
        [$sale, $returnSerial, , $replacementNo] = $this->fourSerialPosSale(withReplacementAvailable: true);
        $admin = $this->centralAdmin();
        InventoryUserBranch::query()->create(['user_id' => $admin->id, 'branch_id' => $this->branchA->id]);

        $this->actingAs($admin)->post(route('return-exchange-management.store'), [
            'inventory_sale_id' => $sale->id,
            'replacement_category' => ReturnExchangeReplacementCategory::CustomerRequestedExchange->value,
            'reason' => 'Plan only',
            'lines' => [[
                'description' => 'Return',
                'qty' => 1,
                'serial_number' => $returnSerial,
            ]],
        ]);

        $case = ReturnExchangeCase::query()->latest('id')->firstOrFail();
        $serial = InventorySerial::query()->where('serial_number', $replacementNo)->firstOrFail();
        $this->assertSame(InventorySerialStatus::Available, $serial->status);

        $this->actingAs($admin)->post(route('return-exchange-management.planned-replacement', $case), [
            'planned_replacement_serial_number' => $replacementNo,
            'price_difference_amount' => '99.00',
        ])->assertRedirect();

        $serial->refresh();
        $this->assertSame(InventorySerialStatus::Available, $serial->status);
    }

    public function test_readiness_blocks_completion_until_orchestration_enabled(): void
    {
        [$sale, $returnSerial] = $this->fourSerialPosSale();
        $admin = $this->centralAdmin();
        InventoryUserBranch::query()->create(['user_id' => $admin->id, 'branch_id' => $this->branchA->id]);

        $this->actingAs($admin)->post(route('return-exchange-management.store'), [
            'inventory_sale_id' => $sale->id,
            'replacement_category' => ReturnExchangeReplacementCategory::Warranty->value,
            'reason' => 'Blocked',
            'lines' => [[
                'description' => 'Return',
                'qty' => 1,
                'serial_number' => $returnSerial,
            ]],
        ]);

        $case = ReturnExchangeCase::query()->latest('id')->firstOrFail();
        $service = app(ReturnExchangeCaseWorkflowReadinessService::class);

        $this->assertFalse($service->mayAttemptCompletion($case));
        $this->assertNotEmpty($service->blockingReasons($case));
    }

    public function test_demo_pos_6754_does_not_persist_cases(): void
    {
        $admin = $this->centralAdmin();
        $before = ReturnExchangeCase::query()->count();

        $this->actingAs($admin)
            ->get(route('return-exchange-management.demo.pos-6754'))
            ->assertOk()
            ->assertSee('POS-6754', false)
            ->assertSee('Pending verification', false)
            ->assertSee('Pending CA approval', false);

        $this->assertSame($before, ReturnExchangeCase::query()->count());
    }

    public function test_management_index_resolves_finance_navigation_context(): void
    {
        $admin = $this->centralAdmin();
        $this->actingAs($admin);

        $request = Request::create(route('return-exchange-management.index'), 'GET');
        $request->setUserResolver(fn () => $admin);
        $request->setRouteResolver(fn () => app('router')->getRoutes()->match($request));

        $resolver = app(NavigationContextResolver::class);
        $context = $resolver->resolve($request, 'Return & exchange management');

        $this->assertSame(NavigationMenu::Finance, $context->menu);
    }

    public function test_legacy_index_shows_hybrid_management_link_for_authorized_admin(): void
    {
        $admin = $this->centralAdmin();

        $this->actingAs($admin)
            ->get(route('return-cases.index'))
            ->assertOk()
            ->assertSee(route('return-exchange-management.index'), false)
            ->assertSee('Hybrid management', false);
    }

    public function test_agent_does_not_see_hybrid_management_link_on_legacy_index(): void
    {
        $agent = $this->agentUser();
        Permission::findOrCreate(RolePermissionSeeder::PERMISSION_RETURNS_CASES_VIEW, 'web');
        $agent->givePermissionTo(RolePermissionSeeder::PERMISSION_RETURNS_CASES_VIEW);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($agent)
            ->get(route('return-cases.index'))
            ->assertOk()
            ->assertDontSee('Hybrid management', false);
    }
}

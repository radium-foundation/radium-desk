<?php

namespace Tests\Feature\IraMemory;

use App\Enums\IncomingEmailOperatorClassification;
use App\Enums\IraMemoryCreatedFrom;
use App\Enums\IraMemoryDecisionKind;
use App\Enums\IraMemoryPatternKind;
use App\Enums\IraMemoryStatus;
use App\Models\IraMemory;
use App\Models\User;
use App\Services\IraMemory\IraMemoryService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IraMemoryPhaseM3AdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'inbound_email.enabled' => true,
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        User::factory()->create([
            'name' => 'System',
            'email' => 'superadmin@radium.local',
        ])->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);
    }

    public function test_admin_can_browse_search_and_view_memory(): void
    {
        $admin = $this->createAdmin('m3-browse@test.com');
        $memory = $this->seedMemory($admin, 'browse@acme.com', IncomingEmailOperatorClassification::Sales->value);

        $this->actingAs($admin)
            ->get(route('admin.ira-memory.index', ['q' => 'browse@acme.com']))
            ->assertOk()
            ->assertSee('browse@acme.com')
            ->assertSee('IRA Memory');

        $this->actingAs($admin)
            ->get(route('admin.ira-memory.show', $memory))
            ->assertOk()
            ->assertSee('browse@acme.com')
            ->assertSee('Explainability');
    }

    public function test_enable_disable_edit_and_soft_delete(): void
    {
        $admin = $this->createAdmin('m3-edit@test.com');
        $memory = $this->seedMemory($admin, 'toggle@acme.com', IncomingEmailOperatorClassification::Support->value);

        $this->actingAs($admin)
            ->patch(route('admin.ira-memory.toggle', $memory))
            ->assertRedirect();

        $this->assertSame(IraMemoryStatus::Disabled, $memory->fresh()->status);

        $this->actingAs($admin)
            ->patch(route('admin.ira-memory.toggle', $memory))
            ->assertRedirect();

        $this->assertSame(IraMemoryStatus::Active, $memory->fresh()->status);

        $this->actingAs($admin)
            ->put(route('admin.ira-memory.update', $memory), [
                'pattern_kind' => IraMemoryPatternKind::Sender->value,
                'pattern_value' => 'toggle@acme.com',
                'decision_kind' => IraMemoryDecisionKind::Classification->value,
                'decision_value' => IncomingEmailOperatorClassification::Refund->value,
                'memory_type' => 'classification',
                'reason' => 'Edited in admin',
                'confidence' => 88,
            ])
            ->assertRedirect(route('admin.ira-memory.show', $memory));

        $memory->refresh();
        $this->assertSame(IncomingEmailOperatorClassification::Refund->value, $memory->decision_value);
        $this->assertSame(88, $memory->confidence);
        $this->assertSame('Edited in admin', $memory->reason);
        $this->assertSame(IraMemoryCreatedFrom::LearningCenter, $memory->created_from);

        $this->actingAs($admin)
            ->delete(route('admin.ira-memory.destroy', $memory))
            ->assertRedirect(route('admin.ira-memory.index'));

        $this->assertSoftDeleted('ira_memories', ['id' => $memory->id]);
        $this->assertSame(IraMemoryStatus::Deleted, IraMemory::withTrashed()->find($memory->id)?->status);
    }

    public function test_merge_and_test_memory(): void
    {
        $admin = $this->createAdmin('m3-merge@test.com');
        $service = app(IraMemoryService::class);

        $survivor = $this->seedMemory($admin, 'keep@acme.com', IncomingEmailOperatorClassification::Sales->value);
        $duplicate = $this->seedMemory($admin, 'drop@acme.com', IncomingEmailOperatorClassification::Sales->value);
        $service->recordUsage($duplicate);

        $this->actingAs($admin)
            ->post(route('admin.ira-memory.merge'), [
                'survivor_id' => $survivor->id,
                'source_ids' => [$survivor->id, $duplicate->id],
            ])
            ->assertRedirect(route('admin.ira-memory.show', $survivor));

        $this->assertSame(IraMemoryStatus::Merged, $duplicate->fresh()->status);
        $this->assertSame($survivor->id, $duplicate->fresh()->merged_into_memory_id);
        $this->assertGreaterThanOrEqual(1, $survivor->fresh()->times_used);

        $this->actingAs($admin)
            ->post(route('admin.ira-memory.test'), [
                'from_email' => 'keep@acme.com',
                'subject' => 'Hello',
            ])
            ->assertOk()
            ->assertSee('keep@acme.com')
            ->assertSee('match');
    }

    public function test_guest_and_disabled_inbound_are_blocked(): void
    {
        $admin = $this->createAdmin('m3-deny@test.com');
        $memory = $this->seedMemory($admin, 'deny@acme.com', IncomingEmailOperatorClassification::Sales->value);

        $this->get(route('admin.ira-memory.index'))->assertRedirect();

        config(['inbound_email.enabled' => false]);

        $this->actingAs($admin)
            ->get(route('admin.ira-memory.index'))
            ->assertNotFound();

        $this->actingAs($admin)
            ->get(route('admin.ira-memory.show', $memory))
            ->assertNotFound();
    }

    private function seedMemory(User $actor, string $patternValue, string $decisionValue): IraMemory
    {
        return app(IraMemoryService::class)->upsertFromTeaching(
            patternKind: IraMemoryPatternKind::Sender,
            patternValue: $patternValue,
            decisionKind: IraMemoryDecisionKind::Classification,
            decisionValue: $decisionValue,
            actor: $actor,
            createdFrom: IraMemoryCreatedFrom::LearningCenter,
        );
    }

    private function createAdmin(string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $user;
    }
}

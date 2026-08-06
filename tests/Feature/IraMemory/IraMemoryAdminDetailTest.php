<?php

namespace Tests\Feature\IraMemory;

use App\Enums\IncomingEmailMessageStatus;
use App\Enums\IncomingEmailOperatorClassification;
use App\Enums\IraMemoryCreatedFrom;
use App\Enums\IraMemoryDecisionKind;
use App\Enums\IraMemoryPatternKind;
use App\Enums\IraMemorySource;
use App\Enums\IraMemoryStatus;
use App\Models\IncomingEmailMessage;
use App\Models\IraMemory;
use App\Models\User;
use App\Services\IraMemory\IraMemoryService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IraMemoryAdminDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'inbound_email.enabled' => true,
            'inbound_email.auto_create_service_case' => false,
            'inbound_email.smart_routing_enabled' => false,
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        User::factory()->create([
            'name' => 'System',
            'email' => 'superadmin@radium.local',
        ])->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);
    }

    public function test_admin_can_view_memory_list_and_detail_explainability(): void
    {
        $admin = $this->createAdmin('ira-mem-admin@test.com');
        $memory = $this->createMemory($admin, [
            'reason' => 'VIP sender taught in Learning Center',
            'times_used' => 4,
            'last_used_at' => now()->subHour(),
        ]);

        $matched = IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'fixture',
            'provider_message_id' => 'ira-mem-example-1',
            'from_email' => 'vip@acme.com',
            'subject' => 'Need help with order',
            'preview' => 'Please call me about my order',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'received_at' => now()->subMinutes(30),
            'matched_ira_memory_id' => $memory->id,
            'matched_learning_rule_id' => $memory->id,
            'ira_confidence' => 90,
        ]);

        $origin = IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'fixture',
            'provider_message_id' => 'ira-mem-origin-1',
            'from_email' => 'vip@acme.com',
            'subject' => 'Original teach email',
            'preview' => 'Teach origin',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'received_at' => now()->subDay(),
        ]);

        $memory->forceFill([
            'created_from_type' => IncomingEmailMessage::class,
            'created_from_id' => $origin->id,
        ])->save();

        $this->actingAs($admin)
            ->get(route('admin.ira-memory.index'))
            ->assertOk()
            ->assertSee('IRA Memory')
            ->assertSee('vip@acme.com')
            ->assertSee('Learning Center');

        $response = $this->actingAs($admin)
            ->get(route('admin.ira-memory.show', $memory))
            ->assertOk();

        $response
            ->assertSee('Explainability')
            ->assertSee('Why matched')
            ->assertSee('VIP sender taught in Learning Center')
            ->assertSee('Matched fields')
            ->assertSee('Sender: vip@acme.com')
            ->assertSee('Confidence')
            ->assertSee('High')
            ->assertSee('90%')
            ->assertSee('Pattern')
            ->assertSee('Rule source')
            ->assertSee('Learning Center')
            ->assertSee('Email')
            ->assertSee('Usage')
            ->assertSee('4×')
            ->assertSee('Last matched')
            ->assertSee('Example emails')
            ->assertSee('Need help with order')
            ->assertSee('Original teach email')
            ->assertSee('Origin');

        $this->assertSame($matched->id, IncomingEmailMessage::query()->where('matched_ira_memory_id', $memory->id)->value('id'));
    }

    public function test_detail_uses_deterministic_why_when_reason_missing(): void
    {
        $admin = $this->createAdmin('ira-mem-why@test.com');
        $memory = $this->createMemory($admin, ['reason' => null]);

        $this->actingAs($admin)
            ->get(route('admin.ira-memory.show', $memory))
            ->assertOk()
            ->assertSee('Matched an operator-confirmed learning rule')
            ->assertSee('Sender')
            ->assertSee('Sales');
    }

    public function test_forbidden_without_settings_permission(): void
    {
        $agent = User::factory()->create([
            'email' => 'ira-mem-agent@test.com',
            'is_active' => true,
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $memory = $this->createMemory($this->createAdmin('ira-mem-owner@test.com'));

        $this->actingAs($agent)
            ->get(route('admin.ira-memory.index'))
            ->assertForbidden();

        $this->actingAs($agent)
            ->get(route('admin.ira-memory.show', $memory))
            ->assertForbidden();
    }

    public function test_not_found_when_inbound_email_disabled(): void
    {
        config(['inbound_email.enabled' => false]);

        $admin = $this->createAdmin('ira-mem-disabled@test.com');
        $memory = $this->createMemory($admin);

        $this->actingAs($admin)
            ->get(route('admin.ira-memory.index'))
            ->assertNotFound();

        $this->actingAs($admin)
            ->get(route('admin.ira-memory.show', $memory))
            ->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createMemory(User $actor, array $overrides = []): IraMemory
    {
        $service = app(IraMemoryService::class);

        $memory = $service->upsertFromTeaching(
            patternKind: IraMemoryPatternKind::Sender,
            patternValue: 'vip@acme.com',
            decisionKind: IraMemoryDecisionKind::Classification,
            decisionValue: IncomingEmailOperatorClassification::Sales->value,
            actor: $actor,
            createdFrom: IraMemoryCreatedFrom::LearningCenter,
            confidence: 90,
            source: IraMemorySource::Email,
        );

        if ($overrides !== []) {
            $memory->forceFill($overrides)->save();
        }

        $this->assertSame(IraMemoryStatus::Active, $memory->fresh()->status);

        return $memory->fresh() ?? $memory;
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

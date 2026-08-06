<?php

namespace Tests\Feature\IncomingEmail;

use App\Enums\IncomingEmailClassification;
use App\Enums\IncomingEmailImportance;
use App\Enums\IncomingEmailIntakeQueue;
use App\Enums\IncomingEmailLearningScope;
use App\Enums\IncomingEmailMessageStatus;
use App\Enums\IncomingEmailOperatorClassification;
use App\Models\IncomingEmailMessage;
use App\Models\User;
use App\Support\IncomingEmail\IncomingEmailAccess;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IncomingEmailLearningCenterAccessTest extends TestCase
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

    /**
     * @return array<string, array{0: string}>
     */
    public static function rolesWithDefaultEmailIntakeAccess(): array
    {
        return [
            'admin' => [RolePermissionSeeder::ROLE_ADMIN],
            'operations_admin' => [RolePermissionSeeder::ROLE_OPERATIONS_ADMIN],
            'support_agent' => [RolePermissionSeeder::ROLE_AGENT],
            'support_specialist' => [RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST],
            'customer_coordinator' => [RolePermissionSeeder::ROLE_CUSTOMER_COORDINATOR],
        ];
    }

    #[DataProvider('rolesWithDefaultEmailIntakeAccess')]
    public function test_default_roles_can_view_and_manage_learning_center(string $role): void
    {
        $user = $this->userWithRole($role, 'intake-'.$role.'@test.com');

        $this->assertTrue(IncomingEmailAccess::allowsView($user));
        $this->assertTrue(IncomingEmailAccess::allowsManage($user));
        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_EMAIL_INTAKE_VIEW));
        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_EMAIL_INTAKE_MANAGE));

        $html = (string) $this->actingAs($user)
            ->get(route('admin.incoming-emails.index', [
                'queue' => IncomingEmailIntakeQueue::NeedsHuman->value,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('IRA Learning Center', $html);
        $this->assertStringContainsString('title="Learning Center"', $this->sidebarHtml($user));
    }

    public function test_employee_without_email_intake_permission_receives_403(): void
    {
        $user = $this->userWithRole(RolePermissionSeeder::ROLE_EMPLOYEE, 'no-intake@test.com');

        $this->assertFalse(IncomingEmailAccess::allowsView($user));
        $this->assertFalse(IncomingEmailAccess::allowsManage($user));

        $this->actingAs($user)
            ->get(route('admin.incoming-emails.index'))
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('admin.incoming-emails.learning.apply'), [
                'action' => 'classification',
                'message_ids' => [1],
                'classification' => IncomingEmailOperatorClassification::Support->value,
                'scope' => IncomingEmailLearningScope::ThisEmail->value,
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('admin.incoming-emails.disposition.apply'), [
                'disposition' => 'keep_pending',
                'message_ids' => [1],
                'keep_pending_reason' => 'waiting_customer',
            ])
            ->assertForbidden();

        $this->assertStringNotContainsString('title="Learning Center"', $this->sidebarHtml($user));
    }

    public function test_view_only_user_can_open_learning_center_but_cannot_mutate(): void
    {
        $user = User::factory()->create([
            'email' => 'view-only-intake@test.com',
            'is_active' => true,
        ]);
        $user->givePermissionTo(RolePermissionSeeder::PERMISSION_EMAIL_INTAKE_VIEW);

        $message = IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'fixture',
            'provider_message_id' => 'view-only-1',
            'from_email' => 'buyer@example.com',
            'subject' => 'Quote please',
            'preview' => 'Need pricing',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'classification' => IncomingEmailClassification::UnknownCustomer,
            'received_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('admin.incoming-emails.index'))
            ->assertOk()
            ->assertSee('IRA Learning Center', false)
            ->assertDontSee('data-ira-bulk-form', false);

        $this->actingAs($user)
            ->post(route('admin.incoming-emails.learning.apply'), [
                'action' => 'importance',
                'message_ids' => [$message->id],
                'importance' => IncomingEmailImportance::High->value,
                'scope' => IncomingEmailLearningScope::ThisEmail->value,
            ])
            ->assertForbidden();

        $this->assertSame(IncomingEmailMessageStatus::NeedsReview, $message->fresh()->status);
    }

    public function test_support_agent_can_apply_learning_action(): void
    {
        $agent = $this->userWithRole(RolePermissionSeeder::ROLE_AGENT, 'support-teacher@test.com');

        $message = IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'fixture',
            'provider_message_id' => 'support-teach-1',
            'from_email' => 'vip@acme.com',
            'subject' => 'Urgent help',
            'preview' => 'Please call',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'received_at' => now(),
        ]);

        $this->actingAs($agent)
            ->post(route('admin.incoming-emails.learning.apply'), [
                'action' => 'classification',
                'message_ids' => [$message->id],
                'classification' => IncomingEmailOperatorClassification::Support->value,
                'scope' => IncomingEmailLearningScope::SameSender->value,
            ])
            ->assertRedirect(route('admin.incoming-emails.index', [
                'queue' => IncomingEmailIntakeQueue::NeedsHuman->value,
            ]));

        $this->assertSame(
            IncomingEmailClassification::Support,
            $message->fresh()->classification,
        );
    }

    public function test_disabled_inbound_email_returns_404(): void
    {
        config(['inbound_email.enabled' => false]);

        $admin = $this->userWithRole(RolePermissionSeeder::ROLE_ADMIN, 'disabled-intake@test.com');

        $this->actingAs($admin)
            ->get(route('admin.incoming-emails.index'))
            ->assertNotFound();
    }

    private function userWithRole(string $role, string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function sidebarHtml(User $user): string
    {
        return (string) $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();
    }
}

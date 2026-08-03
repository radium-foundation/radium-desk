<?php

namespace Tests\Feature\Administration;

use App\Enums\CommunicationTemplates\CommunicationTemplateCategory;
use App\Enums\CommunicationTemplates\CommunicationTemplateChannel;
use App\Enums\CommunicationTemplates\CommunicationTemplateGreetingStyle;
use App\Enums\CommunicationTemplates\CommunicationTemplateSignatureMode;
use App\Enums\CommunicationTemplates\CommunicationTemplateStatus;
use App\Models\CommunicationTemplate;
use App\Models\User;
use App\Services\CommunicationTemplates\CommunicationTemplateBladeImporter;
use App\Services\CommunicationTemplates\CommunicationTemplatePreviewService;
use App\Services\CommunicationTemplates\CommunicationTemplateStoreService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunicationTemplateStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_superadmin_can_create_revise_approve_and_rollback(): void
    {
        $superadmin = $this->makeUser(RolePermissionSeeder::ROLE_SUPERADMIN);

        $create = $this->actingAs($superadmin)->post(route('admin.communication-templates.store'), [
            'name' => 'Refund Confirmation Store',
            'category' => CommunicationTemplateCategory::Refund->value,
            'channels' => [CommunicationTemplateChannel::Email->value],
            'subject' => 'Your Refund Has Been Processed',
            'greeting_style' => CommunicationTemplateGreetingStyle::HelloCustomer->value,
            'body_html' => '<p>Refund {{refund_amount}} ref {{refund_reference}}</p>',
            'signature_mode' => CommunicationTemplateSignatureMode::CompanyDefault->value,
        ]);

        $template = CommunicationTemplate::query()->first();
        $this->assertNotNull($template);
        $create->assertRedirect(route('admin.communication-templates.show', $template));
        $this->assertSame(1, $template->current_version);
        $this->assertSame(CommunicationTemplateStatus::Draft, $template->status);

        $this->actingAs($superadmin)->post(route('admin.communication-templates.approve', $template))
            ->assertRedirect();
        $this->assertSame(CommunicationTemplateStatus::Approved, $template->fresh()->status);

        $this->actingAs($superadmin)->put(route('admin.communication-templates.update', $template), [
            'name' => 'Refund Confirmation Store',
            'category' => CommunicationTemplateCategory::Refund->value,
            'channels' => [CommunicationTemplateChannel::Email->value],
            'subject' => 'Your Refund Has Been Processed',
            'greeting_style' => CommunicationTemplateGreetingStyle::DearCustomer->value,
            'body_html' => '<p>Updated refund {{refund_amount}}</p>',
            'signature_mode' => CommunicationTemplateSignatureMode::CompanyDefault->value,
            'change_reason' => 'Copy tweak',
        ])->assertRedirect();

        $template = $template->fresh();
        $this->assertSame(2, $template->current_version);
        $this->assertCount(2, $template->versions);

        $this->actingAs($superadmin)->post(route('admin.communication-templates.rollback', $template), [
            'version' => 1,
            'change_reason' => 'Restore original',
        ])->assertRedirect();

        $template = $template->fresh();
        $this->assertSame(3, $template->current_version);
        $this->assertStringContainsString('Refund {{refund_amount}}', $template->currentVersionRecord()?->body_html ?? '');
    }

    public function test_admin_can_view_but_not_manage(): void
    {
        $superadmin = $this->makeUser(RolePermissionSeeder::ROLE_SUPERADMIN);
        $admin = $this->makeUser(RolePermissionSeeder::ROLE_ADMIN);

        $template = app(CommunicationTemplateStoreService::class)->create([
            'name' => 'Support Closed',
            'category' => CommunicationTemplateCategory::Support->value,
            'channels' => [CommunicationTemplateChannel::Email->value],
            'subject' => 'Closed',
            'greeting_style' => CommunicationTemplateGreetingStyle::HelloCustomer->value,
            'body_html' => '<p>Done</p>',
            'signature_mode' => CommunicationTemplateSignatureMode::None->value,
            'status' => CommunicationTemplateStatus::Approved->value,
        ], $superadmin);

        $this->actingAs($admin)->get(route('admin.communication-templates.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.communication-templates.show', $template))->assertOk();
        $this->actingAs($admin)->get(route('admin.communication-templates.create'))->assertForbidden();
        $this->actingAs($admin)->post(route('admin.communication-templates.store'), [
            'name' => 'Nope',
            'category' => CommunicationTemplateCategory::General->value,
            'channels' => [CommunicationTemplateChannel::Email->value],
            'greeting_style' => CommunicationTemplateGreetingStyle::None->value,
            'body_html' => '<p>x</p>',
            'signature_mode' => CommunicationTemplateSignatureMode::None->value,
        ])->assertForbidden();
    }

    public function test_operations_admin_has_no_access(): void
    {
        $ops = $this->makeUser(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $this->actingAs($ops)->get(route('admin.communication-templates.index'))->assertForbidden();
    }

    public function test_preview_renders_variables(): void
    {
        $superadmin = $this->makeUser(RolePermissionSeeder::ROLE_SUPERADMIN);
        $template = app(CommunicationTemplateStoreService::class)->create([
            'name' => 'Previewable',
            'category' => CommunicationTemplateCategory::General->value,
            'channels' => [CommunicationTemplateChannel::Email->value],
            'subject' => 'Hello {customer_name}',
            'greeting_style' => CommunicationTemplateGreetingStyle::HelloCustomer->value,
            'body_html' => '<p>Order {{order_id}}</p>',
            'signature_mode' => CommunicationTemplateSignatureMode::CompanyDefault->value,
        ], $superadmin);

        $preview = app(CommunicationTemplatePreviewService::class)->previewTemplate($template, [
            'customer_name' => 'Asha',
            'order_id' => 'RD1',
            'company_name' => 'Radium',
        ], $superadmin);

        $this->assertStringContainsString('Asha', $preview['html']);
        $this->assertStringContainsString('RD1', $preview['html']);
        $this->assertStringContainsString('Hello Asha', $preview['subject'] ?? $preview['html']);
    }

    public function test_blade_import_inventory_and_import(): void
    {
        $superadmin = $this->makeUser(RolePermissionSeeder::ROLE_SUPERADMIN);
        $importer = app(CommunicationTemplateBladeImporter::class);

        $inventory = $importer->inventory();
        $this->assertNotEmpty($inventory);
        $this->assertTrue(collect($inventory)->contains(fn (array $row): bool => $row['notification_type'] === 'refund_confirmation'));

        $result = $importer->importAll($superadmin, approve: true);
        $this->assertGreaterThan(0, $result['imported']);
        $this->assertDatabaseHas('communication_templates', [
            'notification_type' => 'refund_confirmation',
            'status' => CommunicationTemplateStatus::Approved->value,
            'runtime_source' => 'store',
        ]);

        $second = $importer->importAll($superadmin, approve: true);
        $this->assertSame(0, $second['imported']);
        $this->assertGreaterThan(0, $second['skipped']);
    }

    public function test_agent_cannot_access_template_store(): void
    {
        $agent = $this->makeUser(RolePermissionSeeder::ROLE_AGENT);
        $this->actingAs($agent)->get(route('admin.communication-templates.index'))->assertForbidden();
    }

    public function test_imported_templates_prefer_store_runtime_with_blade_fallback(): void
    {
        $superadmin = $this->makeUser(RolePermissionSeeder::ROLE_SUPERADMIN);
        app(CommunicationTemplateBladeImporter::class)->importAll($superadmin, approve: true);

        $this->assertDatabaseHas('communication_templates', [
            'notification_type' => 'refund_confirmation',
            'runtime_source' => 'store',
        ]);
        $this->assertTrue(
            CommunicationTemplate::query()
                ->where('notification_type', 'refund_confirmation')
                ->where('approved_version', '>', 0)
                ->exists()
        );
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}

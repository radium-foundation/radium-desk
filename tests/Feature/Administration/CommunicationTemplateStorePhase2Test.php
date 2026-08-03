<?php

namespace Tests\Feature\Administration;

use App\Data\NotificationMessage;
use App\Enums\CommunicationTemplates\CommunicationTemplateCategory;
use App\Enums\CommunicationTemplates\CommunicationTemplateChannel;
use App\Enums\CommunicationTemplates\CommunicationTemplateGreetingStyle;
use App\Enums\CommunicationTemplates\CommunicationTemplateSignatureMode;
use App\Enums\CommunicationTemplates\CommunicationTemplateStatus;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\NotificationType;
use App\Models\CommunicationTemplate;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\CommunicationTemplates\CommunicationTemplateBladeImporter;
use App\Services\CommunicationTemplates\CommunicationTemplateComparisonService;
use App\Services\CommunicationTemplates\CommunicationTemplateRuntimeService;
use App\Services\CommunicationTemplates\CommunicationTemplateSignatureBuilder;
use App\Services\CommunicationTemplates\CommunicationTemplateStoreService;
use App\Services\CommunicationTemplates\CommunicationTemplateTestSendService;
use App\Services\IncidentReferenceService;
use App\Services\Notifications\Channels\EmailChannel;
use App\Services\OutgoingEmail\OutgoingEmailTemplatePreviewService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CommunicationTemplateStorePhase2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config([
            'mail.enabled' => true,
            'mail.default' => 'array',
        ]);
    }

    public function test_runtime_uses_approved_store_then_falls_back_to_blade(): void
    {
        $superadmin = $this->makeUser(RolePermissionSeeder::ROLE_SUPERADMIN);
        app(CommunicationTemplateBladeImporter::class)->importAll($superadmin, approve: true);

        [$message] = $this->makeMessage(NotificationType::RefundConfirmation);
        $result = app(EmailChannel::class)->send($message);

        $this->assertTrue($result->success);
        $this->assertSame('store', $result->metadata['runtime_source']);
        $this->assertFalse($result->metadata['used_fallback']);

        $template = CommunicationTemplate::query()
            ->where('notification_type', NotificationType::RefundConfirmation->value)
            ->first();
        $this->assertNotNull($template);
        $template->update(['approved_version' => 9999]);

        Log::spy();
        $result = app(EmailChannel::class)->send($message);
        $this->assertTrue($result->success);
        $this->assertSame('blade', $result->metadata['runtime_source']);
        $this->assertTrue($result->metadata['used_fallback']);
        $this->assertGreaterThan(0, $template->fresh()->fallback_count);
    }

    public function test_edit_approved_creates_draft_without_changing_runtime_snapshot(): void
    {
        $superadmin = $this->makeUser(RolePermissionSeeder::ROLE_SUPERADMIN);
        $store = app(CommunicationTemplateStoreService::class);
        $template = $store->create([
            'name' => 'Support Closed Playbook',
            'category' => CommunicationTemplateCategory::Support->value,
            'channels' => [CommunicationTemplateChannel::Email->value],
            'subject' => 'Closed',
            'greeting_style' => CommunicationTemplateGreetingStyle::HelloCustomer->value,
            'body_html' => '<p>Original</p>',
            'signature_mode' => CommunicationTemplateSignatureMode::UserSignature->value,
            'status' => CommunicationTemplateStatus::Approved->value,
            'notification_type' => NotificationType::ServiceCaseClosed->value,
            'is_reply_playbook' => true,
            'playbook_scope' => 'global',
        ], $superadmin);

        $this->assertSame(1, $template->approved_version);

        $store->revise($template, [
            'body_html' => '<p>Draft change</p>',
            'greeting_style' => CommunicationTemplateGreetingStyle::DearCustomer->value,
            'signature_mode' => CommunicationTemplateSignatureMode::UserSignature->value,
            'change_reason' => 'Copy tweak',
        ], $superadmin);

        $template = $template->fresh();
        $this->assertSame(CommunicationTemplateStatus::Draft, $template->status);
        $this->assertSame(1, $template->approved_version);
        $this->assertSame(2, $template->current_version);
        $this->assertStringContainsString('Original', $template->approvedVersionRecord()?->body_html ?? '');

        $store->approve($template, $superadmin);
        $template = $template->fresh();
        $this->assertSame(2, $template->approved_version);
        $this->assertSame(CommunicationTemplateStatus::Approved, $template->status);
    }

    public function test_comparison_and_health_dashboard(): void
    {
        $superadmin = $this->makeUser(RolePermissionSeeder::ROLE_SUPERADMIN);
        app(CommunicationTemplateBladeImporter::class)->importAll($superadmin, approve: true);
        $template = CommunicationTemplate::query()->where('notification_type', 'refund_confirmation')->first();

        $comparison = app(CommunicationTemplateComparisonService::class)->compare($template, $superadmin);
        $this->assertArrayHasKey('blade_html', $comparison);
        $this->assertArrayHasKey('store_html', $comparison);
        $this->assertArrayHasKey('diff_ratio', $comparison);

        $this->actingAs($superadmin)
            ->get(route('admin.communication-health.index'))
            ->assertOk()
            ->assertSee('Communication Health')
            ->assertSee('Migration Progress');

        $this->actingAs($superadmin)
            ->get(route('admin.communication-templates.compare', $template))
            ->assertOk()
            ->assertSee('Blade output');
    }

    public function test_test_send_is_superadmin_only(): void
    {
        $superadmin = $this->makeUser(RolePermissionSeeder::ROLE_SUPERADMIN);
        $admin = $this->makeUser(RolePermissionSeeder::ROLE_ADMIN);
        app(CommunicationTemplateBladeImporter::class)->importAll($superadmin, approve: true);
        $template = CommunicationTemplate::query()->where('notification_type', 'refund_confirmation')->first();

        $this->actingAs($admin)
            ->post(route('admin.communication-templates.test-send', $template), [
                'recipient_email' => 'admin@example.com',
            ])
            ->assertForbidden();

        $result = app(CommunicationTemplateTestSendService::class)->send(
            $template,
            $superadmin,
            'superadmin@example.com',
        );
        $this->assertTrue($result['success']);
    }

    public function test_reply_playbooks_and_automatic_signature(): void
    {
        $superadmin = $this->makeUser(RolePermissionSeeder::ROLE_SUPERADMIN);
        $superadmin->forceFill([
            'designation' => 'Support Lead',
            'department' => 'Customer Success',
            'phone' => '9999999999',
            'company_name' => 'Radium',
            'default_greeting_style' => CommunicationTemplateGreetingStyle::DearCustomer->value,
        ])->save();

        app(CommunicationTemplateBladeImporter::class)->importAll($superadmin, approve: true);

        $playbooks = app(OutgoingEmailTemplatePreviewService::class)->availableTemplates($superadmin);
        $this->assertTrue(collect($playbooks)->contains(fn (array $row): bool => $row['key'] === 'blank'));
        $this->assertTrue(collect($playbooks)->contains(fn (array $row): bool => str_starts_with($row['key'], 'playbook:')));

        $signature = app(CommunicationTemplateSignatureBuilder::class)->forUser($superadmin);
        $this->assertStringContainsString('Support Lead', $signature);
        $this->assertStringContainsString('Customer Success', $signature);
        $this->assertStringContainsString($superadmin->email, $signature);
    }

    public function test_runtime_render_without_store_still_uses_blade(): void
    {
        [$message] = $this->makeMessage(NotificationType::RequestSerialNumber);
        $rendered = app(CommunicationTemplateRuntimeService::class)->renderNotificationMessage($message);

        $this->assertSame('blade', $rendered['runtime_source']);
        $this->assertFalse($rendered['used_fallback']);
        $this->assertNotSame('', trim($rendered['html']));
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * @return array{0: NotificationMessage}
     */
    private function makeMessage(NotificationType $type): array
    {
        $agent = $this->makeUser(RolePermissionSeeder::ROLE_AGENT);
        $order = Order::query()->create([
            'order_id' => 'RD-P2-'.uniqid(),
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'customer_email' => 'customer@example.com',
            'customer_name' => 'Priya',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Phase 2 case',
            'description' => 'Phase 2 case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return [new NotificationMessage(
            type: $type,
            customer: $order,
            incident: $incident,
            actor: $agent,
            variables: [
                'refund_amount' => '₹100',
                'refund_reference' => 'RF-1',
                'company_name' => 'Radium',
            ],
        )];
    }
}

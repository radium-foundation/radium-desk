<?php

namespace Tests\Feature\IncomingEmail;

use App\Enums\IncomingEmailAttentionCategory;
use App\Enums\IncomingEmailClassification;
use App\Enums\IncomingEmailMessageStatus;
use App\Models\AuditLog;
use App\Models\IncomingEmailIgnoreStat;
use App\Models\IncomingEmailMessage;
use App\Models\Order;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\IncomingEmail\IncomingEmailAttentionCategoryService;
use App\Services\IncomingEmail\IncomingEmailIntakeCounterService;
use App\Services\IncomingEmail\IncomingEmailPriorityPhraseService;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomingEmailDashboardV2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'inbound_email.enabled' => true,
            'inbound_email.priority_phrases' => ['legal notice', 'consumer forum'],
            'inbound_email.mailboxes' => [
                'support@radiumbox.com' => 'support',
                'sales@radiumbox.com' => 'sales',
            ],
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        User::factory()->create([
            'name' => 'System',
            'email' => 'superadmin@radium.local',
        ])->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);
    }

    public function test_dashboard_shows_email_intake_kpi_card_with_needs_attention_total(): void
    {
        $admin = $this->createAdmin('v2-admin@test.com');

        IncomingEmailMessage::query()->create([
            'mailbox' => 'sales@radiumbox.com',
            'channel' => 'sales',
            'provider' => 'fixture',
            'provider_message_id' => 'sales-1',
            'from_email' => 'lead@example.com',
            'subject' => 'Buy device',
            'preview' => 'Pricing enquiry',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'classification' => IncomingEmailClassification::PossibleSalesLead,
            'received_at' => now(),
        ]);

        IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'channel' => 'support',
            'provider' => 'fixture',
            'provider_message_id' => 'order-1',
            'from_email' => 'known@example.com',
            'subject' => 'Follow up',
            'preview' => 'Order help',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'classification' => IncomingEmailClassification::ExistingCustomer,
            'order_id' => $this->seedOrder('known@example.com')->id,
            'received_at' => now(),
        ]);

        $html = (string) $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('data-email-intake-kpi', $html);
        $this->assertStringContainsString('Email Intake', $html);
        $this->assertStringContainsString('Needs Attention', $html);
        $this->assertStringContainsString('Escalations', $html);
        $this->assertStringContainsString('admin/incoming-emails?queue=needs_human', $html);
        $this->assertStringNotContainsString('data-email-intake-counters', $html);
    }

    public function test_needs_attention_equals_sales_plus_orders_plus_escalations(): void
    {
        $admin = $this->createAdmin('v2-aggregate@test.com');

        IncomingEmailMessage::query()->create([
            'mailbox' => 'sales@radiumbox.com',
            'channel' => 'sales',
            'provider' => 'fixture',
            'provider_message_id' => 'agg-sales',
            'from_email' => 'sales@example.com',
            'subject' => 'Buy',
            'preview' => 'Buy',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'classification' => IncomingEmailClassification::PossibleSalesLead,
            'received_at' => now(),
        ]);

        IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'channel' => 'support',
            'provider' => 'fixture',
            'provider_message_id' => 'agg-orders',
            'from_email' => 'orders@example.com',
            'subject' => 'Help',
            'preview' => 'Help',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'classification' => IncomingEmailClassification::Support,
            'order_id' => $this->seedOrder('orders@example.com')->id,
            'received_at' => now(),
        ]);

        IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'channel' => 'support',
            'provider' => 'fixture',
            'provider_message_id' => 'agg-priority',
            'from_email' => 'legal@example.com',
            'subject' => 'Legal notice regarding product',
            'preview' => 'Formal complaint',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'classification' => IncomingEmailClassification::UnknownCustomer,
            'received_at' => now(),
        ]);

        $widget = app(IncomingEmailIntakeCounterService::class)->dashboardWidget($admin);

        $this->assertNotNull($widget);
        $this->assertSame(3, $widget['needs_attention']);
        $this->assertSame(1, $widget['hover']['needs_attention'][0]['count']);
        $this->assertSame('Sales', $widget['hover']['needs_attention'][0]['label']);
        $this->assertSame(1, $widget['hover']['needs_attention'][1]['count']);
        $this->assertSame('Orders', $widget['hover']['needs_attention'][1]['label']);
        $this->assertSame(1, $widget['hover']['needs_attention'][2]['count']);
        $this->assertSame('Escalations', $widget['hover']['needs_attention'][2]['label']);
    }

    public function test_hover_includes_ignored_promotions_spam_and_automatic_counts(): void
    {
        $admin = $this->createAdmin('v2-hover@test.com');
        $today = now()->toDateString();

        IncomingEmailIgnoreStat::query()->create(['stat_date' => $today, 'reason' => 'promotions', 'count' => 26]);
        IncomingEmailIgnoreStat::query()->create(['stat_date' => $today, 'reason' => 'spam', 'count' => 12]);
        IncomingEmailIgnoreStat::query()->create(['stat_date' => $today, 'reason' => 'auto_responder', 'count' => 43]);

        $widget = app(IncomingEmailIntakeCounterService::class)->dashboardWidget($admin);

        $this->assertSame(26, $widget['hover']['ignored'][0]['count']);
        $this->assertSame(12, $widget['hover']['ignored'][1]['count']);
        $this->assertSame(43, $widget['hover']['ignored'][2]['count']);
    }

    public function test_escalation_phrase_detection_is_auditable(): void
    {
        config(['inbound_email.priority_phrases' => ['consumer forum']]);

        $message = IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'fixture',
            'provider_message_id' => 'priority-1',
            'from_email' => 'forum@example.com',
            'subject' => 'Consumer forum complaint',
            'preview' => 'Escalation',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'received_at' => now(),
        ]);

        $category = app(IncomingEmailAttentionCategoryService::class)->categorize(
            $message,
            collect(),
        );

        $this->assertSame(IncomingEmailAttentionCategory::Priority, $category);
        $this->assertSame('Escalations', IncomingEmailAttentionCategory::Priority->label());

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'incoming_email.priority_detected',
            'auditable_id' => $message->id,
        ]);

        $audit = AuditLog::query()
            ->where('event', 'incoming_email.priority_detected')
            ->where('auditable_id', $message->id)
            ->first();

        $this->assertSame('consumer forum', $audit->new_values['matched_phrase'] ?? null);
        $this->assertSame('config:inbound_email.priority_phrases', $audit->new_values['rule_source'] ?? null);
    }

    public function test_zero_state_shows_card_with_normal_severity(): void
    {
        $admin = $this->createAdmin('v2-zero@test.com');

        $widget = app(IncomingEmailIntakeCounterService::class)->dashboardWidget($admin);

        $this->assertNotNull($widget);
        $this->assertSame(0, $widget['needs_attention']);
        $this->assertSame('normal', $widget['severity']);

        $html = (string) $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('data-email-intake-kpi', $html);
    }

    public function test_severity_thresholds_for_needs_attention_count(): void
    {
        $service = app(IncomingEmailIntakeCounterService::class);

        $this->assertSame('normal', $service->severityForCount(0));
        $this->assertSame('blue', $service->severityForCount(3));
        $this->assertSame('amber', $service->severityForCount(10));
        $this->assertSame('red', $service->severityForCount(20));
    }

    public function test_agent_without_email_admin_permission_does_not_see_widget(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->assertNull(app(IncomingEmailIntakeCounterService::class)->dashboardWidget($agent));

        $html = (string) $this->actingAs($agent)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-email-intake-kpi', $html);
    }

    public function test_sales_and_orders_categorization(): void
    {
        $service = app(IncomingEmailAttentionCategoryService::class);

        $salesMessage = IncomingEmailMessage::query()->create([
            'mailbox' => 'sales@radiumbox.com',
            'channel' => 'sales',
            'provider' => 'fixture',
            'provider_message_id' => 'cat-sales',
            'from_email' => 'lead@example.com',
            'subject' => 'Quote',
            'preview' => 'Quote',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'classification' => IncomingEmailClassification::PossibleSalesLead,
            'received_at' => now(),
        ]);

        $ordersMessage = IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'channel' => 'support',
            'provider' => 'fixture',
            'provider_message_id' => 'cat-orders',
            'from_email' => 'customer@example.com',
            'subject' => 'Service',
            'preview' => 'Service',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'classification' => IncomingEmailClassification::Support,
            'order_id' => $this->seedOrder('customer@example.com')->id,
            'received_at' => now(),
        ]);

        $this->assertSame(
            IncomingEmailAttentionCategory::Sales,
            $service->categorize($salesMessage, collect()),
        );
        $this->assertSame(
            IncomingEmailAttentionCategory::Orders,
            $service->categorize($ordersMessage, collect()),
        );
    }

    private function seedOrder(string $email): Order
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return Order::query()->create([
            'order_id' => 'RD-V2-'.uniqid(),
            'serial_number' => 'SN-V2-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Customer',
            'customer_phone' => '9876501234',
            'customer_email' => $email,
            'status' => 'active',
            'created_by' => $creator->id,
        ]);
    }

    private function createAdmin(string $email): User
    {
        $admin = User::factory()->create(['email' => $email]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        app(SettingService::class)->setMany([
            'assignment.communication_intake_primary_user_id' => (string) $admin->id,
        ]);

        $this->assertTrue($admin->can('update', SystemSetting::class));

        return $admin;
    }
}

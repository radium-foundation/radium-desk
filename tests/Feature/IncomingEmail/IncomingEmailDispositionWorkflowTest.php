<?php

namespace Tests\Feature\IncomingEmail;

use App\Enums\IncomingEmailClassification;
use App\Enums\IncomingEmailDisposition;
use App\Enums\IncomingEmailIgnoreDispositionVariant;
use App\Enums\IncomingEmailIntakeQueue;
use App\Enums\IncomingEmailKeepPendingReason;
use App\Enums\IncomingEmailLearningScope;
use App\Enums\IncomingEmailMessageStatus;
use App\Enums\IncomingEmailOperatorClassification;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\IncomingEmailLearningRule;
use App\Models\IncomingEmailMessage;
use App\Models\Order;
use App\Models\User;
use App\Services\IncomingEmail\IncomingEmailIntakeCounterService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomingEmailDispositionWorkflowTest extends TestCase
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

        $system = User::factory()->create([
            'name' => 'System',
            'email' => 'superadmin@radium.local',
        ]);
        $system->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $dayAdmin = User::factory()->create(['email' => 'day-disp@test.com', 'is_active' => true]);
        $dayAdmin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $nightAdmin = User::factory()->create(['email' => 'night-disp@test.com', 'is_active' => true]);
        $nightAdmin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        app(\App\Services\SettingService::class)->setMany([
            'assignment.timezone' => config('app.timezone'),
            'assignment.day_shift_start' => '09:00',
            'assignment.day_shift_end' => '18:30',
            'assignment.day_shift_admin_user_id' => (string) $dayAdmin->id,
            'assignment.night_shift_admin_user_id' => (string) $nightAdmin->id,
            'assignment.communication_intake_primary_user_id' => (string) $dayAdmin->id,
            'assignment.communication_intake_fallback_user_id' => (string) $nightAdmin->id,
        ]);
    }

    public function test_assign_plus_create_case_leaves_queue_and_assigns_owner(): void
    {
        $admin = $this->createAdmin('disp-create@test.com');
        $assignee = $this->createAdmin('disp-owner@test.com');

        $message = $this->needsHumanMessage('create-1', 'lead@buyer.com', 'Need quote');

        $this->actingAs($admin)
            ->post(route('admin.incoming-emails.learning.apply'), [
                'action' => 'assign',
                'message_ids' => [$message->id],
                'assignee_user_id' => $assignee->id,
                'scope' => IncomingEmailLearningScope::ThisEmail->value,
            ])
            ->assertRedirect();

        $this->assertSame(IncomingEmailMessageStatus::NeedsReview, $message->fresh()->status);

        $before = app(IncomingEmailIntakeCounterService::class)->needsHumanCount();

        $this->actingAs($admin)
            ->post(route('admin.incoming-emails.disposition.apply'), [
                'disposition' => IncomingEmailDisposition::CreateCase->value,
                'message_ids' => [$message->id],
                'assignee_user_id' => $assignee->id,
            ])
            ->assertRedirect(route('admin.incoming-emails.index', [
                'queue' => IncomingEmailIntakeQueue::NeedsHuman->value,
            ]));

        $message->refresh();
        $this->assertSame(IncomingEmailMessageStatus::Linked, $message->status);
        $this->assertSame(IncomingEmailDisposition::CreateCase, $message->disposition);
        $this->assertNotNull($message->incident_id);
        $this->assertNotNull($message->disposed_at);
        $this->assertSame($admin->id, $message->disposed_by_user_id);

        $incident = Incident::query()->findOrFail($message->incident_id);
        $this->assertSame(IncidentSource::Email, $incident->source);
        $this->assertSame($assignee->id, $incident->assigned_to_user_id);

        $this->assertSame(
            $before - 1,
            app(IncomingEmailIntakeCounterService::class)->needsHumanCount(),
        );

        $this->assertTrue(
            AuditLog::query()
                ->where('event', 'incoming_email.disposition')
                ->where('auditable_id', $message->id)
                ->exists(),
        );
    }

    public function test_docs_teach_plus_link_case_leaves_queue(): void
    {
        $admin = $this->createAdmin('disp-link@test.com');
        $existing = $this->seedOpenCase('SC28801');

        $message = $this->needsHumanMessage('link-1', 'shop@vendor.com', 'Order confirmed');

        $this->actingAs($admin)
            ->post(route('admin.incoming-emails.learning.apply'), [
                'action' => 'classification',
                'message_ids' => [$message->id],
                'classification' => IncomingEmailOperatorClassification::Docs->value,
                'scope' => IncomingEmailLearningScope::SameSubjectPattern->value,
            ])
            ->assertRedirect();

        $message->refresh();
        $this->assertSame(IncomingEmailClassification::Docs, $message->classification);
        $this->assertSame(IncomingEmailMessageStatus::NeedsReview, $message->status);

        $this->actingAs($admin)
            ->post(route('admin.incoming-emails.disposition.apply'), [
                'disposition' => IncomingEmailDisposition::LinkCase->value,
                'message_ids' => [$message->id],
                'case_reference' => $existing->reference_no,
            ])
            ->assertRedirect();

        $message->refresh();
        $this->assertSame(IncomingEmailMessageStatus::Linked, $message->status);
        $this->assertSame(IncomingEmailDisposition::LinkCase, $message->disposition);
        $this->assertSame($existing->id, $message->incident_id);
        $this->assertDatabaseHas('incoming_email_learning_rules', [
            'decision_value' => IncomingEmailOperatorClassification::Docs->value,
        ]);
    }

    public function test_ignore_spam_promotion_auto_processed_leave_queue(): void
    {
        $admin = $this->createAdmin('disp-ignore@test.com');

        $ignore = $this->needsHumanMessage('ig-1', 'noise@example.com', 'Ping');
        $spam = $this->needsHumanMessage('sp-1', 'spam@example.com', 'Buy now');
        $promo = $this->needsHumanMessage('pr-1', 'promo@example.com', 'Sale');
        $auto = $this->needsHumanMessage('au-1', 'auto@example.com', 'Out of office');

        $this->actingAs($admin)->post(route('admin.incoming-emails.disposition.apply'), [
            'disposition' => IncomingEmailDisposition::Ignore->value,
            'message_ids' => [$ignore->id],
            'ignore_variant' => IncomingEmailIgnoreDispositionVariant::AlwaysSender->value,
        ])->assertRedirect();

        $this->actingAs($admin)->post(route('admin.incoming-emails.disposition.apply'), [
            'disposition' => IncomingEmailDisposition::Spam->value,
            'message_ids' => [$spam->id],
        ])->assertRedirect();

        $this->actingAs($admin)->post(route('admin.incoming-emails.disposition.apply'), [
            'disposition' => IncomingEmailDisposition::Promotion->value,
            'message_ids' => [$promo->id],
        ])->assertRedirect();

        $this->actingAs($admin)->post(route('admin.incoming-emails.disposition.apply'), [
            'disposition' => IncomingEmailDisposition::AutoProcessed->value,
            'message_ids' => [$auto->id],
        ])->assertRedirect();

        $this->assertSame(IncomingEmailMessageStatus::Ignored, $ignore->fresh()->status);
        $this->assertSame(IncomingEmailDisposition::Ignore, $ignore->fresh()->disposition);
        $this->assertSame(IncomingEmailMessageStatus::Ignored, $spam->fresh()->status);
        $this->assertSame(IncomingEmailDisposition::Spam, $spam->fresh()->disposition);
        $this->assertSame(IncomingEmailClassification::Spam, $spam->fresh()->classification);
        $this->assertSame(IncomingEmailMessageStatus::Ignored, $promo->fresh()->status);
        $this->assertSame(IncomingEmailDisposition::Promotion, $promo->fresh()->disposition);
        $this->assertSame(IncomingEmailMessageStatus::Ignored, $auto->fresh()->status);
        $this->assertSame(IncomingEmailDisposition::AutoProcessed, $auto->fresh()->disposition);

        $this->assertGreaterThan(0, IncomingEmailLearningRule::query()->count());
        $this->assertSame(0, app(IncomingEmailIntakeCounterService::class)->needsHumanCount());
    }

    public function test_keep_pending_requires_reason_and_stays_in_needs_human(): void
    {
        $admin = $this->createAdmin('disp-pending@test.com');
        $message = $this->needsHumanMessage('kp-1', 'wait@example.com', 'Need serial');

        $this->actingAs($admin)
            ->post(route('admin.incoming-emails.disposition.apply'), [
                'disposition' => IncomingEmailDisposition::KeepPending->value,
                'message_ids' => [$message->id],
            ])
            ->assertSessionHasErrors('keep_pending_reason');

        $this->actingAs($admin)
            ->post(route('admin.incoming-emails.disposition.apply'), [
                'disposition' => IncomingEmailDisposition::KeepPending->value,
                'message_ids' => [$message->id],
                'keep_pending_reason' => IncomingEmailKeepPendingReason::NeedOrderNumber->value,
            ])
            ->assertRedirect();

        $message->refresh();
        $this->assertSame(IncomingEmailMessageStatus::NeedsReview, $message->status);
        $this->assertSame(IncomingEmailDisposition::KeepPending, $message->disposition);
        $this->assertSame(IncomingEmailKeepPendingReason::NeedOrderNumber->value, $message->disposition_reason);
        $this->assertSame(1, app(IncomingEmailIntakeCounterService::class)->needsHumanCount());

        $html = (string) $this->actingAs($admin)
            ->get(route('admin.incoming-emails.index', ['queue' => IncomingEmailIntakeQueue::NeedsHuman->value]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Need Order Number', $html);
        $this->assertStringContainsString('Disposition', $html);
        $this->assertStringContainsString('Create Service Case', $html);
    }

    public function test_docs_classification_alone_does_not_dispose(): void
    {
        $admin = $this->createAdmin('docs-only@test.com');
        $message = $this->needsHumanMessage('docs-1', 'docs@shop.com', 'Invoice');

        $this->actingAs($admin)
            ->post(route('admin.incoming-emails.learning.apply'), [
                'action' => 'classification',
                'message_ids' => [$message->id],
                'classification' => IncomingEmailOperatorClassification::Docs->value,
                'scope' => IncomingEmailLearningScope::ThisEmail->value,
            ])
            ->assertRedirect();

        $message->refresh();
        $this->assertSame(IncomingEmailClassification::Docs, $message->classification);
        $this->assertSame(IncomingEmailMessageStatus::NeedsReview, $message->status);
        $this->assertNull($message->disposition);
    }

    public function test_dashboard_widget_drops_after_disposition(): void
    {
        $admin = $this->createAdmin('widget-disp@test.com');
        $message = $this->needsHumanMessage('wd-1', 'sales@lead.com', 'Buy device');

        $widgetBefore = app(IncomingEmailIntakeCounterService::class)->dashboardWidget($admin);
        $this->assertSame(1, $widgetBefore['needs_attention']);

        $this->actingAs($admin)->post(route('admin.incoming-emails.disposition.apply'), [
            'disposition' => IncomingEmailDisposition::Spam->value,
            'message_ids' => [$message->id],
        ])->assertRedirect();

        $widgetAfter = app(IncomingEmailIntakeCounterService::class)->dashboardWidget($admin);
        $this->assertSame(0, $widgetAfter['needs_attention']);
    }

    private function needsHumanMessage(string $providerId, string $from, string $subject): IncomingEmailMessage
    {
        return IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'fixture',
            'provider_message_id' => $providerId,
            'from_email' => $from,
            'from_name' => 'Tester',
            'subject' => $subject,
            'preview' => $subject.' body',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'classification' => IncomingEmailClassification::PossibleSalesLead,
            'ignore_reason' => 'unknown_customer',
            'received_at' => now(),
        ]);
    }

    private function seedOpenCase(string $reference): Incident
    {
        $actorId = User::query()->first()->id;

        $order = Order::query()->create([
            'order_id' => 'RD-DISP-'.uniqid(),
            'serial_number' => 'SN-DISP-'.uniqid(),
            'product_name' => 'Device',
            'device_model' => 'Device',
            'customer_name' => 'Existing Customer',
            'customer_email' => 'existing-disp@example.com',
            'customer_phone' => '9999999999',
            'status' => OrderStatus::Active,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => $reference,
            'category' => 'Service',
            'source' => IncidentSource::Email,
            'title' => 'Existing case',
            'description' => 'Seeded for link disposition',
            'status' => IncidentStatus::Open,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);
    }

    private function createAdmin(string $email): User
    {
        $admin = User::factory()->create([
            'email' => $email,
            'is_active' => true,
        ]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $admin;
    }
}

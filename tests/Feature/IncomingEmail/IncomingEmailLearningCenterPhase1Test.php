<?php

namespace Tests\Feature\IncomingEmail;

use App\Data\IncomingEmail\NormalizedInboundEmail;
use App\Enums\IncomingEmailClassification;
use App\Enums\IncomingEmailIgnoreLearningAction;
use App\Enums\IncomingEmailImportance;
use App\Enums\IncomingEmailIntakeQueue;
use App\Enums\IncomingEmailLearningDecisionType;
use App\Enums\IncomingEmailLearningRuleType;
use App\Enums\IncomingEmailLearningScope;
use App\Enums\IncomingEmailMessageStatus;
use App\Enums\IncomingEmailOperatorClassification;
use App\Models\IncomingEmailLearningRule;
use App\Models\IncomingEmailMessage;
use App\Models\User;
use App\Services\IncomingEmail\IncomingEmailIngestService;
use App\Services\IncomingEmail\IncomingEmailProcessorService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomingEmailLearningCenterPhase1Test extends TestCase
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
            'inbound_email.ignored_labels' => ['SPAM', 'CATEGORY_PROMOTIONS'],
            'inbound_email.system_sender_patterns' => ['mailer-daemon@'],
            'inbound_email.system_from_names' => ['mail delivery subsystem'],
            'inbound_email.auto_responder_header_tokens' => ['auto-submitted'],
            'inbound_email.mailboxes' => ['support@radiumbox.com' => 'support'],
            'inbound_email.preview_max_chars' => 280,
            'inbound_email.blocked_senders' => [],
            'inbound_email.blocked_domains' => [],
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        User::factory()->create([
            'name' => 'System',
            'email' => 'superadmin@radium.local',
        ])->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);
    }

    public function test_needs_human_page_renders_operator_cards_without_internal_statuses(): void
    {
        $admin = $this->createAdmin('learning-admin@test.com');

        IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'fixture',
            'provider_message_id' => 'learn-1',
            'from_email' => 'buyer@example.com',
            'from_name' => 'Buyer One',
            'subject' => 'Need a quote',
            'preview' => 'Looking for pricing on your product line for next week.',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'classification' => IncomingEmailClassification::UnknownCustomer,
            'received_at' => now(),
        ]);

        $html = (string) $this->actingAs($admin)
            ->get(route('admin.incoming-emails.index', ['queue' => IncomingEmailIntakeQueue::NeedsHuman->value]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('IRA Learning Center', $html);
        $this->assertStringContainsString('Buyer One', $html);
        $this->assertStringContainsString('Need a quote', $html);
        $this->assertStringContainsString('IRA Suggestion', $html);
        $this->assertStringContainsString('Confidence', $html);
        $this->assertStringContainsString('Suggested Owner', $html);
        $this->assertStringContainsString('data-ira-row', $html);
        $this->assertStringContainsString('data-subject=', $html);
        $this->assertStringContainsString('data-preview=', $html);
        $this->assertStringContainsString('Unknown Customer', $html);
        $this->assertStringContainsString('data-expand-json', $html);
        $this->assertStringContainsString('Looking for pricing', $html);
        $this->assertStringNotContainsString('needs_review', $html);
        $this->assertStringNotContainsString('unknown_customer', $html);
        $this->assertStringNotContainsString('ira-learning-card__actions', $html);
        $this->assertStringContainsString('Completed Automatically', $html);
    }

    public function test_completed_automatically_queue_hides_confidence_and_owner(): void
    {
        $admin = $this->createAdmin('auto-ui@test.com');

        IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'fixture',
            'provider_message_id' => 'auto-ui-1',
            'from_email' => 'noreply@shop.com',
            'subject' => 'Order shipped',
            'preview' => 'Your package is on the way.',
            'status' => IncomingEmailMessageStatus::Ignored,
            'classification' => IncomingEmailClassification::OwnOutbound,
            'ignore_reason' => 'auto_responder',
            'received_at' => now(),
            'processed_at' => now(),
        ]);

        $html = (string) $this->actingAs($admin)
            ->get(route('admin.incoming-emails.index', ['queue' => IncomingEmailIntakeQueue::Automatic->value]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Completed Automatically', $html);
        $this->assertStringContainsString('Handled By', $html);
        $this->assertStringContainsString('ira-lc-row__handled', $html);
        $this->assertMatchesRegularExpression('/ira-lc-row__handled[^>]*>\s*IRA\s*</', $html);
        $this->assertStringNotContainsString('Suggested Owner', $html);
        $this->assertStringNotContainsString('>Confidence<', $html);
    }

    public function test_operator_assign_creates_sender_learning_rule(): void
    {
        $admin = $this->createAdmin('assign-teacher@test.com');
        $assignee = $this->createAdmin('assignee@test.com');

        $message = IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'fixture',
            'provider_message_id' => 'assign-1',
            'from_email' => 'vip@acme.com',
            'subject' => 'Urgent help',
            'preview' => 'Please call me',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'classification' => IncomingEmailClassification::PossibleSalesLead,
            'received_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.incoming-emails.learning.apply'), [
                'action' => 'assign',
                'message_ids' => [$message->id],
                'assignee_user_id' => $assignee->id,
                'scope' => IncomingEmailLearningScope::SameSender->value,
            ])
            ->assertRedirect(route('admin.incoming-emails.index', [
                'queue' => IncomingEmailIntakeQueue::NeedsHuman->value,
            ]))
            ->assertSessionHas('status');

        $message->refresh();
        $this->assertSame($assignee->id, $message->learning_owner_user_id);
        // Teaching Assign must NOT leave Needs Human.
        $this->assertSame(IncomingEmailMessageStatus::NeedsReview, $message->status);
        $this->assertNull($message->disposition);

        $this->assertDatabaseHas('incoming_email_learning_rules', [
            'rule_type' => IncomingEmailLearningRuleType::Sender->value,
            'match_value' => 'vip@acme.com',
            'decision_type' => IncomingEmailLearningDecisionType::Assign->value,
            'decision_value' => (string) $assignee->id,
            'enabled' => 1,
        ]);
    }

    public function test_bulk_ignore_and_classification_learning_actions(): void
    {
        $admin = $this->createAdmin('bulk-teacher@test.com');

        $one = IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'fixture',
            'provider_message_id' => 'bulk-1',
            'from_email' => 'news@vendor.com',
            'subject' => 'Weekly digest',
            'preview' => 'Newsletter content',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'received_at' => now(),
        ]);

        $two = IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'fixture',
            'provider_message_id' => 'bulk-2',
            'from_email' => 'news2@vendor.com',
            'subject' => 'Another digest',
            'preview' => 'More newsletter',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'received_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.incoming-emails.learning.apply'), [
                'action' => 'ignore',
                'message_ids' => [$one->id, $two->id],
                'ignore_action' => IncomingEmailIgnoreLearningAction::Newsletter->value,
                'scope' => IncomingEmailLearningScope::SameDomain->value,
            ])
            ->assertRedirect();

        $this->assertSame(IncomingEmailMessageStatus::Ignored, $one->fresh()->status);
        $this->assertSame(IncomingEmailMessageStatus::Ignored, $two->fresh()->status);

        $this->assertDatabaseHas('incoming_email_learning_rules', [
            'rule_type' => IncomingEmailLearningRuleType::SenderDomain->value,
            'match_value' => 'vendor.com',
            'decision_type' => IncomingEmailLearningDecisionType::Ignore->value,
            'decision_value' => IncomingEmailIgnoreLearningAction::Newsletter->value,
        ]);

        $sales = IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'fixture',
            'provider_message_id' => 'bulk-3',
            'from_email' => 'lead@buyer.com',
            'subject' => 'Pricing',
            'preview' => 'Quote please',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'received_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.incoming-emails.learning.apply'), [
                'action' => 'classification',
                'message_ids' => [$sales->id],
                'classification' => IncomingEmailOperatorClassification::Sales->value,
                'scope' => IncomingEmailLearningScope::SameSender->value,
            ])
            ->assertRedirect();

        $this->assertSame(
            IncomingEmailClassification::PossibleSalesLead,
            $sales->fresh()->classification,
        );
    }

    public function test_importance_action_persists_and_creates_rule(): void
    {
        $admin = $this->createAdmin('importance-teacher@test.com');

        $message = IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'fixture',
            'provider_message_id' => 'imp-1',
            'from_email' => 'legal@example.com',
            'subject' => 'Notice',
            'preview' => 'Formal notice',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'received_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.incoming-emails.learning.apply'), [
                'action' => 'importance',
                'message_ids' => [$message->id],
                'importance' => IncomingEmailImportance::Escalation->value,
                'scope' => IncomingEmailLearningScope::SameSubjectPattern->value,
            ])
            ->assertRedirect();

        $this->assertSame(IncomingEmailImportance::Escalation, $message->fresh()->importance);
        $this->assertDatabaseHas('incoming_email_learning_rules', [
            'rule_type' => IncomingEmailLearningRuleType::SubjectPattern->value,
            'decision_type' => IncomingEmailLearningDecisionType::Importance->value,
            'decision_value' => IncomingEmailImportance::Escalation->value,
        ]);
    }

    public function test_learning_rules_execute_before_intelligence_and_can_ignore(): void
    {
        $teacher = $this->createAdmin('rule-creator@test.com');

        IncomingEmailLearningRule::query()->create([
            'rule_type' => IncomingEmailLearningRuleType::Sender->value,
            'match_value' => 'noise@vendor.com',
            'decision_type' => IncomingEmailLearningDecisionType::Ignore->value,
            'decision_value' => IncomingEmailIgnoreLearningAction::AlwaysIgnore->value,
            'confidence' => 95,
            'created_by' => $teacher->id,
            'times_used' => 0,
            'enabled' => true,
        ]);

        $message = app(IncomingEmailIngestService::class)->ingest(new NormalizedInboundEmail(
            mailbox: 'support@radiumbox.com',
            provider: 'fixture',
            providerMessageId: 'rule-ignore-1',
            rfcMessageId: '<rule-ignore-1@radium.test>',
            threadId: 'thread-rule-1',
            fromEmail: 'noise@vendor.com',
            fromName: 'Noise',
            toEmails: ['support@radiumbox.com'],
            subject: 'Status update',
            preview: 'Automated vendor ping',
            receivedAt: now(),
            attachmentCount: 0,
            headers: [],
            labels: [],
            rawPayload: ['fixture' => true],
        ), processImmediately: false);

        $this->assertNotNull($message);
        app(IncomingEmailProcessorService::class)->process($message->fresh());

        $message->refresh();
        $this->assertSame(IncomingEmailMessageStatus::Ignored, $message->status);
        $this->assertNotNull($message->matched_learning_rule_id);
        $this->assertSame(1, IncomingEmailLearningRule::query()->first()->times_used);
    }

    public function test_this_email_only_scope_does_not_persist_rule(): void
    {
        $admin = $this->createAdmin('once-teacher@test.com');

        $message = IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'fixture',
            'provider_message_id' => 'once-1',
            'from_email' => 'once@example.com',
            'subject' => 'One time',
            'preview' => 'Ignore me once',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'received_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.incoming-emails.learning.apply'), [
                'action' => 'ignore',
                'message_ids' => [$message->id],
                'ignore_action' => IncomingEmailIgnoreLearningAction::IgnoreOnce->value,
                'scope' => IncomingEmailLearningScope::SameSender->value,
            ])
            ->assertRedirect();

        $this->assertSame(IncomingEmailMessageStatus::Ignored, $message->fresh()->status);
        $this->assertSame(0, IncomingEmailLearningRule::query()->count());
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

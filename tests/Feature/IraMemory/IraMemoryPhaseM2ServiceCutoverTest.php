<?php

namespace Tests\Feature\IraMemory;

use App\Data\IncomingEmail\NormalizedInboundEmail;
use App\Enums\IncomingEmailClassification;
use App\Enums\IncomingEmailDisposition;
use App\Enums\IncomingEmailIgnoreDispositionVariant;
use App\Enums\IncomingEmailIgnoreLearningAction;
use App\Enums\IncomingEmailImportance;
use App\Enums\IncomingEmailIntakeQueue;
use App\Enums\IncomingEmailLearningDecisionType;
use App\Enums\IncomingEmailLearningRuleType;
use App\Enums\IncomingEmailLearningScope;
use App\Enums\IncomingEmailMessageStatus;
use App\Enums\IncomingEmailOperatorClassification;
use App\Enums\IraMemoryCreatedFrom;
use App\Enums\IraMemoryDecisionKind;
use App\Enums\IraMemoryPatternKind;
use App\Enums\IraMemoryStatus;
use App\Enums\IraMemoryType;
use App\Models\IncomingEmailLearningRule;
use App\Models\IncomingEmailMessage;
use App\Models\IraMemory;
use App\Models\User;
use App\Services\IncomingEmail\IncomingEmailIngestService;
use App\Services\IncomingEmail\IncomingEmailLearningRulesService;
use App\Services\IncomingEmail\IncomingEmailProcessorService;
use App\Services\IraMemory\IraMemoryService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IraMemoryPhaseM2ServiceCutoverTest extends TestCase
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

    public function test_teaching_creates_ira_memory_via_service(): void
    {
        $admin = $this->createAdmin('m2-teach@test.com');
        $assignee = $this->createAdmin('m2-assignee@test.com');

        $message = IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'fixture',
            'provider_message_id' => 'm2-teach-1',
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
            ->assertRedirect();

        $this->assertDatabaseHas('ira_memories', [
            'pattern_kind' => IraMemoryPatternKind::Sender->value,
            'pattern_value' => 'vip@acme.com',
            'decision_kind' => IraMemoryDecisionKind::Assign->value,
            'decision_value' => (string) $assignee->id,
            'status' => IraMemoryStatus::Active->value,
            'created_from' => IraMemoryCreatedFrom::LearningCenter->value,
            'memory_type' => IraMemoryType::Owner->value,
        ]);

        $message->refresh();
        $this->assertSame($assignee->id, $message->learning_owner_user_id);
        $this->assertNotNull($message->matched_learning_rule_id);
        $this->assertSame($message->matched_learning_rule_id, $message->matched_ira_memory_id);
    }

    public function test_teaching_updates_existing_memory_without_changing_created_from(): void
    {
        $admin = $this->createAdmin('m2-update@test.com');
        $first = $this->createAdmin('m2-first-owner@test.com');
        $second = $this->createAdmin('m2-second-owner@test.com');

        $message = IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'fixture',
            'provider_message_id' => 'm2-update-1',
            'from_email' => 'repeat@buyer.com',
            'subject' => 'Quote',
            'preview' => 'Need pricing',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'received_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.incoming-emails.learning.apply'), [
                'action' => 'assign',
                'message_ids' => [$message->id],
                'assignee_user_id' => $first->id,
                'scope' => IncomingEmailLearningScope::SameSender->value,
            ])
            ->assertRedirect();

        $memoryId = IraMemory::query()->where('pattern_value', 'repeat@buyer.com')->value('id');
        $this->assertNotNull($memoryId);

        $messageTwo = IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'fixture',
            'provider_message_id' => 'm2-update-2',
            'from_email' => 'repeat@buyer.com',
            'subject' => 'Quote again',
            'preview' => 'Still need pricing',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'received_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.incoming-emails.learning.apply'), [
                'action' => 'assign',
                'message_ids' => [$messageTwo->id],
                'assignee_user_id' => $second->id,
                'scope' => IncomingEmailLearningScope::SameSender->value,
            ])
            ->assertRedirect();

        $this->assertSame(1, IraMemory::query()->where('pattern_value', 'repeat@buyer.com')->count());

        $memory = IraMemory::query()->findOrFail($memoryId);
        $this->assertSame((string) $second->id, $memory->decision_value);
        $this->assertSame(IraMemoryCreatedFrom::LearningCenter, $memory->created_from);
        $this->assertSame(IraMemoryStatus::Active, $memory->status);
    }

    public function test_matcher_reads_active_ira_memories_and_skips_disabled(): void
    {
        $teacher = $this->createAdmin('m2-matcher@test.com');
        $service = app(IraMemoryService::class);

        $active = $service->upsertFromTeaching(
            patternKind: IraMemoryPatternKind::Sender,
            patternValue: 'active@vendor.com',
            decisionKind: IraMemoryDecisionKind::Ignore,
            decisionValue: IncomingEmailIgnoreLearningAction::AlwaysIgnore->value,
            actor: $teacher,
            createdFrom: IraMemoryCreatedFrom::LearningCenter,
        );

        $disabled = $service->upsertFromTeaching(
            patternKind: IraMemoryPatternKind::Sender,
            patternValue: 'disabled@vendor.com',
            decisionKind: IraMemoryDecisionKind::Ignore,
            decisionValue: IncomingEmailIgnoreLearningAction::AlwaysIgnore->value,
            actor: $teacher,
            createdFrom: IraMemoryCreatedFrom::SystemSeed,
        );
        $service->disable($disabled);

        $activeMessage = IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'fixture',
            'provider_message_id' => 'm2-match-active',
            'from_email' => 'active@vendor.com',
            'subject' => 'Ping',
            'preview' => 'Hello',
            'status' => IncomingEmailMessageStatus::Received,
            'received_at' => now(),
        ]);

        $disabledMessage = IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'fixture',
            'provider_message_id' => 'm2-match-disabled',
            'from_email' => 'disabled@vendor.com',
            'subject' => 'Ping',
            'preview' => 'Hello',
            'status' => IncomingEmailMessageStatus::Received,
            'received_at' => now(),
        ]);

        $activeMatches = app(IncomingEmailLearningRulesService::class)->matchesFor($activeMessage);
        $disabledMatches = app(IncomingEmailLearningRulesService::class)->matchesFor($disabledMessage);

        $this->assertCount(1, $activeMatches);
        $this->assertSame($active->id, $activeMatches[0]->rule->id);
        $this->assertSame([], $disabledMatches);
    }

    public function test_apply_match_populates_dual_memory_ids_and_usage(): void
    {
        $teacher = $this->createAdmin('m2-dual@test.com');

        $memory = app(IraMemoryService::class)->upsertFromTeaching(
            patternKind: IraMemoryPatternKind::Sender,
            patternValue: 'noise@vendor.com',
            decisionKind: IraMemoryDecisionKind::Ignore,
            decisionValue: IncomingEmailIgnoreLearningAction::AlwaysIgnore->value,
            actor: $teacher,
            createdFrom: IraMemoryCreatedFrom::LearningCenter,
        );

        $message = app(IncomingEmailIngestService::class)->ingest(new NormalizedInboundEmail(
            mailbox: 'support@radiumbox.com',
            provider: 'fixture',
            providerMessageId: 'm2-dual-1',
            rfcMessageId: '<m2-dual-1@radium.test>',
            threadId: 'thread-m2-dual',
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
        $this->assertSame($memory->id, $message->matched_learning_rule_id);
        $this->assertSame($memory->id, $message->matched_ira_memory_id);
        $this->assertSame(1, $memory->fresh()->times_used);
    }

    public function test_legacy_learning_rule_facade_and_view_remain_compatible(): void
    {
        $teacher = $this->createAdmin('m2-legacy@test.com');

        $rule = IncomingEmailLearningRule::query()->create([
            'rule_type' => IncomingEmailLearningRuleType::SenderDomain->value,
            'match_value' => 'compat.example',
            'decision_type' => IncomingEmailLearningDecisionType::Importance->value,
            'decision_value' => IncomingEmailImportance::Escalation->value,
            'confidence' => 91,
            'created_by' => $teacher->id,
            'enabled' => true,
        ]);

        $this->assertDatabaseHas('incoming_email_learning_rules', [
            'id' => $rule->id,
            'rule_type' => IncomingEmailLearningRuleType::SenderDomain->value,
            'match_value' => 'compat.example',
            'decision_type' => IncomingEmailLearningDecisionType::Importance->value,
            'enabled' => 1,
        ]);

        $this->assertDatabaseHas('ira_memories', [
            'id' => $rule->id,
            'pattern_kind' => IraMemoryPatternKind::SenderDomain->value,
            'pattern_value' => 'compat.example',
            'decision_kind' => IraMemoryDecisionKind::Importance->value,
            'status' => IraMemoryStatus::Active->value,
        ]);
    }

    public function test_assign_memory_sets_learning_owner_for_sales_assignment_path(): void
    {
        $teacher = $this->createAdmin('m2-sales-teacher@test.com');
        $salesOwner = $this->createAdmin('m2-sales-owner@test.com');

        app(IraMemoryService::class)->upsertFromTeaching(
            patternKind: IraMemoryPatternKind::Sender,
            patternValue: 'lead@buyer.com',
            decisionKind: IraMemoryDecisionKind::Assign,
            decisionValue: (string) $salesOwner->id,
            actor: $teacher,
            createdFrom: IraMemoryCreatedFrom::LearningCenter,
        );

        $message = app(IncomingEmailIngestService::class)->ingest(new NormalizedInboundEmail(
            mailbox: 'support@radiumbox.com',
            provider: 'fixture',
            providerMessageId: 'm2-sales-1',
            rfcMessageId: '<m2-sales-1@radium.test>',
            threadId: 'thread-m2-sales',
            fromEmail: 'lead@buyer.com',
            fromName: 'Lead',
            toEmails: ['support@radiumbox.com'],
            subject: 'Pricing please',
            preview: 'We want a quote',
            receivedAt: now(),
            attachmentCount: 0,
            headers: [],
            labels: [],
            rawPayload: ['fixture' => true],
        ), processImmediately: false);

        $this->assertNotNull($message);
        app(IncomingEmailProcessorService::class)->process($message->fresh());

        $message->refresh();
        $this->assertSame($salesOwner->id, $message->learning_owner_user_id);
        $this->assertSame($salesOwner->id, $message->suggested_assignee_user_id);
        $this->assertNotNull($message->matched_ira_memory_id);
        $this->assertSame($message->matched_learning_rule_id, $message->matched_ira_memory_id);
    }

    public function test_learning_center_regression_teach_and_queue_behaviour(): void
    {
        $admin = $this->createAdmin('m2-lc-regression@test.com');

        $message = IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'fixture',
            'provider_message_id' => 'm2-lc-1',
            'from_email' => 'news@vendor.com',
            'subject' => 'Weekly digest',
            'preview' => 'Newsletter',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'received_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.incoming-emails.learning.apply'), [
                'action' => 'classification',
                'message_ids' => [$message->id],
                'classification' => IncomingEmailOperatorClassification::Sales->value,
                'scope' => IncomingEmailLearningScope::SameSender->value,
            ])
            ->assertRedirect(route('admin.incoming-emails.index', [
                'queue' => IncomingEmailIntakeQueue::NeedsHuman->value,
            ]));

        $message->refresh();
        $this->assertSame(IncomingEmailClassification::PossibleSalesLead, $message->classification);
        $this->assertSame(IncomingEmailMessageStatus::NeedsReview, $message->status);
        $this->assertNull($message->disposition);

        $this->assertDatabaseHas('ira_memories', [
            'pattern_value' => 'news@vendor.com',
            'decision_kind' => IraMemoryDecisionKind::Classification->value,
            'created_from' => IraMemoryCreatedFrom::LearningCenter->value,
        ]);
    }

    public function test_disposition_regression_sets_created_from_disposition(): void
    {
        $admin = $this->createAdmin('m2-disp@test.com');

        $message = IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'fixture',
            'provider_message_id' => 'm2-disp-1',
            'from_email' => 'spammy@example.com',
            'subject' => 'Buy now',
            'preview' => 'Limited offer',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'received_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.incoming-emails.disposition.apply'), [
                'disposition' => IncomingEmailDisposition::Ignore->value,
                'message_ids' => [$message->id],
                'ignore_variant' => IncomingEmailIgnoreDispositionVariant::AlwaysSender->value,
            ])
            ->assertRedirect();

        $message->refresh();
        $this->assertSame(IncomingEmailMessageStatus::Ignored, $message->status);
        $this->assertSame(IncomingEmailDisposition::Ignore, $message->disposition);
        $this->assertNotNull($message->matched_ira_memory_id);
        $this->assertSame($message->matched_learning_rule_id, $message->matched_ira_memory_id);

        $this->assertDatabaseHas('ira_memories', [
            'pattern_kind' => IraMemoryPatternKind::Sender->value,
            'pattern_value' => 'spammy@example.com',
            'decision_kind' => IraMemoryDecisionKind::Ignore->value,
            'created_from' => IraMemoryCreatedFrom::Disposition->value,
            'status' => IraMemoryStatus::Active->value,
        ]);
    }

    public function test_merge_and_activate_disable_round_trip(): void
    {
        $actor = $this->createAdmin('m2-merge@test.com');
        $service = app(IraMemoryService::class);

        $survivor = $service->upsertFromTeaching(
            patternKind: IraMemoryPatternKind::Sender,
            patternValue: 'keep@example.com',
            decisionKind: IraMemoryDecisionKind::Classification,
            decisionValue: IncomingEmailOperatorClassification::Sales->value,
            actor: $actor,
            createdFrom: IraMemoryCreatedFrom::ManualEdit,
        );

        $duplicate = $service->upsertFromTeaching(
            patternKind: IraMemoryPatternKind::Sender,
            patternValue: 'drop@example.com',
            decisionKind: IraMemoryDecisionKind::Classification,
            decisionValue: IncomingEmailOperatorClassification::Sales->value,
            actor: $actor,
            createdFrom: IraMemoryCreatedFrom::Import,
        );

        $service->recordUsage($duplicate);
        $merged = $service->merge($duplicate, $survivor, $actor);

        $this->assertSame($survivor->id, $merged->id);
        $this->assertSame(IraMemoryStatus::Merged, $duplicate->fresh()->status);
        $this->assertSame($survivor->id, $duplicate->fresh()->merged_into_memory_id);
        $this->assertGreaterThanOrEqual(1, $survivor->fresh()->times_used);

        $service->disable($survivor);
        $this->assertSame(IraMemoryStatus::Disabled, $survivor->fresh()->status);

        $service->activate($survivor);
        $this->assertSame(IraMemoryStatus::Active, $survivor->fresh()->status);
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

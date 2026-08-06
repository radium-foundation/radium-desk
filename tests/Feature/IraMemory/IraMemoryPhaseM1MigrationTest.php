<?php

namespace Tests\Feature\IraMemory;

use App\Enums\IncomingEmailLearningDecisionType;
use App\Enums\IncomingEmailLearningRuleType;
use App\Enums\IraMemoryCreatedFrom;
use App\Enums\IraMemoryDecisionKind;
use App\Enums\IraMemoryPatternKind;
use App\Enums\IraMemorySource;
use App\Enums\IraMemoryStatus;
use App\Enums\IraMemoryType;
use App\Models\IncomingEmailLearningRule;
use App\Models\IncomingEmailMessage;
use App\Models\IraMemory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IraMemoryPhaseM1MigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ira_memories_schema_exists_with_documented_columns(): void
    {
        $this->assertTrue(Schema::hasTable('ira_memories'));
        $this->assertTrue(Schema::hasTable('ira_memory_relations'));
        $this->assertTrue($this->isLearningRulesView());

        foreach ([
            'uuid',
            'memory_type',
            'source',
            'pattern_kind',
            'pattern_value',
            'decision_kind',
            'decision_value',
            'reason',
            'confidence',
            'status',
            'times_used',
            'last_used_at',
            'created_by_user_id',
            'created_from',
            'created_from_type',
            'created_from_id',
            'merged_into_memory_id',
            'expires_at',
            'suggestion_origin',
            'approval_status',
            'score',
            'metadata',
            'uniqueness_guard',
            'deleted_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('ira_memories', $column), "Missing column {$column}");
        }

        $this->assertTrue(Schema::hasColumn('incoming_email_messages', 'matched_learning_rule_id'));
        $this->assertTrue(Schema::hasColumn('incoming_email_messages', 'matched_ira_memory_id'));
    }

    public function test_compatibility_view_exposes_legacy_learning_rule_columns(): void
    {
        $teacher = User::factory()->create();

        $rule = IncomingEmailLearningRule::query()->create([
            'rule_type' => IncomingEmailLearningRuleType::Sender->value,
            'match_value' => 'legacy@example.com',
            'decision_type' => IncomingEmailLearningDecisionType::Assign->value,
            'decision_value' => (string) $teacher->id,
            'confidence' => 88,
            'created_by' => $teacher->id,
            'enabled' => true,
            'times_used' => 3,
        ]);

        $this->assertDatabaseHas('incoming_email_learning_rules', [
            'id' => $rule->id,
            'rule_type' => IncomingEmailLearningRuleType::Sender->value,
            'match_value' => 'legacy@example.com',
            'decision_type' => IncomingEmailLearningDecisionType::Assign->value,
            'decision_value' => (string) $teacher->id,
            'confidence' => 88,
            'created_by' => $teacher->id,
            'enabled' => 1,
            'times_used' => 3,
        ]);

        $memory = IraMemory::query()->findOrFail($rule->id);
        $this->assertSame(IraMemoryType::Owner, $memory->memory_type);
        $this->assertSame(IraMemorySource::Email, $memory->source);
        $this->assertSame(IraMemoryStatus::Active, $memory->status);
        $this->assertSame(IraMemoryPatternKind::Sender, $memory->pattern_kind);
        $this->assertSame(IraMemoryDecisionKind::Assign, $memory->decision_kind);
        $this->assertNotEmpty($memory->uuid);
    }

    public function test_backfill_preserves_usage_confidence_creator_and_disabled_state(): void
    {
        $teacher = User::factory()->create();

        // Simulate a pre-M1 row shape by writing through the facade, then asserting Memory fields.
        $disabled = IncomingEmailLearningRule::query()->create([
            'rule_type' => IncomingEmailLearningRuleType::SenderDomain->value,
            'match_value' => 'noise.test',
            'decision_type' => IncomingEmailLearningDecisionType::Ignore->value,
            'decision_value' => 'always_ignore',
            'confidence' => 70,
            'created_by' => $teacher->id,
            'enabled' => false,
            'times_used' => 12,
            'last_used_at' => now()->subDay(),
        ]);

        $memory = IraMemory::withTrashed()->findOrFail($disabled->id);

        $this->assertSame(IraMemoryStatus::Disabled, $memory->status);
        $this->assertSame(IraMemoryType::Ignore, $memory->memory_type);
        $this->assertSame(70, $memory->confidence);
        $this->assertSame(12, $memory->times_used);
        $this->assertSame($teacher->id, $memory->created_by_user_id);
        $this->assertNotNull($memory->last_used_at);
        $this->assertFalse((bool) $disabled->enabled);
    }

    public function test_expand_in_place_backfills_pre_m1_learning_rule_rows(): void
    {
        $teacher = User::factory()->create();
        $migration = require database_path('migrations/2026_08_06_140000_expand_learning_rules_into_ira_memories.php');

        $migration->down();

        $this->assertTrue(Schema::hasTable('incoming_email_learning_rules'));
        $this->assertFalse(Schema::hasTable('ira_memories'));

        $lastUsedAt = now()->subHours(6)->toDateTimeString();

        $id = DB::table('incoming_email_learning_rules')->insertGetId([
            'rule_type' => IncomingEmailLearningRuleType::Sender->value,
            'match_value' => 'pre-m1@example.com',
            'decision_type' => IncomingEmailLearningDecisionType::Classification->value,
            'decision_value' => 'support',
            'confidence' => 77,
            'created_by' => $teacher->id,
            'times_used' => 9,
            'last_used_at' => $lastUsedAt,
            'enabled' => 1,
            'created_at' => now()->subDay()->toDateTimeString(),
            'updated_at' => now()->subDay()->toDateTimeString(),
        ]);

        $legacyCount = DB::table('incoming_email_learning_rules')->count();

        $migration->up();

        $this->assertTrue(Schema::hasTable('ira_memories'));
        $this->assertSame($legacyCount, DB::table('ira_memories')->count());
        $this->assertDatabaseHas('ira_memories', [
            'id' => $id,
            'pattern_kind' => IncomingEmailLearningRuleType::Sender->value,
            'pattern_value' => 'pre-m1@example.com',
            'decision_kind' => IncomingEmailLearningDecisionType::Classification->value,
            'decision_value' => 'support',
            'memory_type' => IraMemoryType::Classification->value,
            'source' => IraMemorySource::Email->value,
            'status' => IraMemoryStatus::Active->value,
            'created_from' => IraMemoryCreatedFrom::Migration->value,
            'confidence' => 77,
            'times_used' => 9,
            'created_by_user_id' => $teacher->id,
        ]);

        $memory = DB::table('ira_memories')->where('id', $id)->first();
        $this->assertNotEmpty($memory->uuid);
        $this->assertNotNull($memory->last_used_at);
    }

    public function test_importance_maps_to_routing_pattern_memory_type(): void
    {
        $teacher = User::factory()->create();

        $rule = IncomingEmailLearningRule::query()->create([
            'rule_type' => IncomingEmailLearningRuleType::Mailbox->value,
            'match_value' => 'support@radiumbox.com',
            'decision_type' => IncomingEmailLearningDecisionType::Importance->value,
            'decision_value' => 'escalation',
            'confidence' => 80,
            'created_by' => $teacher->id,
            'enabled' => true,
        ]);

        $memory = IraMemory::query()->findOrFail($rule->id);
        $this->assertSame(IraMemoryType::RoutingPattern, $memory->memory_type);
        $this->assertSame(IraMemoryDecisionKind::Importance, $memory->decision_kind);
    }

    public function test_matched_ira_memory_id_coexists_with_matched_learning_rule_id(): void
    {
        $teacher = User::factory()->create();

        $rule = IncomingEmailLearningRule::query()->create([
            'rule_type' => IncomingEmailLearningRuleType::Sender->value,
            'match_value' => 'pair@example.com',
            'decision_type' => IncomingEmailLearningDecisionType::Classification->value,
            'decision_value' => 'support',
            'confidence' => 90,
            'created_by' => $teacher->id,
            'enabled' => true,
        ]);

        $message = IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'fixture',
            'provider_message_id' => 'm1-pair-1',
            'from_email' => 'pair@example.com',
            'subject' => 'Pair test',
            'preview' => 'Preview',
            'status' => 'needs_review',
            'received_at' => now(),
            'matched_learning_rule_id' => $rule->id,
            'matched_ira_memory_id' => $rule->id,
        ]);

        $message->refresh();
        $this->assertSame($rule->id, $message->matched_learning_rule_id);
        $this->assertSame($rule->id, $message->matched_ira_memory_id);
        $this->assertTrue($message->matchedLearningRule()->exists());
        $this->assertTrue($message->matchedIraMemory()->exists());
    }

    public function test_migration_is_idempotent(): void
    {
        $migration = require database_path('migrations/2026_08_06_140000_expand_learning_rules_into_ira_memories.php');

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasTable('ira_memories'));
        $this->assertTrue(Schema::hasTable('ira_memory_relations'));
        $this->assertTrue(Schema::hasColumn('incoming_email_messages', 'matched_ira_memory_id'));
    }

    public function test_rollback_restores_learning_rules_table_shape(): void
    {
        $teacher = User::factory()->create();

        IncomingEmailLearningRule::query()->create([
            'rule_type' => IncomingEmailLearningRuleType::Sender->value,
            'match_value' => 'rollback@example.com',
            'decision_type' => IncomingEmailLearningDecisionType::Ignore->value,
            'decision_value' => 'always_ignore',
            'confidence' => 91,
            'created_by' => $teacher->id,
            'enabled' => true,
            'times_used' => 4,
        ]);

        $beforeCount = DB::table('ira_memories')->count();
        $this->assertGreaterThan(0, $beforeCount);

        $migration = require database_path('migrations/2026_08_06_140000_expand_learning_rules_into_ira_memories.php');
        $migration->down();

        $this->assertTrue(Schema::hasTable('incoming_email_learning_rules'));
        $this->assertFalse(Schema::hasTable('ira_memories'));
        $this->assertFalse(Schema::hasTable('ira_memory_relations'));
        $this->assertFalse(Schema::hasColumn('incoming_email_messages', 'matched_ira_memory_id'));

        $this->assertSame($beforeCount, DB::table('incoming_email_learning_rules')->count());
        $this->assertDatabaseHas('incoming_email_learning_rules', [
            'match_value' => 'rollback@example.com',
            'decision_type' => 'ignore',
            'confidence' => 91,
            'times_used' => 4,
            'enabled' => 1,
        ]);

        // Re-apply for subsequent tests in this class after RefreshDatabase? RefreshDatabase
        // wraps each test, so no need to re-up here.
    }

    public function test_facade_scope_enabled_filters_active_status_only(): void
    {
        $teacher = User::factory()->create();

        IncomingEmailLearningRule::query()->create([
            'rule_type' => IncomingEmailLearningRuleType::Sender->value,
            'match_value' => 'active@example.com',
            'decision_type' => IncomingEmailLearningDecisionType::Ignore->value,
            'decision_value' => 'always_ignore',
            'confidence' => 80,
            'created_by' => $teacher->id,
            'enabled' => true,
        ]);

        IncomingEmailLearningRule::query()->create([
            'rule_type' => IncomingEmailLearningRuleType::Sender->value,
            'match_value' => 'disabled@example.com',
            'decision_type' => IncomingEmailLearningDecisionType::Ignore->value,
            'decision_value' => 'always_ignore',
            'confidence' => 80,
            'created_by' => $teacher->id,
            'enabled' => false,
        ]);

        $this->assertSame(1, IncomingEmailLearningRule::query()->enabled()->count());
        $this->assertSame(2, IncomingEmailLearningRule::query()->count());
    }

    public function test_created_from_defaults_for_new_facade_rows(): void
    {
        $teacher = User::factory()->create();

        $rule = IncomingEmailLearningRule::query()->create([
            'rule_type' => IncomingEmailLearningRuleType::Keyword->value,
            'match_value' => 'warranty',
            'decision_type' => IncomingEmailLearningDecisionType::Classification->value,
            'decision_value' => 'support',
            'confidence' => 80,
            'created_by' => $teacher->id,
            'enabled' => true,
        ]);

        $this->assertSame(IraMemoryCreatedFrom::LearningCenter, $rule->fresh()->created_from);
        $this->assertSame(IraMemorySource::Email, $rule->fresh()->source);
    }

    private function isLearningRulesView(): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $row = DB::selectOne(
                "SELECT type FROM sqlite_master WHERE name = 'incoming_email_learning_rules'"
            );

            return ($row->type ?? null) === 'view';
        }

        if ($driver === 'mysql') {
            $row = DB::selectOne(
                'SELECT TABLE_TYPE AS table_type
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?',
                ['incoming_email_learning_rules']
            );

            return strtoupper((string) ($row->table_type ?? '')) === 'VIEW';
        }

        return false;
    }
}

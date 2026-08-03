<?php

namespace Tests\Unit\IncomingEmail;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmailPhase1ReplyClassificationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_is_idempotent_when_run_twice(): void
    {
        $migration = $this->migration();

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('incoming_email_messages', 'classification'));
        $this->assertTrue(Schema::hasTable('outgoing_email_messages'));
        $this->assertTrue(Schema::hasTable('incoming_email_ignore_stats'));
        $this->assertTrue(Schema::hasColumn('outgoing_email_messages', 'in_reply_to_incoming_email_message_id'));
        $this->assertTrue(Schema::hasColumn('outgoing_email_messages', 'status'));
    }

    public function test_migration_completes_after_partial_classification_only_state(): void
    {
        // Simulate production: classification already exists from a failed prior run.
        Schema::dropIfExists('incoming_email_ignore_stats');
        Schema::dropIfExists('outgoing_email_messages');

        if (! Schema::hasColumn('incoming_email_messages', 'classification')) {
            Schema::table('incoming_email_messages', function ($table): void {
                $table->string('classification', 64)->nullable();
            });
        }

        $this->assertTrue(Schema::hasColumn('incoming_email_messages', 'classification'));
        $this->assertFalse(Schema::hasTable('outgoing_email_messages'));
        $this->assertFalse(Schema::hasTable('incoming_email_ignore_stats'));

        $migration = $this->migration();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('incoming_email_messages', 'classification'));
        $this->assertTrue(Schema::hasTable('outgoing_email_messages'));
        $this->assertTrue(Schema::hasTable('incoming_email_ignore_stats'));

        // Second pass must remain a no-op (no duplicate column/index errors).
        $migration->up();
        $this->assertTrue(Schema::hasTable('outgoing_email_messages'));
    }

    public function test_down_is_safe_when_objects_already_missing(): void
    {
        $migration = $this->migration();
        $migration->up();

        Schema::dropIfExists('incoming_email_ignore_stats');
        Schema::dropIfExists('outgoing_email_messages');

        $migration->down();

        $this->assertFalse(Schema::hasColumn('incoming_email_messages', 'classification'));
        $this->assertFalse(Schema::hasTable('outgoing_email_messages'));
        $this->assertFalse(Schema::hasTable('incoming_email_ignore_stats'));

        // Running down again must not throw.
        $migration->down();
        $this->assertFalse(Schema::hasColumn('incoming_email_messages', 'classification'));
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_08_03_190000_add_email_phase1_reply_and_classification.php');
    }
}

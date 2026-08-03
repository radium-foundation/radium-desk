<?php

namespace Tests\Unit\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DropCommunicationTemplateStoreTablesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_drop_migration_is_idempotent_and_scoped(): void
    {
        // RefreshDatabase already applied all migrations, including the drop.
        $this->assertFalse(Schema::hasTable('communication_templates'));
        $this->assertFalse(Schema::hasTable('communication_template_versions'));
        $this->assertFalse(Schema::hasTable('communication_template_usages'));

        $this->assertSame(
            0,
            DB::table('permissions')
                ->whereIn('name', [
                    'communication-templates.view',
                    'communication-templates.manage',
                ])
                ->count(),
        );

        // Re-running the drop statements must remain safe.
        Schema::dropIfExists('communication_template_usages');
        Schema::dropIfExists('communication_template_versions');
        Schema::dropIfExists('communication_templates');

        $this->assertFalse(Schema::hasTable('communication_templates'));
        $this->assertTrue(Schema::hasTable('permissions'));
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('incoming_email_messages'));
    }
}

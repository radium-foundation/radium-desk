<?php

namespace Tests\Feature\RadiumBox;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RadiumBoxStatutoryReceiptPublicationMigrationTest extends TestCase
{
    use RefreshDatabase;
    private const MIGRATION = '2026_10_31_140000_create_radiumbox_statutory_receipt_publications_table.php';

    /** @var list<string> Production-tested MariaDB identifiers (P-31-90). */
    private const MARIADB_IDENTIFIERS = [
        'rbox_stat_rcpt_pub_inv_uq',
        'rbox_stat_rcpt_pub_idem_uq',
        'rbox_stat_rcpt_pub_inv_fk',
    ];

    public function test_migration_source_uses_explicit_mariadb_safe_identifiers(): void
    {
        $path = database_path('migrations/'.self::MIGRATION);
        $this->assertFileExists($path);

        $source = file_get_contents($path);
        $this->assertIsString($source);

        foreach (self::MARIADB_IDENTIFIERS as $identifier) {
            $this->assertStringContainsString($identifier, $source);
            $this->assertLessThanOrEqual(
                64,
                strlen($identifier),
                "Identifier {$identifier} must fit MariaDB 64-character limit.",
            );
        }

        $this->assertStringNotContainsString(
            'radiumbox_statutory_receipt_publications_statutory_invoice_id_foreign',
            $source,
            'Default Laravel foreign key name exceeds MariaDB limit on this table.',
        );

        $this->assertStringNotContainsString(
            'radiumbox_statutory_receipt_publications_statutory_invoice_id_unique',
            $source,
            'Default Laravel unique index name exceeds MariaDB limit on this table.',
        );
    }

    public function test_migration_is_present_after_refresh_database(): void
    {
        $this->assertTrue(
            Schema::hasTable('radiumbox_statutory_receipt_publications'),
            'RefreshDatabase should apply the statutory receipt publications migration.',
        );
    }
}

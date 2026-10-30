<?php

namespace Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

class InventoryAvailabilitySyncLogsMigrationIndexTest extends TestCase
{
    private const COMPOSITE_INDEX = 'inv_avail_sync_logs_product_created_idx';

    private const LEGACY_AUTO_INDEX = 'inventory_availability_sync_logs_inventory_product_id_created_at_index';

    public function test_composite_index_name_fits_mariadb_identifier_limit(): void
    {
        $this->assertSame(39, strlen(self::COMPOSITE_INDEX));
        $this->assertLessThanOrEqual(64, strlen(self::COMPOSITE_INDEX));
    }

    public function test_legacy_auto_generated_index_name_exceeded_mariadb_limit(): void
    {
        $this->assertSame(70, strlen(self::LEGACY_AUTO_INDEX));
        $this->assertGreaterThan(64, strlen(self::LEGACY_AUTO_INDEX));
    }
}

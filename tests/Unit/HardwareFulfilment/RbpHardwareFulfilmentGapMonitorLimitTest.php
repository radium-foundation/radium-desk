<?php

namespace Tests\Unit\HardwareFulfilment;

use Tests\TestCase;

class RbpHardwareFulfilmentGapMonitorLimitTest extends TestCase
{
    public function test_find_gaps_sql_prefilters_rbp_and_limit_applies_after_eligibility(): void
    {
        $source = file_get_contents(app_path('Services/HardwareFulfilment/RbpHardwareFulfilmentGapMonitor.php'));
        $this->assertNotFalse($source);
        $this->assertStringContainsString('UPPER(source_id) LIKE ?', $source);
        $this->assertStringContainsString('count($gaps) >= $limit', $source);
        $this->assertStringNotContainsString('$query->limit($limit)', $source);
    }
}

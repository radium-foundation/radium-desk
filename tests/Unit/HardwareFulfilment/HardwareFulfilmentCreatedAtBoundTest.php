<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HardwareFulfilmentCreatedAtBoundTest extends TestCase
{
    public function test_sql_bound_is_ist_wall_clock_for_ist_or_utc_instants(): void
    {
        $ist = Carbon::parse('2026-09-08 17:17:00', HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);
        $utc = Carbon::parse('2026-09-08 11:47:00', 'UTC');

        $this->assertSame('2026-09-08 17:17:00', HardwareFulfilmentEligibility::createdAtSqlBound($ist));
        $this->assertSame('2026-09-08 17:17:00', HardwareFulfilmentEligibility::createdAtSqlBound($utc));
        $this->assertSame(
            '2026-09-05 00:00:00',
            HardwareFulfilmentEligibility::createdAtSqlBound(HardwareFulfilmentEligibility::cutoffInstant()),
        );
        $this->assertSame(
            '2026-09-04 18:30:00',
            HardwareFulfilmentEligibility::cutoffInstant()->copy()->timezone('UTC')->format('Y-m-d H:i:s'),
        );
    }
}

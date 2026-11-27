<?php

namespace Tests\Unit\Support;

use App\Support\RadiumSignHistoricalRdsReservation;
use Tests\TestCase;

class RadiumSignHistoricalRdsReservationTest extends TestCase
{
    public function test_manifest_digest_is_immutable(): void
    {
        $this->assertSame(
            '247d054e2e370f35c760fa0b8c93bdb1d5ac7bb6fd78843ab1f2528daafbaaa6',
            RadiumSignHistoricalRdsReservation::manifestSha256(),
        );
    }

    public function test_reserved_historical_ids_include_rds363_and_gaps(): void
    {
        $this->assertTrue(RadiumSignHistoricalRdsReservation::isReserved('RDS363'));
        $this->assertTrue(RadiumSignHistoricalRdsReservation::isReserved('RDS366'));
        $this->assertFalse(RadiumSignHistoricalRdsReservation::isReserved('RDS365'));
        $this->assertTrue(RadiumSignHistoricalRdsReservation::isReserved('RDS113966'));
    }

    public function test_reserved_count_matches_manifest(): void
    {
        $this->assertCount(391, RadiumSignHistoricalRdsReservation::reservedIds());
    }
}

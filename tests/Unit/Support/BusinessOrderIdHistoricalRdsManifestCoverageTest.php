<?php

namespace Tests\Unit\Support;

use App\Support\BusinessOrderId;
use App\Support\RadiumSignHistoricalRdsReservation;
use Tests\TestCase;

class BusinessOrderIdHistoricalRdsManifestCoverageTest extends TestCase
{
    public function test_every_manifest_id_resolves_to_radiumsign(): void
    {
        foreach (RadiumSignHistoricalRdsReservation::reservedIds() as $reservedId) {
            $this->assertSame('radiumsign.com', BusinessOrderId::owner($reservedId), $reservedId);
        }
    }
}
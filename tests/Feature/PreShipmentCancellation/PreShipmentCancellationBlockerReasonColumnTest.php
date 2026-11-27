<?php

namespace Tests\Feature\PreShipmentCancellation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PreShipmentCancellationBlockerReasonColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_blocker_reason_column_is_text_after_widen_migration(): void
    {
        $type = Schema::getColumnType('pre_shipment_cancellation_requests', 'blocker_reason');

        $this->assertContains(
            $type,
            ['text', 'mediumtext', 'longtext'],
            'Operator metadata requires text storage; varchar(64) caused production QueryException 22001.',
        );
    }
}

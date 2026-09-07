<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Enums\HardwareFulfilmentState;
use Tests\TestCase;

class HardwareFulfilmentStateMachineTest extends TestCase
{
    public function test_serials_allocated_precedes_invoice_issued_on_the_happy_path(): void
    {
        $path = array_map(
            static fn (HardwareFulfilmentState $state): string => $state->value,
            HardwareFulfilmentState::happyPath(),
        );

        $this->assertLessThan(
            array_search('invoice_issued', $path, true),
            array_search('serials_allocated', $path, true),
        );
        $this->assertTrue(
            HardwareFulfilmentState::SerialsAllocated->rank()
            < HardwareFulfilmentState::InvoiceIssued->rank(),
        );
    }

    public function test_ready_cannot_skip_to_invoice_issued(): void
    {
        $this->assertFalse(
            HardwareFulfilmentState::ReadyForFulfilment->canTransitionTo(
                HardwareFulfilmentState::InvoiceIssued,
            ),
        );
        $this->assertTrue(
            HardwareFulfilmentState::ReadyForFulfilment->canTransitionTo(
                HardwareFulfilmentState::SerialsAllocated,
            ),
        );
    }

    public function test_out_of_order_and_skip_edges_are_closed(): void
    {
        $this->assertFalse(
            HardwareFulfilmentState::Ingested->canTransitionTo(HardwareFulfilmentState::Paid),
        );
        $this->assertFalse(
            HardwareFulfilmentState::SerialsAllocated->canTransitionTo(HardwareFulfilmentState::Ingested),
        );
        $this->assertFalse(
            HardwareFulfilmentState::SerialsAllocated->canTransitionTo(HardwareFulfilmentState::ShipmentCreated),
        );
        $this->assertTrue(
            HardwareFulfilmentState::SerialsAllocated->canTransitionTo(HardwareFulfilmentState::InvoiceIssued),
        );
        $this->assertTrue(
            HardwareFulfilmentState::InvoiceIssued->canTransitionTo(HardwareFulfilmentState::ShipmentCreated),
        );
    }
}

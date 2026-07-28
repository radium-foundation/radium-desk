<?php

namespace Tests\Unit\Bonvoice;

use App\Enums\OutboundClickToCallLifecycleStatus;
use App\Support\Bonvoice\OutboundClickToCallLifecycleNormalizer;
use Tests\TestCase;

class OutboundClickToCallLifecycleNormalizerTest extends TestCase
{
    public function test_normalizes_ringing_on_customer_leg(): void
    {
        $status = OutboundClickToCallLifecycleNormalizer::normalize(
            status: 'Ringing',
            leg: 'B',
        );

        $this->assertSame(OutboundClickToCallLifecycleStatus::Ringing, $status);
    }

    public function test_normalizes_ringing_on_agent_leg_as_calling(): void
    {
        $status = OutboundClickToCallLifecycleNormalizer::normalize(
            status: 'RINGING',
            leg: 'A',
        );

        $this->assertSame(OutboundClickToCallLifecycleStatus::Calling, $status);
    }

    public function test_ringing_with_on_call_agent_status_stays_ringing(): void
    {
        $status = OutboundClickToCallLifecycleNormalizer::normalize(
            status: 'RINGING',
            agentStatus: 'ON CALL',
            leg: 'B',
        );

        $this->assertSame(OutboundClickToCallLifecycleStatus::Ringing, $status);
    }

    public function test_ringing_with_on_call_on_agent_leg_stays_calling(): void
    {
        $status = OutboundClickToCallLifecycleNormalizer::normalize(
            status: 'Ringing',
            agentStatus: 'On Call',
            leg: 'A',
        );

        $this->assertSame(OutboundClickToCallLifecycleStatus::Calling, $status);
    }

    public function test_answered_with_on_call_maps_to_connected_lifecycle(): void
    {
        $status = OutboundClickToCallLifecycleNormalizer::normalize(
            status: 'ANSWERED',
            agentStatus: 'ON CALL',
            leg: 'B',
        );

        $this->assertSame(OutboundClickToCallLifecycleStatus::Answered, $status);
    }

    public function test_on_call_alone_does_not_infer_connected(): void
    {
        $status = OutboundClickToCallLifecycleNormalizer::normalize(
            status: 'DIALING',
            agentStatus: 'ON CALL',
            leg: 'A',
        );

        $this->assertSame(OutboundClickToCallLifecycleStatus::Calling, $status);
    }

    public function test_normalizes_terminal_provider_statuses(): void
    {
        $this->assertSame(
            OutboundClickToCallLifecycleStatus::Busy,
            OutboundClickToCallLifecycleNormalizer::normalize(status: 'Busy'),
        );
        $this->assertSame(
            OutboundClickToCallLifecycleStatus::NoAnswer,
            OutboundClickToCallLifecycleNormalizer::normalize(status: 'NOANSWER'),
        );
        $this->assertSame(
            OutboundClickToCallLifecycleStatus::Failed,
            OutboundClickToCallLifecycleNormalizer::normalize(status: 'FAILED'),
        );
        $this->assertSame(
            OutboundClickToCallLifecycleStatus::Cancelled,
            OutboundClickToCallLifecycleNormalizer::normalize(status: 'Cancelled'),
        );
        $this->assertSame(
            OutboundClickToCallLifecycleStatus::Completed,
            OutboundClickToCallLifecycleNormalizer::normalize(status: 'Completed'),
        );
    }
}

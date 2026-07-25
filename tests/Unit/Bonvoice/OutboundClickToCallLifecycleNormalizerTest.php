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

    public function test_normalizes_answered_as_connected_lifecycle(): void
    {
        $status = OutboundClickToCallLifecycleNormalizer::normalize(
            status: 'Answered',
            agentStatus: 'On Call',
            leg: 'B',
        );

        $this->assertSame(OutboundClickToCallLifecycleStatus::Answered, $status);
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

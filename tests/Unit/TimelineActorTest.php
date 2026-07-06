<?php

namespace Tests\Unit;

use App\Data\TimelineActor;
use App\Enums\TimelineActorKind;
use App\Enums\TimelineEventType;
use Tests\TestCase;

class TimelineActorTest extends TestCase
{
    public function test_role_labels(): void
    {
        $this->assertSame('Customer', (new TimelineActor('Customer'))->roleLabel());
        $this->assertSame('Automation', (new TimelineActor('Ira', 'IRA AI', true))->roleLabel());
        $this->assertSame('System', (new TimelineActor('System', kind: TimelineActorKind::System))->roleLabel());
        $this->assertSame('Team Member', (new TimelineActor('Priya'))->roleLabel());
    }

    public function test_filter_categories(): void
    {
        $this->assertSame('notifications', TimelineEventType::WhatsApp->filterCategory());
        $this->assertSame('payments', TimelineEventType::Payment->filterCategory());
        $this->assertSame('support', TimelineEventType::ServiceCaseCreated->filterCategory());
        $this->assertSame('support', TimelineEventType::InternalNote->filterCategory());
        $this->assertSame('support', TimelineEventType::Assignment->filterCategory());
        $this->assertSame('support', TimelineEventType::AuditEvent->filterCategory());
        $this->assertSame('synchronization', TimelineEventType::Synchronization->filterCategory());
        $this->assertSame('appointments', TimelineEventType::Appointment->filterCategory());
    }
}

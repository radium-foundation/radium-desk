<?php

namespace Tests\Unit;

use App\Data\TimelineActor;
use App\Enums\TimelineEventType;
use Tests\TestCase;

class TimelineActorTest extends TestCase
{
    public function test_role_labels(): void
    {
        $this->assertSame('Customer', (new TimelineActor('Customer'))->roleLabel());
        $this->assertSame('System', (new TimelineActor('Ira', 'IRA AI', true))->roleLabel());
        $this->assertSame('Agent', (new TimelineActor('Priya'))->roleLabel());
    }

    public function test_filter_categories(): void
    {
        $this->assertSame('whatsapp', TimelineEventType::WhatsApp->filterCategory());
        $this->assertSame('payments', TimelineEventType::Payment->filterCategory());
        $this->assertSame('repairs', TimelineEventType::ServiceCaseCreated->filterCategory());
        $this->assertSame('notes', TimelineEventType::InternalNote->filterCategory());
        $this->assertSame('assignments', TimelineEventType::Assignment->filterCategory());
        $this->assertSame('audit', TimelineEventType::AuditEvent->filterCategory());
    }
}

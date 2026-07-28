<?php

namespace Tests\Unit\Dashboard;

use App\Data\RecentActivityItem;
use App\Models\AuditLog;
use App\Support\Dashboard\TeamActivityLabelFormatter;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TeamActivityLabelFormatterTest extends TestCase
{
    public function test_formats_assigned_with_order_reference(): void
    {
        $formatter = new TeamActivityLabelFormatter;
        $audit = new AuditLog(['event' => 'service_case.assigned']);
        $item = new RecentActivityItem(
            stream: 'team',
            title: 'Assigned',
            typePill: 'Assignment',
            indicatorVariant: 'muted',
            incidentReference: 'SC-1',
            orderReference: 'RD3462318',
            customerName: 'Customer',
            entityIncidentId: 1,
            entityReference: 'SC-1',
            occurredAt: Carbon::now(),
            compactTime: '1m',
            exactTime: 'now',
            actorName: 'Agent',
            isAutomation: false,
        );

        $this->assertSame('Assigned RD3462318', $formatter->labelFor($audit, $item));
    }

    public function test_formats_supervisor_friendly_labels_without_reference(): void
    {
        $formatter = new TeamActivityLabelFormatter;

        $this->assertSame(
            'Status Changed',
            $formatter->labelFor(new AuditLog(['event' => 'service_case.status_changed'])),
        );
        $this->assertSame(
            'Remark Added',
            $formatter->labelFor(new AuditLog(['event' => 'created'])),
        );
        $this->assertSame(
            'WhatsApp Sent',
            $formatter->labelFor(new AuditLog(['event' => 'whatsapp.template_sent'])),
        );
        $this->assertSame(
            'IVR Call',
            $formatter->labelFor(new AuditLog(['event' => 'missed_call_recovery.created'])),
        );
        $this->assertSame(
            'Leave Approved',
            $formatter->labelFor(new AuditLog(['event' => 'workforce.leave.approved'])),
        );
        $this->assertSame(
            'IRA Validation Passed',
            $formatter->labelFor(new AuditLog(['event' => 'service_case.automation.validation_passed'])),
        );
    }

    public function test_compact_display_label_shortens_configured_and_long_labels(): void
    {
        $formatter = new TeamActivityLabelFormatter;

        $this->assertSame('Available', $formatter->compactDisplayLabel('Availability Changed'));
        $this->assertSame('Status', $formatter->compactDisplayLabel('Status Changed'));
        $this->assertSame('Guide Sent', $formatter->compactDisplayLabel('Driver Guide Sent'));
        $this->assertSame('WhatsApp', $formatter->compactDisplayLabel('WhatsApp Sent'));
        $this->assertSame('Assigned RD3462318', $formatter->compactDisplayLabel('Assigned RD3462318'));
        $this->assertSame('IRA Validation', $formatter->compactDisplayLabel('IRA Validation Passed'));
    }
}

<?php

namespace Tests\Unit\Support\Operations;

use App\Models\Incident;
use App\Models\User;
use App\Support\Operations\AppointmentReminderMessageContext;
use Tests\TestCase;

class AppointmentReminderMessageContextTest extends TestCase
{
    public function test_appointment_type_prefers_incident_category(): void
    {
        $incident = new Incident([
            'category' => 'Driver Installation',
            'title' => 'Fallback title',
        ]);

        $this->assertSame('Driver Installation', AppointmentReminderMessageContext::appointmentTypeLabel($incident));
    }

    public function test_appointment_type_falls_back_to_incident_title(): void
    {
        $incident = new Incident([
            'category' => '',
            'title' => 'Remote troubleshooting',
        ]);

        $this->assertSame('Remote troubleshooting', AppointmentReminderMessageContext::appointmentTypeLabel($incident));
    }

    public function test_appointment_type_returns_null_when_unavailable(): void
    {
        $incident = new Incident([
            'category' => '',
            'title' => '',
        ]);

        $this->assertNull(AppointmentReminderMessageContext::appointmentTypeLabel($incident));
    }

    public function test_engineer_display_name_uses_first_name(): void
    {
        $engineer = new User([
            'first_name' => 'Gaurav',
            'last_name' => 'Kumar',
            'name' => 'Gaurav Kumar',
        ]);

        $this->assertSame('Gaurav', AppointmentReminderMessageContext::engineerDisplayName($engineer));
    }
}

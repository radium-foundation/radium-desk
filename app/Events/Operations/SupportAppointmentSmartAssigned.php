<?php

namespace App\Events\Operations;

use App\Data\Operations\SmartAssignmentResult;
use App\Models\Incident;
use App\Models\SupportAppointment;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupportAppointmentSmartAssigned
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Incident $incident,
        public SupportAppointment $appointment,
        public User $assignee,
        public SmartAssignmentResult $result,
    ) {}
}

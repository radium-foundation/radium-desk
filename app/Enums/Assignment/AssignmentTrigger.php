<?php

namespace App\Enums\Assignment;

enum AssignmentTrigger: string
{
    case OnCreate = 'on_create';
    case ValidationSuccess = 'validation_success';
    case ValidationFailure = 'validation_failure';
    case GraceExpired = 'grace_expired';
    case CommunicationIntake = 'communication_intake';
    case AppointmentBooked = 'appointment_booked';
    case EligibilityEvaluation = 'eligibility_evaluation';
    case EmailTriage = 'email_triage';
}

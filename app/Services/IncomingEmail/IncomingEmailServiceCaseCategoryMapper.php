<?php

namespace App\Services\IncomingEmail;

use App\Enums\IncomingEmailClassification;
use App\Enums\NewContactIntent;
use InvalidArgumentException;

/**
 * Maps inbound email classification → Service Case category / new-contact intent.
 *
 * There is no IncidentType enum — category is a string on incidents.
 */
class IncomingEmailServiceCaseCategoryMapper
{
    /**
     * Internal operational mail must not auto-create Service Cases (yet).
     */
    public function isInternalOperational(IncomingEmailClassification $classification): bool
    {
        return in_array($classification, [
            IncomingEmailClassification::FinanceAction,
            IncomingEmailClassification::HrAction,
            IncomingEmailClassification::VendorAction,
        ], true);
    }

    public function assertCustomerFacing(IncomingEmailClassification $classification): void
    {
        if ($this->isInternalOperational($classification)) {
            throw new InvalidArgumentException(
                'Internal operational email classification cannot auto-create a Service Case: '.$classification->value,
            );
        }

        if (! $classification->isOperational()) {
            throw new InvalidArgumentException(
                'Non-operational email classification cannot auto-create a Service Case: '.$classification->value,
            );
        }
    }

    public function category(IncomingEmailClassification $classification): string
    {
        $this->assertCustomerFacing($classification);

        return match ($classification) {
            IncomingEmailClassification::Support,
            IncomingEmailClassification::ExistingCustomer => 'Service',
            IncomingEmailClassification::Appointment => 'Appointment',
            IncomingEmailClassification::Refund => 'Refund',
            IncomingEmailClassification::PossibleSalesLead => 'Sales Lead',
            IncomingEmailClassification::UnknownCustomer => 'General Support',
            default => 'General Support',
        };
    }

    public function intent(IncomingEmailClassification $classification): NewContactIntent
    {
        $this->assertCustomerFacing($classification);

        return match ($classification) {
            IncomingEmailClassification::PossibleSalesLead => NewContactIntent::BuyDevice,
            IncomingEmailClassification::Support,
            IncomingEmailClassification::ExistingCustomer,
            IncomingEmailClassification::Appointment,
            IncomingEmailClassification::UnknownCustomer => NewContactIntent::GeneralSupport,
            IncomingEmailClassification::Refund => NewContactIntent::Other,
            default => NewContactIntent::GeneralSupport,
        };
    }
}

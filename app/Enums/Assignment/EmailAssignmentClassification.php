<?php

namespace App\Enums\Assignment;

/**
 * Future email routing classifications. Phase 1 defines the contract only.
 */
enum EmailAssignmentClassification: string
{
    case ExistingCaseAttachOnly = 'existing_case_attach_only';
    case NewSupportCase = 'new_support_case';
    case SalesLead = 'sales_lead';
    case UnknownEmail = 'unknown_email';
}

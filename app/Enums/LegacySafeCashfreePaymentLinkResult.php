<?php

namespace App\Enums;

enum LegacySafeCashfreePaymentLinkResult: string
{
    case LinkedSuccessfully = 'LINKED_SUCCESSFULLY';
    case AlreadyLinked = 'ALREADY_LINKED';
    case SkippedRevalidationFailure = 'SKIPPED_REVALIDATION_FAILURE';
    case FailedMutation = 'FAILED_MUTATION';
    case FailedPostValidation = 'FAILED_POST_VALIDATION';
}

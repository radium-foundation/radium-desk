<?php

namespace App\Enums;

enum EInvoiceCancelOutcome: string
{
    case NotRequired = 'not_required';
    case AlreadyCancelled = 'already_cancelled';
    case Success = 'success';
    case TemporaryFailure = 'temporary_failure';
    case PermanentFailure = 'permanent_failure';
    case ProviderNotImplemented = 'provider_not_implemented';
}

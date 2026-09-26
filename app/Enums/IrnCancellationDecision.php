<?php

namespace App\Enums;

enum IrnCancellationDecision: string
{
    case NotRequired = 'not_required';
    case CancellationRequired = 'cancellation_required';
    case CancellationNotPermittedByWindow = 'cancellation_not_permitted_by_window';
    case Cancelled = 'cancelled';
    case AlreadyCancelled = 'already_cancelled';
    case ProviderUnavailable = 'provider_unavailable';
    case UnknownManualReview = 'unknown_manual_review';
}

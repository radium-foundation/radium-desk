<?php

namespace App\Enums\RadiumBox;

enum RadiumBoxStatutoryReceiptPublicationStatus: string
{
    case Pending = 'pending';
    case AwaitingActivation = 'awaiting_activation';
    case Delivered = 'delivered';
    case DuplicateDelivered = 'duplicate_delivered';
    case FailedPermanent = 'failed_permanent';
}

<?php

namespace App\Enums;

enum GoodsReceiptSerialValidationStatus: string
{
    case Pending = 'pending';
    case Valid = 'valid';
    case Blank = 'blank';
    case DuplicateInReceipt = 'duplicate_in_receipt';
    case AlreadyExists = 'already_exists';
    case AssignedElsewhere = 'assigned_elsewhere';
    case WrongProduct = 'wrong_product';

    public function isBlocking(): bool
    {
        return $this !== self::Valid;
    }
}

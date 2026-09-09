<?php

namespace App\Enums;

enum FinancePartyKind: string
{
    case Person = 'person';
    case Organisation = 'organisation';

    public function label(): string
    {
        return match ($this) {
            self::Person => 'Person',
            self::Organisation => 'Organisation',
        };
    }
}

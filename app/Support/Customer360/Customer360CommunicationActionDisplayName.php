<?php

namespace App\Support\Customer360;

use App\Enums\CommunicationActionKey;

final class Customer360CommunicationActionDisplayName
{
    /**
     * @var list<string>
     */
    private const PURCHASE_ACTION_KEYS = [
        'buy_product',
        'buy_rd_service',
    ];

    public static function for(string $actionKey, string $name): string
    {
        if (in_array($actionKey, self::PURCHASE_ACTION_KEYS, true)) {
            return $name;
        }

        if (CommunicationActionKey::tryFrom($actionKey) === null) {
            return $name;
        }

        if (str_starts_with($name, 'Send ')) {
            return $name;
        }

        return 'Send '.$name;
    }
}

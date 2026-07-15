<?php

namespace App\Support\Customer360;

use Illuminate\Support\Str;

final class Customer360AgentNamePresenter
{
    public static function displayFirstName(?string $fullName, ?string $firstName = null): ?string
    {
        if (filled($firstName)) {
            return trim($firstName);
        }

        if (! filled($fullName)) {
            return null;
        }

        $trimmed = trim($fullName);
        $token = Str::before($trimmed, ' ');

        return filled($token) ? $token : $trimmed;
    }
}

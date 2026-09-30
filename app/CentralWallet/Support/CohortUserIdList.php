<?php

namespace App\CentralWallet\Support;

final class CohortUserIdList
{
    /**
     * @return list<int>
     */
    public static function normalize(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/\s*,\s*/', $raw) ?: [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $parsed = [];

        foreach ($raw as $id) {
            if (is_int($id)) {
                if ($id > 0) {
                    $parsed[] = $id;
                }

                continue;
            }

            if (! is_string($id) && ! is_float($id)) {
                continue;
            }

            $token = trim((string) $id);
            if ($token === '' || ! ctype_digit(ltrim($token, '+'))) {
                continue;
            }

            $intId = (int) $token;
            if ($intId > 0) {
                $parsed[] = $intId;
            }
        }

        sort($parsed);

        return array_values(array_unique($parsed));
    }
}

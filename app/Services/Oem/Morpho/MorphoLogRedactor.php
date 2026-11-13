<?php

namespace App\Services\Oem\Morpho;

final class MorphoLogRedactor
{
    public static function redactSerial(?string $serial): string
    {
        if ($serial === null || $serial === '') {
            return '[serial:empty]';
        }

        $len = strlen($serial);

        if ($len <= 4) {
            return '[serial:redacted]';
        }

        return substr($serial, 0, 2).str_repeat('*', max(0, $len - 4)).substr($serial, -2);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    public static function redactHeaders(array $headers): array
    {
        $redacted = [];

        foreach ($headers as $name => $value) {
            $lower = strtolower($name);

            if (in_array($lower, ['authorization', 'securitystamp', 'x-api-key'], true)) {
                $redacted[$name] = '[redacted]';

                continue;
            }

            $redacted[$name] = $value;
        }

        return $redacted;
    }
}

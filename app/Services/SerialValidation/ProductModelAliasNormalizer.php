<?php

namespace App\Services\SerialValidation;

use App\Support\DeviceModelFormatter;
use Illuminate\Support\Str;

class ProductModelAliasNormalizer
{
    /**
     * Resolve a production device model label to a serial_pattern_profiles key.
     */
    public function resolve(?string $productLabel): ?string
    {
        if (! filled($productLabel)) {
            return null;
        }

        $trimmed = trim($productLabel);
        $profileKeys = array_keys(config('serial_pattern_profiles', []));

        foreach ($profileKeys as $profileKey) {
            if ($this->labelsMatch($trimmed, $profileKey)) {
                return $profileKey;
            }
        }

        foreach (config('serial_pattern_profile_aliases.aliases', []) as $profileKey => $aliases) {
            if (! is_string($profileKey) || ! is_array($aliases)) {
                continue;
            }

            foreach ($aliases as $alias) {
                if (! is_string($alias) || $alias === '') {
                    continue;
                }

                if ($this->labelsMatch($trimmed, $alias)) {
                    return $profileKey;
                }
            }
        }

        $stripped = $this->stripVendorPrefixes($trimmed);

        if ($stripped !== $trimmed) {
            $resolved = $this->resolve($stripped);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        $shortDisplay = DeviceModelFormatter::shortDisplay($trimmed);

        if (filled($shortDisplay) && ! $this->labelsMatch($shortDisplay, $trimmed)) {
            $resolved = $this->resolve($shortDisplay);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return $this->resolveFromProductCode($trimmed, $profileKeys);
    }

    private function labelsMatch(string $left, string $right): bool
    {
        return $this->compactLabel($left) === $this->compactLabel($right);
    }

    private function compactLabel(string $label): string
    {
        return Str::upper(preg_replace('/\s+/', '', trim($label)) ?? trim($label));
    }

    private function stripVendorPrefixes(string $label): string
    {
        $tokens = preg_split('/\s+/', trim($label)) ?: [];
        $vendorPrefixes = config('serial_pattern_profile_aliases.vendor_prefixes', []);

        while ($tokens !== [] && in_array(Str::upper($tokens[0]), $vendorPrefixes, true)) {
            array_shift($tokens);
        }

        return implode(' ', $tokens);
    }

    /**
     * @param  list<string>  $profileKeys
     */
    private function resolveFromProductCode(string $label, array $profileKeys): ?string
    {
        $compact = $this->compactLabel($label);

        $patterns = [
            'MIS 100' => '/^MIS100(?:V2)?$/',
            'MFS 110' => '/^MFS110$/',
            'FM 220' => '/^FM220U?$/',
            'MSO E3' => '/^MSO1300E3$/',
        ];

        foreach ($patterns as $profileKey => $pattern) {
            if (! in_array($profileKey, $profileKeys, true)) {
                continue;
            }

            if (preg_match($pattern, $compact) === 1) {
                return $profileKey;
            }
        }

        return null;
    }
}

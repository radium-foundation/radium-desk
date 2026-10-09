<?php

namespace App\CentralWallet\Reliability;

final class CentralWalletServiceProviderRegistrationExtractor
{
    /**
     * @return list<string> FQCN strings registered via singleton()/bind()
     */
    public function extract(string $providerFilePath): array
    {
        if (! is_file($providerFilePath)) {
            return [];
        }

        $contents = (string) file_get_contents($providerFilePath);

        preg_match_all(
            '/->(?:singleton|bind)\(\s*(?:\\\\)?([\w\\\\]+)::class/',
            $contents,
            $matches,
        );

        $classes = array_values(array_unique(array_map(
            static fn (string $class): string => ltrim($class, '\\'),
            $matches[1] ?? [],
        )));

        sort($classes);

        return $classes;
    }
}

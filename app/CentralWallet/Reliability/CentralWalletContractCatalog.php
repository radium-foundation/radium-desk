<?php

namespace App\CentralWallet\Reliability;

use InvalidArgumentException;
use RuntimeException;

final class CentralWalletContractCatalog
{
    public const VERSION = '1.0.0';

    public const CONTRACT_RELATIVE_PATH = 'contracts/central-wallet/v1/contract.json';

    public const COMPATIBILITY_RELATIVE_PATH = 'contracts/central-wallet/v1/compatibility-matrix.json';

    public function contractPath(?string $basePath = null): string
    {
        return ($basePath ?? base_path()).'/'.self::CONTRACT_RELATIVE_PATH;
    }

    public function compatibilityMatrixPath(?string $basePath = null): string
    {
        return ($basePath ?? base_path()).'/'.self::COMPATIBILITY_RELATIVE_PATH;
    }

    /**
     * @return array<string, mixed>
     */
    public function loadContract(?string $basePath = null): array
    {
        return $this->loadJsonFile($this->contractPath($basePath));
    }

    /**
     * @return array<string, mixed>
     */
    public function loadCompatibilityMatrix(?string $basePath = null): array
    {
        return $this->loadJsonFile($this->compatibilityMatrixPath($basePath));
    }

    /**
     * @return array<string, mixed>
     */
    public function loadFixture(string $relativePath, ?string $basePath = null): array
    {
        $path = ($basePath ?? base_path()).'/contracts/central-wallet/v1/'.$relativePath;

        return $this->loadJsonFile($path);
    }

    /**
     * @param  array<string, mixed>  $contract
     */
    public function assertContractVersion(array $contract): void
    {
        $version = (string) ($contract['contract_version'] ?? '');

        if ($version !== self::VERSION) {
            throw new InvalidArgumentException("Unsupported contract version: {$version}");
        }
    }

    /**
     * @return list<string>
     */
    public function requiredProviderRouteNames(?string $basePath = null): array
    {
        $contract = $this->loadContract($basePath);
        $routes = [];

        foreach ($contract['endpoints'] ?? [] as $endpoint) {
            if (! is_array($endpoint)) {
                continue;
            }

            $routeName = $endpoint['route_name'] ?? null;
            if (is_string($routeName) && $routeName !== '') {
                $routes[] = $routeName;
            }
        }

        return array_values(array_unique($routes));
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJsonFile(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Contract file not found: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException("Invalid JSON contract file: {$path}");
        }

        return $decoded;
    }
}

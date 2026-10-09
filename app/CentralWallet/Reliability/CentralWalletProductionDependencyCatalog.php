<?php

namespace App\CentralWallet\Reliability;

final class CentralWalletProductionDependencyCatalog
{
    public const CONTRACT_RELATIVE_PATH = 'contracts/central-wallet/v1/production-dependency-contract.json';

    public function __construct(
        private readonly CentralWalletContractCatalog $contractCatalog,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function load(): array
    {
        $path = base_path().'/'.self::CONTRACT_RELATIVE_PATH;

        if (! is_file($path)) {
            throw new \RuntimeException('Production dependency contract missing: '.self::CONTRACT_RELATIVE_PATH);
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new \RuntimeException('Production dependency contract is not valid JSON.');
        }

        return $decoded;
    }

    public function version(): string
    {
        $contract = $this->load();

        return (string) ($contract['contract_version'] ?? '0.0.0');
    }
}

<?php

namespace App\CentralWallet\Reliability;

use App\Services\Release\ReleaseManifestStore;

final class CentralWalletRuntimeManifestBuilder
{
    public function __construct(
        private readonly CentralWalletContractCatalog $catalog,
        private readonly CentralWalletRuntimeManifestStore $runtimeManifestStore,
        private readonly ReleaseManifestStore $releaseManifestStore,
    ) {}

    /**
     * @param  list<string>  $runtimeFiles
     * @return array<string, mixed>
     */
    public function build(
        string $project,
        string $environment,
        string $deploymentType,
        array $runtimeFiles,
        ?string $gitSha = null,
        ?string $gitBranch = null,
        ?string $overlayPromptId = null,
        ?string $overlayDeploymentId = null,
        ?string $sourceCommit = null,
        ?string $rollbackReference = null,
    ): array {
        $previous = $this->runtimeManifestStore->read();
        $release = $this->releaseManifestStore->read();

        return [
            'schema_version' => 1,
            'project' => $project,
            'environment' => $environment,
            'deployment_type' => $deploymentType,
            'contract_version' => CentralWalletContractCatalog::VERSION,
            'git' => [
                'sha' => $gitSha,
                'branch' => $gitBranch,
            ],
            'release_manifest' => [
                'path' => 'storage/app/private/release.json',
                'version' => $release['version'] ?? null,
                'build' => $release['build'] ?? null,
                'deployed_at' => $release['deployed_at'] ?? null,
            ],
            'overlay' => [
                'prompt_id' => $overlayPromptId,
                'deployment_id' => $overlayDeploymentId,
                'source_commit' => $sourceCommit,
            ],
            'runtime_files' => $this->hashRuntimeFiles($runtimeFiles),
            'previous_manifest_sha256' => $this->runtimeManifestStore->sha256(),
            'rollback_reference' => $rollbackReference,
            'deployed_at' => now()->toIso8601String(),
            'generated_by' => 'central-wallet:write-runtime-manifest',
            'previous_manifest_present' => $previous !== null,
        ];
    }

    /**
     * @return list<string>
     */
    public function defaultDeskRuntimeFiles(): array
    {
        return [
            'routes/central_wallet.php',
            'app/CentralWallet/Application/WalletVisibilityService.php',
            'app/CentralWallet/Infrastructure/Http/Controllers/WalletVisibilityController.php',
            'app/Providers/CentralWalletServiceProvider.php',
            'contracts/central-wallet/v1/contract.json',
            'contracts/central-wallet/v1/compatibility-matrix.json',
        ];
    }

    /**
     * @param  list<string>  $runtimeFiles
     * @return list<array{path: string, sha256: string|null, exists: bool}>
     */
    private function hashRuntimeFiles(array $runtimeFiles): array
    {
        $hashes = [];

        foreach ($runtimeFiles as $relativePath) {
            $absolute = base_path($relativePath);
            $hashes[] = [
                'path' => $relativePath,
                'sha256' => is_file($absolute) ? hash_file('sha256', $absolute) : null,
                'exists' => is_file($absolute),
            ];
        }

        return $hashes;
    }
}

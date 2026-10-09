<?php

namespace App\CentralWallet\Reliability;

use App\Services\Release\ReleaseManifestStore;

final class CentralWalletRuntimeManifestBuilder
{
    public function __construct(
        private readonly CentralWalletRuntimeManifestStore $runtimeManifestStore,
        private readonly CentralWalletManagedFileInventory $managedFileInventory,
        private readonly CentralWalletReleaseIdentityCalculator $releaseIdentityCalculator,
        private readonly ?ReleaseManifestStore $releaseManifestStore = null,
    ) {}

    /**
     * @param  list<array{commit: string, label: string}>  $sourceComponents
     * @return array<string, mixed>
     */
    public function build(
        string $project,
        string $environment,
        string $deploymentType,
        ?string $gitSha = null,
        ?string $gitBranch = null,
        ?string $overlayPromptId = null,
        ?string $overlayDeploymentId = null,
        ?string $sourceCommit = null,
        ?string $rollbackReference = null,
        ?bool $gitWorktreeClean = null,
        array $sourceComponents = [],
        bool $includeDeskReleaseManifest = true,
    ): array {
        $previous = $this->runtimeManifestStore->read();
        $release = $includeDeskReleaseManifest && $this->releaseManifestStore !== null
            ? $this->releaseManifestStore->read()
            : [];
        $managedFiles = $this->managedFileInventory->hashDeclaredFiles();
        $primarySourceCommit = $this->resolvePrimarySourceCommit($gitSha, $sourceCommit, $sourceComponents);
        $normalizedComponents = $this->normalizeSourceComponents($primarySourceCommit, $sourceComponents);

        $sourceIdentity = [
            'primary_source_commit' => $primarySourceCommit,
            'source_components' => $normalizedComponents,
            'git_worktree_clean' => $gitWorktreeClean,
        ];

        $canonicalPayload = $this->releaseIdentityCalculator->buildCanonicalPayload(
            project: $project,
            deploymentType: $deploymentType,
            contractVersion: CentralWalletContractCatalog::VERSION,
            releaseBranch: $this->managedFileInventory->releaseBranch(),
            sourceIdentity: $sourceIdentity,
            managedFiles: $managedFiles,
            inventoryVersion: $this->managedFileInventory->inventoryVersion(),
        );

        $releaseIdentity = $this->releaseIdentityCalculator->hashCanonicalPayload($canonicalPayload);

        return [
            'schema_version' => CentralWalletReleaseIdentityCalculator::SCHEMA_VERSION,
            'manifest_contract_version' => CentralWalletReleaseIdentityCalculator::MANIFEST_CONTRACT_VERSION,
            'project' => $project,
            'target_environment' => $environment,
            'deployment_type' => $deploymentType,
            'contract_version' => CentralWalletContractCatalog::VERSION,
            'release_branch' => $this->managedFileInventory->releaseBranch(),
            'source_identity' => $sourceIdentity,
            'release_identity' => $releaseIdentity,
            'managed_file_inventory_version' => $this->managedFileInventory->inventoryVersion(),
            'managed_files' => $managedFiles,
            'managed_file_count' => count($managedFiles),
            'production_dependency_contract' => $this->productionDependencyContractMetadata(),
            'git' => [
                'sha' => $gitSha,
                'branch' => $gitBranch,
            ],
            'release_manifest' => $includeDeskReleaseManifest ? [
                'path' => 'storage/app/private/release.json',
                'version' => $release['version'] ?? null,
                'build' => $release['build'] ?? null,
                'deployed_at' => $release['deployed_at'] ?? null,
            ] : [
                'path' => null,
                'version' => null,
                'build' => null,
                'deployed_at' => null,
            ],
            'overlay' => [
                'prompt_id' => $overlayPromptId,
                'deployment_id' => $overlayDeploymentId,
                'source_commit' => $sourceCommit ?? $primarySourceCommit,
            ],
            'audit' => [
                'generated_at' => now()->toIso8601String(),
                'generated_by' => 'central-wallet:write-runtime-manifest',
                'previous_manifest_sha256' => $this->runtimeManifestStore->sha256(),
                'previous_manifest_present' => $previous !== null,
                'rollback_reference' => $rollbackReference,
            ],
            'runtime_files' => $managedFiles,
            'deployed_at' => now()->toIso8601String(),
            'generated_by' => 'central-wallet:write-runtime-manifest',
            'previous_manifest_sha256' => $this->runtimeManifestStore->sha256(),
            'rollback_reference' => $rollbackReference,
            'previous_manifest_present' => $previous !== null,
        ];
    }

    /**
     * @return list<string>
     */
    public function defaultDeskRuntimeFiles(): array
    {
        return $this->managedFileInventory->declaredPaths();
    }

    /**
     * @return list<string>
     */
    public function defaultSpokeRuntimeFiles(): array
    {
        return $this->managedFileInventory->declaredPaths();
    }

    /**
     * @param  list<array{commit: string, label: string}>  $sourceComponents
     * @return list<array{commit: string, label: string}>
     */
    private function normalizeSourceComponents(?string $primarySourceCommit, array $sourceComponents): array
    {
        $normalized = [];

        foreach ($sourceComponents as $component) {
            if (! is_array($component)) {
                continue;
            }

            $commit = trim((string) ($component['commit'] ?? ''));
            $label = trim((string) ($component['label'] ?? ''));

            if ($commit === '') {
                continue;
            }

            $normalized[] = [
                'commit' => $commit,
                'label' => $label !== '' ? $label : 'component',
            ];
        }

        if ($normalized === [] && is_string($primarySourceCommit) && $primarySourceCommit !== '') {
            $normalized[] = [
                'commit' => $primarySourceCommit,
                'label' => 'primary',
            ];
        }

        usort($normalized, function (array $a, array $b): int {
            $commitCompare = strcmp($a['commit'], $b['commit']);

            return $commitCompare !== 0 ? $commitCompare : strcmp($a['label'], $b['label']);
        });

        return $normalized;
    }

    /**
     * @param  list<array{commit: string, label: string}>  $sourceComponents
     */
    private function resolvePrimarySourceCommit(?string $gitSha, ?string $sourceCommit, array $sourceComponents): ?string
    {
        $sourceCommit = is_string($sourceCommit) ? trim($sourceCommit) : '';
        if ($sourceCommit !== '') {
            return $sourceCommit;
        }

        $gitSha = is_string($gitSha) ? trim($gitSha) : '';
        if ($gitSha !== '') {
            return $gitSha;
        }

        foreach ($sourceComponents as $component) {
            if (! is_array($component)) {
                continue;
            }

            $commit = trim((string) ($component['commit'] ?? ''));
            if ($commit !== '') {
                return $commit;
            }
        }

        return null;
    }

    /**
     * @return array{version: string|null}
     */
    private function productionDependencyContractMetadata(): array
    {
        try {
            $catalog = app(CentralWalletProductionDependencyCatalog::class);

            return ['version' => $catalog->version()];
        } catch (\Throwable) {
            return ['version' => null];
        }
    }
}

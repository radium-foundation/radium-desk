<?php

namespace App\CentralWallet\Reliability;

final class CentralWalletReleaseIdentityCalculator
{
    public const MANIFEST_CONTRACT_VERSION = '2.0.0';

    public const SCHEMA_VERSION = 2;

    /**
     * @param  array<string, mixed>  $sourceIdentity
     * @param  list<array{path: string, sha256: string|null, classification: string}>  $managedFiles
     */
    public function buildCanonicalPayload(
        string $project,
        string $deploymentType,
        string $contractVersion,
        ?string $releaseBranch,
        array $sourceIdentity,
        array $managedFiles,
        string $inventoryVersion,
    ): array {
        $normalizedFiles = [];

        foreach ($managedFiles as $entry) {
            $normalizedFiles[] = [
                'path' => (string) ($entry['path'] ?? ''),
                'sha256' => $entry['sha256'] ?? null,
                'classification' => (string) ($entry['classification'] ?? 'tracked_release_file'),
            ];
        }

        usort($normalizedFiles, fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        $components = $sourceIdentity['source_components'] ?? [];
        if (is_array($components)) {
            usort($components, function (array $a, array $b): int {
                $commitCompare = strcmp((string) ($a['commit'] ?? ''), (string) ($b['commit'] ?? ''));

                return $commitCompare !== 0
                    ? $commitCompare
                    : strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
            });
        } else {
            $components = [];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'manifest_contract_version' => self::MANIFEST_CONTRACT_VERSION,
            'project' => $project,
            'deployment_type' => $deploymentType,
            'contract_version' => $contractVersion,
            'release_branch' => $releaseBranch,
            'source_identity' => [
                'primary_source_commit' => (string) ($sourceIdentity['primary_source_commit'] ?? ''),
                'source_components' => $components,
            ],
            'managed_file_inventory_version' => $inventoryVersion,
            'managed_files' => $normalizedFiles,
        ];
    }

    /**
     * @param  array<string, mixed>  $canonicalPayload
     */
    public function hashCanonicalPayload(array $canonicalPayload): string
    {
        $encoded = json_encode(
            $canonicalPayload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return hash('sha256', $encoded);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function calculateFromManifest(array $manifest): string
    {
        $managedFiles = $manifest['managed_files'] ?? $manifest['runtime_files'] ?? [];

        if (! is_array($managedFiles)) {
            $managedFiles = [];
        }

        $normalizedManagedFiles = [];

        foreach ($managedFiles as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $normalizedManagedFiles[] = [
                'path' => (string) ($entry['path'] ?? ''),
                'sha256' => $entry['sha256'] ?? null,
                'classification' => (string) ($entry['classification'] ?? 'tracked_release_file'),
            ];
        }

        $sourceIdentity = is_array($manifest['source_identity'] ?? null)
            ? $manifest['source_identity']
            : [
                'primary_source_commit' => (string) ($manifest['overlay']['source_commit'] ?? ($manifest['git']['sha'] ?? '')),
                'source_components' => $manifest['source_identity']['source_components'] ?? [],
            ];

        if (! isset($sourceIdentity['source_components']) || ! is_array($sourceIdentity['source_components'])) {
            $primary = trim((string) ($sourceIdentity['primary_source_commit'] ?? ''));
            $sourceIdentity['source_components'] = $primary !== ''
                ? [['commit' => $primary, 'label' => 'primary']]
                : [];
        }

        $payload = $this->buildCanonicalPayload(
            project: (string) ($manifest['project'] ?? ''),
            deploymentType: (string) ($manifest['deployment_type'] ?? 'git'),
            contractVersion: (string) ($manifest['contract_version'] ?? CentralWalletContractCatalog::VERSION),
            releaseBranch: is_string($manifest['release_branch'] ?? null) ? $manifest['release_branch'] : null,
            sourceIdentity: $sourceIdentity,
            managedFiles: $normalizedManagedFiles,
            inventoryVersion: (string) ($manifest['managed_file_inventory_version'] ?? '0.0.0'),
        );

        return $this->hashCanonicalPayload($payload);
    }
}

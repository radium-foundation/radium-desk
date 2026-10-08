<?php

namespace App\CentralWallet\Reliability;

use RuntimeException;

final class CentralWalletManagedFileInventory
{
    public const INVENTORY_RELATIVE_PATH = 'contracts/central-wallet/v1/managed-file-inventory.json';

    public function __construct(
        private readonly CentralWalletContractCatalog $catalog,
    ) {}

    public function path(): string
    {
        return base_path(self::INVENTORY_RELATIVE_PATH);
    }

    /**
     * @return array<string, mixed>
     */
    public function load(): array
    {
        $path = $this->path();

        if (! is_file($path)) {
            throw new RuntimeException('Managed file inventory missing: '.self::INVENTORY_RELATIVE_PATH);
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Managed file inventory is not valid JSON.');
        }

        return $decoded;
    }

    public function inventoryVersion(): string
    {
        return (string) ($this->load()['inventory_version'] ?? '0.0.0');
    }

    public function project(): string
    {
        return (string) ($this->load()['project'] ?? '');
    }

    public function releaseBranch(): ?string
    {
        $branch = $this->load()['release_branch'] ?? null;

        return is_string($branch) && $branch !== '' ? $branch : null;
    }

    /**
     * @return list<array{path: string, classification: string}>
     */
    public function declaredFiles(): array
    {
        $files = [];

        foreach ($this->load()['files'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $path = trim((string) ($entry['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $files[] = [
                'path' => $path,
                'classification' => (string) ($entry['classification'] ?? 'tracked_release_file'),
            ];
        }

        usort($files, fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $files;
    }

    /**
     * @return list<array{path: string, extensions: list<string>}>
     */
    public function scanRoots(): array
    {
        $roots = [];

        foreach ($this->load()['managed_scan_roots'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $path = trim((string) ($entry['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $extensions = [];
            foreach ($entry['extensions'] ?? [] as $extension) {
                if (is_string($extension) && $extension !== '') {
                    $extensions[] = ltrim($extension, '.');
                }
            }

            $roots[] = [
                'path' => $path,
                'extensions' => $extensions === [] ? ['php'] : $extensions,
            ];
        }

        return $roots;
    }

    /**
     * @return list<array{path: string, sha256: string|null, exists: bool, classification: string}>
     */
    public function hashDeclaredFiles(): array
    {
        $hashes = [];

        foreach ($this->declaredFiles() as $entry) {
            $absolute = base_path($entry['path']);
            $hashes[] = [
                'path' => $entry['path'],
                'sha256' => is_file($absolute) ? hash_file('sha256', $absolute) : null,
                'exists' => is_file($absolute),
                'classification' => $entry['classification'],
            ];
        }

        return $hashes;
    }

    /**
     * @return list<string>
     */
    public function declaredPaths(): array
    {
        return array_map(
            fn (array $entry): string => $entry['path'],
            $this->declaredFiles(),
        );
    }
}

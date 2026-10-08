<?php

namespace App\CentralWallet\Reliability;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class CentralWalletOverlayIntegrityVerifier
{
    public function __construct(
        private readonly CentralWalletManagedFileInventory $inventory,
        private readonly CentralWalletReleaseIdentityCalculator $releaseIdentityCalculator,
        private readonly CentralWalletRuntimeManifestStore $runtimeManifestStore,
    ) {}

    /**
     * @return array{
     *     status: string,
     *     checks: list<array<string, mixed>>,
     *     findings: array<string, mixed>
     * }
     */
    public function verify(?array $manifest = null): array
    {
        $manifest ??= $this->runtimeManifestStore->read();
        $checks = [];
        $findings = [
            'missing' => [],
            'changed' => [],
            'unexpected' => [],
            'orphan' => [],
            'manifest_errors' => [],
            'release_identity_mismatch' => false,
            'source_identity_issues' => [],
            'unverifiable' => [],
        ];

        $checks[] = $this->checkManifestPresent($manifest, $findings);
        $checks[] = $this->checkManifestSchema($manifest, $findings);
        $checks[] = $this->checkInventoryComplete($manifest, $findings);
        $checks[] = $this->checkMissingManagedFiles($manifest, $findings);
        $checks[] = $this->checkHashMismatches($manifest, $findings);
        $checks[] = $this->checkReleaseIdentity($manifest, $findings);
        $checks[] = $this->checkSourceIdentity($manifest, $findings);
        $checks[] = $this->checkOrphanOverlayFiles($findings);
        $checks[] = $this->checkUnexpectedManagedFiles($manifest, $findings);

        return [
            'status' => $this->aggregateStatus($checks),
            'checks' => $checks,
            'findings' => $findings,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     */
    private function aggregateStatus(array $checks): string
    {
        foreach ($checks as $check) {
            if (($check['result'] ?? '') === 'FAIL') {
                return 'FAIL';
            }
        }

        foreach ($checks as $check) {
            if (($check['result'] ?? '') === 'WARN') {
                return 'WARN';
            }
        }

        foreach ($checks as $check) {
            if (($check['result'] ?? '') === 'UNVERIFIABLE') {
                return 'UNVERIFIABLE';
            }
        }

        return 'PASS';
    }

    /**
     * @param  array<string, mixed>  $findings
     * @return array<string, mixed>
     */
    private function checkManifestPresent(?array $manifest, array &$findings): array
    {
        if ($manifest === null) {
            $findings['manifest_errors'][] = 'runtime manifest missing';

            return [
                'id' => 'manifest_present',
                'result' => 'FAIL',
                'details' => ['path' => $this->runtimeManifestStore->path()],
            ];
        }

        return [
            'id' => 'manifest_present',
            'result' => 'PASS',
            'details' => ['schema_version' => $manifest['schema_version'] ?? null],
        ];
    }

    /**
     * @param  array<string, mixed>  $findings
     * @return array<string, mixed>
     */
    private function checkManifestSchema(?array $manifest, array &$findings): array
    {
        if ($manifest === null) {
            return ['id' => 'manifest_schema', 'result' => 'FAIL', 'details' => []];
        }

        $schemaVersion = (int) ($manifest['schema_version'] ?? 0);

        if ($schemaVersion < 2) {
            $findings['manifest_errors'][] = 'legacy schema_version=1 manifest; regenerate v2 manifest';

            return [
                'id' => 'manifest_schema',
                'result' => 'WARN',
                'details' => ['schema_version' => $schemaVersion, 'expected' => 2],
            ];
        }

        $required = [
            'manifest_contract_version',
            'project',
            'target_environment',
            'deployment_type',
            'contract_version',
            'release_identity',
            'managed_files',
            'source_identity',
        ];

        $missingFields = [];
        foreach ($required as $field) {
            if (! array_key_exists($field, $manifest)) {
                $missingFields[] = $field;
            }
        }

        if ($missingFields !== []) {
            $findings['manifest_errors'][] = 'missing required manifest fields: '.implode(', ', $missingFields);

            return [
                'id' => 'manifest_schema',
                'result' => 'FAIL',
                'details' => ['missing_fields' => $missingFields],
            ];
        }

        return [
            'id' => 'manifest_schema',
            'result' => 'PASS',
            'details' => [
                'schema_version' => $schemaVersion,
                'manifest_contract_version' => $manifest['manifest_contract_version'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $findings
     * @return array<string, mixed>
     */
    private function checkInventoryComplete(?array $manifest, array &$findings): array
    {
        if ($manifest === null) {
            return ['id' => 'inventory_complete', 'result' => 'FAIL', 'details' => []];
        }

        $declared = $this->inventory->declaredPaths();
        $manifestPaths = $this->manifestManagedPaths($manifest);
        $missingFromManifest = array_values(array_diff($declared, $manifestPaths));
        $unexpectedInManifest = array_values(array_diff($manifestPaths, $declared));

        foreach ($missingFromManifest as $path) {
            $findings['missing'][] = ['path' => $path, 'reason' => 'declared_in_inventory_missing_from_manifest'];
        }

        foreach ($unexpectedInManifest as $path) {
            $findings['unexpected'][] = ['path' => $path, 'reason' => 'present_in_manifest_not_in_inventory'];
        }

        $result = $missingFromManifest === [] && $unexpectedInManifest === [] ? 'PASS' : 'FAIL';

        return [
            'id' => 'inventory_complete',
            'result' => $result,
            'details' => [
                'inventory_count' => count($declared),
                'manifest_count' => count($manifestPaths),
                'missing_from_manifest' => $missingFromManifest,
                'unexpected_in_manifest' => $unexpectedInManifest,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $findings
     * @return array<string, mixed>
     */
    private function checkMissingManagedFiles(?array $manifest, array &$findings): array
    {
        $missing = [];

        foreach ($this->inventory->hashDeclaredFiles() as $entry) {
            if (($entry['exists'] ?? false) === true) {
                continue;
            }

            $missing[] = $entry['path'];
            $findings['missing'][] = ['path' => $entry['path'], 'reason' => 'file_absent_on_disk'];
        }

        return [
            'id' => 'missing_managed_files',
            'result' => $missing === [] ? 'PASS' : 'FAIL',
            'details' => ['missing' => $missing],
        ];
    }

    /**
     * @param  array<string, mixed>  $findings
     * @return array<string, mixed>
     */
    private function checkHashMismatches(?array $manifest, array &$findings): array
    {
        if ($manifest === null) {
            return ['id' => 'hash_mismatch', 'result' => 'FAIL', 'details' => []];
        }

        $changed = [];

        foreach ($this->manifestManagedEntries($manifest) as $entry) {
            $path = (string) ($entry['path'] ?? '');
            $expected = $entry['sha256'] ?? null;
            $absolute = base_path($path);
            $actual = is_file($absolute) ? hash_file('sha256', $absolute) : null;

            if ($expected !== $actual) {
                $changed[] = [
                    'path' => $path,
                    'expected' => $expected,
                    'actual' => $actual,
                    'exists' => is_file($absolute),
                ];
                $findings['changed'][] = [
                    'path' => $path,
                    'expected' => $expected,
                    'actual' => $actual,
                ];
            }
        }

        return [
            'id' => 'hash_mismatch',
            'result' => $changed === [] ? 'PASS' : 'FAIL',
            'details' => ['changed' => $changed],
        ];
    }

    /**
     * @param  array<string, mixed>  $findings
     * @return array<string, mixed>
     */
    private function checkReleaseIdentity(?array $manifest, array &$findings): array
    {
        if ($manifest === null) {
            return ['id' => 'release_identity', 'result' => 'FAIL', 'details' => []];
        }

        if ((int) ($manifest['schema_version'] ?? 0) < 2) {
            return [
                'id' => 'release_identity',
                'result' => 'WARN',
                'details' => ['message' => 'legacy manifest has no release_identity field'],
            ];
        }

        try {
            $expected = $this->releaseIdentityCalculator->calculateFromManifest($manifest);
        } catch (\Throwable $exception) {
            $findings['manifest_errors'][] = $exception->getMessage();

            return [
                'id' => 'release_identity',
                'result' => 'FAIL',
                'details' => ['message' => $exception->getMessage()],
            ];
        }

        $declared = (string) ($manifest['release_identity'] ?? '');
        $matches = $declared !== '' && hash_equals($declared, $expected);
        $findings['release_identity_mismatch'] = ! $matches;

        return [
            'id' => 'release_identity',
            'result' => $matches ? 'PASS' : 'FAIL',
            'details' => [
                'declared' => $declared,
                'calculated' => $expected,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $findings
     * @return array<string, mixed>
     */
    private function checkSourceIdentity(?array $manifest, array &$findings): array
    {
        if ($manifest === null) {
            return ['id' => 'source_identity', 'result' => 'FAIL', 'details' => []];
        }

        $sourceIdentity = $manifest['source_identity'] ?? null;
        $issues = [];

        if (! is_array($sourceIdentity)) {
            $issues[] = 'source_identity missing';
        } else {
            $primary = trim((string) ($sourceIdentity['primary_source_commit'] ?? ''));
            if ($primary === '') {
                $issues[] = 'primary_source_commit missing';
            } elseif (! preg_match('/^[0-9a-f]{7,40}$/i', $primary)) {
                $issues[] = 'primary_source_commit malformed';
            }

            $components = $sourceIdentity['source_components'] ?? [];
            if (is_array($components) && $components !== []) {
                foreach ($components as $component) {
                    if (! is_array($component)) {
                        $issues[] = 'invalid source component entry';
                        continue;
                    }

                    $commit = trim((string) ($component['commit'] ?? ''));
                    if ($commit === '' || ! preg_match('/^[0-9a-f]{7,40}$/i', $commit)) {
                        $issues[] = 'source component commit malformed';
                    }
                }
            }
        }

        $findings['source_identity_issues'] = $issues;

        return [
            'id' => 'source_identity',
            'result' => $issues === [] ? 'PASS' : 'FAIL',
            'details' => ['issues' => $issues],
        ];
    }

    /**
     * @param  array<string, mixed>  $findings
     * @return array<string, mixed>
     */
    private function checkOrphanOverlayFiles(array &$findings): array
    {
        $declared = array_flip($this->inventory->declaredPaths());
        $orphans = [];

        foreach ($this->inventory->scanRoots() as $root) {
            $absoluteRoot = base_path($root['path']);

            if (! is_dir($absoluteRoot)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absoluteRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $extension = strtolower($file->getExtension());
                if (! in_array($extension, $root['extensions'], true)) {
                    continue;
                }

                $relative = $this->relativePath($file->getPathname());
                if ($relative === null) {
                    continue;
                }

                if (! isset($declared[$relative])) {
                    $orphans[] = $relative;
                    $findings['orphan'][] = ['path' => $relative, 'reason' => 'present_on_disk_not_in_inventory'];
                }
            }
        }

        sort($orphans);

        return [
            'id' => 'orphan_overlay_files',
            'result' => $orphans === [] ? 'PASS' : 'FAIL',
            'details' => ['orphans' => $orphans],
        ];
    }

    /**
     * @param  array<string, mixed>  $findings
     * @return array<string, mixed>
     */
    private function checkUnexpectedManagedFiles(?array $manifest, array &$findings): array
    {
        if ($manifest === null) {
            return ['id' => 'unexpected_managed_files', 'result' => 'FAIL', 'details' => []];
        }

        $declared = array_flip($this->inventory->declaredPaths());
        $unexpected = [];

        foreach ($this->manifestManagedPaths($manifest) as $path) {
            if (! isset($declared[$path])) {
                $unexpected[] = $path;
            }
        }

        return [
            'id' => 'unexpected_managed_files',
            'result' => $unexpected === [] ? 'PASS' : 'FAIL',
            'details' => ['unexpected' => $unexpected],
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function manifestManagedPaths(array $manifest): array
    {
        return array_values(array_map(
            fn (array $entry): string => (string) ($entry['path'] ?? ''),
            $this->manifestManagedEntries($manifest),
        ));
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<array<string, mixed>>
     */
    private function manifestManagedEntries(array $manifest): array
    {
        $entries = $manifest['managed_files'] ?? $manifest['runtime_files'] ?? [];

        if (! is_array($entries)) {
            return [];
        }

        $normalized = [];

        foreach ($entries as $entry) {
            if (is_array($entry)) {
                $normalized[] = $entry;
            }
        }

        return $normalized;
    }

    private function relativePath(string $absolutePath): ?string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $normalizedAbsolute = str_replace('\\', '/', $absolutePath);
        $normalizedBase = str_replace('\\', '/', $base);

        if (! str_starts_with($normalizedAbsolute, $normalizedBase)) {
            return null;
        }

        return ltrim(substr($normalizedAbsolute, strlen($normalizedBase)), '/');
    }
}

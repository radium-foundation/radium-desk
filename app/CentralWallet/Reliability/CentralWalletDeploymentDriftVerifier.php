<?php

namespace App\CentralWallet\Reliability;

use Illuminate\Support\Facades\Route;

final class CentralWalletDeploymentDriftVerifier
{
    public function __construct(
        private readonly CentralWalletContractCatalog $catalog,
        private readonly CentralWalletRuntimeManifestStore $runtimeManifestStore,
    ) {}

    /**
     * @return array{status: string, checks: list<array<string, mixed>>}
     */
    public function verifyProvider(): array
    {
        $checks = [];

        $checks[] = $this->checkContractFilesPresent();
        $checks[] = $this->checkContractVersionMatchesCatalog();
        $checks[] = $this->checkRequiredRoutesRegistered();
        $checks[] = $this->checkRuntimeManifestPresent();
        $checks[] = $this->checkRuntimeManifestContractVersion();
        $checks[] = $this->checkRuntimeFileHashes();
        $checks[] = $this->checkReleaseManifestDrift();

        return [
            'status' => $this->aggregateStatus($checks),
            'checks' => $checks,
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

        return 'PASS';
    }

    /**
     * @return array<string, mixed>
     */
    private function checkContractFilesPresent(): array
    {
        $contractExists = is_file($this->catalog->contractPath());
        $matrixExists = is_file($this->catalog->compatibilityMatrixPath());

        return [
            'id' => 'contract_files_present',
            'result' => $contractExists && $matrixExists ? 'PASS' : 'FAIL',
            'details' => [
                'contract' => $contractExists,
                'compatibility_matrix' => $matrixExists,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkContractVersionMatchesCatalog(): array
    {
        try {
            $contract = $this->catalog->loadContract();
            $this->catalog->assertContractVersion($contract);

            return [
                'id' => 'contract_version',
                'result' => 'PASS',
                'details' => ['contract_version' => $contract['contract_version'] ?? null],
            ];
        } catch (\Throwable $exception) {
            return [
                'id' => 'contract_version',
                'result' => 'FAIL',
                'details' => ['message' => $exception->getMessage()],
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkRequiredRoutesRegistered(): array
    {
        $missing = [];

        foreach ($this->catalog->requiredProviderRouteNames() as $routeName) {
            if (! Route::has($routeName)) {
                $missing[] = $routeName;
            }
        }

        return [
            'id' => 'required_routes_registered',
            'result' => $missing === [] ? 'PASS' : 'FAIL',
            'details' => [
                'missing_routes' => $missing,
                'wallet_visibility_present' => Route::has('central-wallet.wallet-visibility.show'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkRuntimeManifestPresent(): array
    {
        $manifest = $this->runtimeManifestStore->read();

        return [
            'id' => 'runtime_manifest_present',
            'result' => $manifest !== null ? 'PASS' : 'WARN',
            'details' => ['path' => $this->runtimeManifestStore->path()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkRuntimeManifestContractVersion(): array
    {
        $manifest = $this->runtimeManifestStore->read();

        if ($manifest === null) {
            return [
                'id' => 'runtime_manifest_contract_version',
                'result' => 'WARN',
                'details' => ['message' => 'runtime manifest missing'],
            ];
        }

        $matches = ($manifest['contract_version'] ?? null) === CentralWalletContractCatalog::VERSION;

        return [
            'id' => 'runtime_manifest_contract_version',
            'result' => $matches ? 'PASS' : 'FAIL',
            'details' => [
                'manifest_contract_version' => $manifest['contract_version'] ?? null,
                'expected' => CentralWalletContractCatalog::VERSION,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkRuntimeFileHashes(): array
    {
        $manifest = $this->runtimeManifestStore->read();

        if ($manifest === null) {
            return [
                'id' => 'runtime_file_hashes',
                'result' => 'WARN',
                'details' => ['message' => 'runtime manifest missing'],
            ];
        }

        $mismatches = [];

        foreach ($manifest['runtime_files'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $path = (string) ($entry['path'] ?? '');
            $expected = $entry['sha256'] ?? null;
            $absolute = base_path($path);
            $actual = is_file($absolute) ? hash_file('sha256', $absolute) : null;

            if ($expected !== $actual) {
                $mismatches[] = [
                    'path' => $path,
                    'expected' => $expected,
                    'actual' => $actual,
                    'exists' => is_file($absolute),
                ];
            }
        }

        return [
            'id' => 'runtime_file_hashes',
            'result' => $mismatches === [] ? 'PASS' : 'FAIL',
            'details' => ['mismatches' => $mismatches],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkReleaseManifestDrift(): array
    {
        $manifest = $this->runtimeManifestStore->read();

        if ($manifest === null) {
            return [
                'id' => 'release_manifest_drift',
                'result' => 'WARN',
                'details' => ['message' => 'runtime manifest missing'],
            ];
        }

        $deploymentType = (string) ($manifest['deployment_type'] ?? '');
        $sourceCommit = (string) ($manifest['overlay']['source_commit'] ?? '');
        $releaseBuild = (string) ($manifest['release_manifest']['build'] ?? '');

        if ($deploymentType !== 'overlay' || $sourceCommit === '' || $releaseBuild === '') {
            return [
                'id' => 'release_manifest_drift',
                'result' => 'PASS',
                'details' => ['message' => 'not an overlay deployment or insufficient metadata'],
            ];
        }

        $drift = $sourceCommit !== $releaseBuild;

        return [
            'id' => 'release_manifest_drift',
            'result' => $drift ? 'WARN' : 'PASS',
            'details' => [
                'overlay_source_commit' => $sourceCommit,
                'release_json_build' => $releaseBuild,
                'note' => 'Overlay deployments may intentionally differ from release.json; WARN not FAIL.',
            ],
        ];
    }
}

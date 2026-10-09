<?php

namespace App\CentralWallet\Reliability;

final class CentralWalletReleaseGateRunner
{
    public function __construct(
        private readonly CentralWalletReleaseGateConfiguration $configuration,
        private readonly CentralWalletContractCatalog $catalog,
        private readonly CentralWalletDeploymentDriftVerifier $driftVerifier,
        private readonly CentralWalletRuntimeManifestStore $manifestStore,
        private readonly CentralWalletSemanticInvariantVerifier $semanticVerifier,
        private readonly CentralWalletSyntheticWalletProbe $syntheticProbe,
        private readonly CentralWalletAccountLinkReconciliationReader $reconciliationReader,
        private readonly CentralWalletAccountLinkVarianceEvaluator $varianceEvaluator,
        private readonly CentralWalletOverlayIntegrityVerifier $overlayIntegrityVerifier,
        private readonly CentralWalletOverlayCompatibilityVerifier $overlayCompatibilityVerifier,
        private readonly CentralWalletCustomerDisplaySynthetic $customerDisplaySynthetic,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(string $phase = 'pre'): array
    {
        $sections = [
            'repository' => $this->checkRepository($phase),
            'contract' => $this->checkContract(),
            'routes' => $this->checkRoutes(),
            'runtime_manifest' => $this->checkRuntimeManifest($phase),
            'overlay_integrity' => $this->checkOverlayIntegrity($phase),
            'overlay_compatibility' => $this->checkOverlayCompatibility($phase),
            'production_dependency_closure' => $this->checkProductionDependencyClosure($phase),
            'deployment_drift' => $this->checkDeploymentDrift($phase),
            'authentication' => $this->checkAuthentication($phase),
            'semantic_invariants' => $this->wrapSection($this->semanticVerifier->verifyProvider()),
            'failure_semantics' => $this->checkFailureSemantics(),
            'account_link_variance' => $this->checkAccountLinkVariance($phase),
            'synthetic_wallet' => $this->wrapSection($this->syntheticProbe->probe($this->configuration->syntheticProbe())),
            'customer_display' => $this->wrapSection($this->customerDisplaySynthetic->probe()),
        ];

        $final = $this->resolveFinal($sections);

        return [
            'project' => $this->configuration->projectKey(),
            'role' => $this->configuration->role(),
            'phase' => $phase,
            'generated_at' => gmdate('c'),
            'read_only' => true,
            'sections' => $sections,
            'final' => $final['status'],
            'final_message' => $final['message'],
        ];
    }

    /**
     * @return array{status: string, checks: list<array<string, mixed>>}
     */
    private function checkRepository(string $phase): array
    {
        $manifest = $this->manifestStore->read();
        $hasSha = is_array($manifest) && trim((string) ($manifest['git_sha'] ?? ($manifest['git']['sha'] ?? ''))) !== '';

        $result = $phase === 'post' && ! $hasSha ? 'WARN' : ($hasSha ? 'PASS' : 'PASS');

        return [
            'status' => $result,
            'checks' => [[
                'id' => 'repository_version',
                'result' => $result,
                'details' => [
                    'git_sha' => $manifest['git_sha'] ?? null,
                    'branch' => $manifest['git_branch'] ?? null,
                    'contract_version' => CentralWalletContractCatalog::VERSION,
                ],
            ]],
        ];
    }

    /**
     * @return array{status: string, checks: list<array<string, mixed>>}
     */
    private function checkContract(): array
    {
        $checks = [];
        $matrix = $this->catalog->loadCompatibilityMatrix();
        $provider = $matrix['providers']['radium-desk'] ?? null;

        $checks[] = [
            'id' => 'provider_contract_version',
            'result' => ($provider['implements_contract'] ?? null) === CentralWalletContractCatalog::VERSION ? 'PASS' : 'FAIL',
            'details' => ['implements_contract' => $provider['implements_contract'] ?? null],
        ];

        foreach ($matrix['consumers'] ?? [] as $site => $consumer) {
            $checks[] = [
                'id' => 'consumer_requires_'.$site,
                'result' => ($consumer['requires_contract'] ?? null) === CentralWalletContractCatalog::VERSION ? 'PASS' : 'FAIL',
                'details' => ['site' => $site],
            ];
        }

        return ['status' => $this->aggregateChecks($checks), 'checks' => $checks];
    }

    /**
     * @return array{status: string, checks: list<array<string, mixed>>}
     */
    private function checkRoutes(): array
    {
        $drift = $this->driftVerifier->verifyProvider();
        $routeCheck = null;

        foreach ($drift['checks'] ?? [] as $check) {
            if (($check['id'] ?? '') === 'required_routes_registered') {
                $routeCheck = $check;
            }
        }

        return [
            'status' => ($routeCheck['result'] ?? 'FAIL') === 'PASS' ? 'PASS' : 'FAIL',
            'checks' => [$routeCheck ?? ['id' => 'required_routes_registered', 'result' => 'FAIL', 'details' => []]],
        ];
    }

    /**
     * @return array{status: string, checks: list<array<string, mixed>>}
     */
    private function checkRuntimeManifest(string $phase): array
    {
        $manifest = $this->manifestStore->read();

        if ($manifest === null) {
            return [
                'status' => $phase === 'post' ? 'FAIL' : 'WARN',
                'checks' => [[
                    'id' => 'runtime_manifest_present',
                    'result' => $phase === 'post' ? 'FAIL' : 'WARN',
                    'details' => ['path' => $this->manifestStore->path()],
                ]],
            ];
        }

        $required = ['project', 'contract_version'];
        $missing = [];

        foreach ($required as $key) {
            if (! array_key_exists($key, $manifest) || trim((string) $manifest[$key]) === '') {
                $missing[] = $key;
            }
        }

        if ((int) ($manifest['schema_version'] ?? 0) >= 2) {
            foreach (['target_environment', 'release_identity', 'managed_files', 'source_identity'] as $key) {
                if (! array_key_exists($key, $manifest)) {
                    $missing[] = $key;
                }
            }
        } elseif (! array_key_exists('deployed_at', $manifest)) {
            $missing[] = 'deployed_at';
        }

        $gitSha = trim((string) (
            $manifest['source_identity']['primary_source_commit']
            ?? $manifest['git_sha']
            ?? ($manifest['git']['sha'] ?? '')
        ));
        if ($gitSha === '') {
            $missing[] = 'git_sha';
        }

        return [
            'status' => $missing === [] ? 'PASS' : 'FAIL',
            'checks' => [[
                'id' => 'runtime_manifest_fields',
                'result' => $missing === [] ? 'PASS' : 'FAIL',
                'details' => ['missing' => $missing, 'deployment_type' => $manifest['deployment_type'] ?? null],
            ]],
        ];
    }

    /**
     * @return array{status: string, checks: list<array<string, mixed>>}
     */
    /**
     * @return array{status: string, checks: list<array<string, mixed>>}
     */
    private function checkOverlayCompatibility(string $phase): array
    {
        if (! $this->configuration->overlayCompatibilityEnabled()) {
            return [
                'status' => 'WARN',
                'checks' => [[
                    'id' => 'overlay_compatibility_disabled',
                    'result' => 'WARN',
                    'details' => ['message' => 'Overlay compatibility gate disabled'],
                ]],
            ];
        }

        $baseline = $this->configuration->overlayCompatibilityBaselineRoot();
        $target = $this->configuration->overlayCompatibilityTargetRoot() ?? base_path();

        if ($baseline === null) {
            $catalog = app(CentralWalletProductionDependencyCatalog::class);
            $contract = $catalog->load();
            $fixtureRelative = trim((string) ($contract['overlay_baseline_fixture_root'] ?? ''), '/');
            $baseline = $fixtureRelative !== '' && is_dir(base_path($fixtureRelative))
                ? base_path($fixtureRelative)
                : null;
        }

        if ($baseline === null) {
            return [
                'status' => $phase === 'post' ? 'WARN' : 'WARN',
                'checks' => [[
                    'id' => 'overlay_compatibility_baseline',
                    'result' => 'WARN',
                    'details' => ['message' => 'Overlay baseline root not configured'],
                ]],
            ];
        }

        $report = $this->overlayCompatibilityVerifier->compareProjectRoots($baseline, $target);

        return [
            'status' => (string) ($report['status'] ?? 'FAIL'),
            'checks' => $report['checks'] ?? [],
        ];
    }

    private function checkProductionDependencyClosure(string $phase): array
    {
        if ($phase === 'pre') {
            return [
                'status' => 'PASS',
                'checks' => [[
                    'id' => 'production_dependency_closure_deferred',
                    'result' => 'PASS',
                    'details' => ['message' => 'Post-deploy runtime closure verification'],
                ]],
            ];
        }

        $report = $this->overlayCompatibilityVerifier->verifyRuntimeClosure(base_path());

        return [
            'status' => $report['status'],
            'checks' => $report['checks'],
        ];
    }

    private function checkOverlayIntegrity(string $phase): array
    {
        $manifest = $this->manifestStore->read();

        if ($manifest === null) {
            return [
                'status' => $phase === 'post' ? 'FAIL' : 'WARN',
                'checks' => [[
                    'id' => 'overlay_integrity_deferred',
                    'result' => $phase === 'post' ? 'FAIL' : 'WARN',
                    'details' => ['message' => 'runtime manifest absent'],
                ]],
            ];
        }

        $report = $this->overlayIntegrityVerifier->verify($manifest);
        $status = (string) ($report['status'] ?? 'FAIL');

        if ($phase === 'pre' && in_array($status, ['WARN', 'UNVERIFIABLE'], true)) {
            $status = 'PASS';
        }

        return [
            'status' => $status,
            'checks' => $report['checks'] ?? [],
        ];
    }

    /**
     * @return array{status: string, checks: list<array<string, mixed>>}
     */
    private function checkDeploymentDrift(string $phase): array
    {
        $drift = $this->driftVerifier->verifyProvider();
        $status = (string) ($drift['status'] ?? 'FAIL');

        if ($phase === 'post' && $status === 'WARN') {
            $hasOnlyOverlayWarn = true;
            foreach ($drift['checks'] ?? [] as $check) {
                if (($check['result'] ?? '') === 'WARN' && ($check['id'] ?? '') !== 'release_manifest_drift' && ($check['id'] ?? '') !== 'runtime_manifest_present') {
                    $hasOnlyOverlayWarn = false;
                }
                if (($check['result'] ?? '') === 'FAIL') {
                    $hasOnlyOverlayWarn = false;
                }
            }

            if ($hasOnlyOverlayWarn) {
                $status = 'PASS';
            }
        }

        if ($phase === 'post' && $status === 'WARN') {
            foreach ($drift['checks'] ?? [] as $check) {
                if (in_array($check['id'] ?? '', ['runtime_manifest_present', 'runtime_file_hashes'], true) && ($check['result'] ?? '') === 'WARN') {
                    $status = 'FAIL';
                }
            }
        }

        return ['status' => $status, 'checks' => $drift['checks'] ?? []];
    }

    /**
     * @return array{status: string, checks: list<array<string, mixed>>}
     */
    private function checkAuthentication(string $phase): array
    {
        $probeConfig = $this->configuration->syntheticProbe();

        if (! $probeConfig['configured']) {
            return [
                'status' => 'BLOCKED',
                'checks' => [[
                    'id' => 'authentication_fixture',
                    'result' => 'BLOCKED',
                    'details' => ['message' => 'FIXTURE NOT CONFIGURED'],
                ]],
            ];
        }

        $probe = $this->syntheticProbe->probe($probeConfig);
        $authChecks = array_values(array_filter(
            $probe['checks'] ?? [],
            static fn (array $check): bool => in_array($check['id'] ?? '', ['unauthenticated', 'site_mismatch', 'route_present'], true),
        ));

        return [
            'status' => $this->aggregateChecks($authChecks),
            'checks' => $authChecks,
        ];
    }

    /**
     * @return array{status: string, checks: list<array<string, mixed>>}
     */
    private function checkFailureSemantics(): array
    {
        $semantic = $this->semanticVerifier->verifyProvider();
        $checks = array_values(array_filter(
            $semantic['checks'] ?? [],
            static fn (array $check): bool => in_array($check['id'] ?? '', [
                'unavailable_not_zero_contract',
                'authoritative_zero_distinct',
                'unverified_positive_not_zero',
            ], true),
        ));

        return ['status' => $this->aggregateChecks($checks), 'checks' => $checks];
    }

    /**
     * @return array{status: string, checks: list<array<string, mixed>>}
     */
    private function checkAccountLinkVariance(string $phase): array
    {
        if (! $this->configuration->accountLinkReconciliationEnabled()) {
            return [
                'status' => $phase === 'post' ? 'BLOCKED' : 'WARN',
                'checks' => [[
                    'id' => 'account_link_reconciliation_configured',
                    'result' => $phase === 'post' ? 'BLOCKED' : 'WARN',
                    'details' => ['message' => 'Account-link reconciliation paths not configured'],
                ]],
            ];
        }

        $deskEnv = $this->configuration->deskEnvPath();
        $spokeEnv = $this->configuration->spokeEnvPath();

        if ($deskEnv === null || $spokeEnv === null) {
            return [
                'status' => 'BLOCKED',
                'checks' => [[
                    'id' => 'account_link_env_paths',
                    'result' => 'BLOCKED',
                    'details' => ['message' => 'desk_env_path and spoke_env_path required'],
                ]],
            ];
        }

        try {
            $siteCode = $this->configuration->syntheticProbe()['site_code'] ?: 'rdservice.in';
            $reconciliation = $this->reconciliationReader->reconcileFromEnvPaths($deskEnv, $spokeEnv, $siteCode);
            $evaluation = $this->varianceEvaluator->evaluate($reconciliation);
        } catch (\Throwable $exception) {
            return [
                'status' => 'FAIL',
                'checks' => [[
                    'id' => 'account_link_reconciliation_read',
                    'result' => 'FAIL',
                    'details' => ['message' => $exception->getMessage()],
                ]],
            ];
        }

        $gateStatus = match ($evaluation['status']) {
            'FAIL' => 'FAIL',
            'NON-BLOCKING' => 'NON-BLOCKING',
            default => 'PASS',
        };

        return [
            'status' => $gateStatus,
            'checks' => [[
                'id' => 'account_link_variance',
                'result' => $evaluation['status'] === 'FAIL' ? 'FAIL' : 'PASS',
                'details' => $evaluation,
            ]],
        ];
    }

    /**
     * @param  array{status: string, checks: list<array<string, mixed>>}  $section
     * @return array{status: string, checks: list<array<string, mixed>>}
     */
    private function wrapSection(array $section): array
    {
        return [
            'status' => $section['status'] ?? 'FAIL',
            'checks' => $section['checks'] ?? [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     */
    private function aggregateChecks(array $checks): string
    {
        foreach ($checks as $check) {
            if (($check['result'] ?? '') === 'BLOCKED') {
                return 'BLOCKED';
            }
        }

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
     * @param  array<string, array{status: string}>  $sections
     * @return array{status: string, message: string}
     */
    private function resolveFinal(array $sections): array
    {
        foreach ($sections as $section) {
            if (($section['status'] ?? '') === 'BLOCKED') {
                return [
                    'status' => 'BLOCKED',
                    'message' => 'BLOCKED — FIXTURE OR RECONCILIATION NOT CONFIGURED',
                ];
            }
        }

        foreach ($sections as $section) {
            if (($section['status'] ?? '') === 'FAIL') {
                return ['status' => 'FAIL', 'message' => ''];
            }
        }

        foreach ($sections as $section) {
            if (($section['status'] ?? '') === 'WARN') {
                return ['status' => 'WARN', 'message' => ''];
            }
        }

        return ['status' => 'PASS', 'message' => ''];
    }
}

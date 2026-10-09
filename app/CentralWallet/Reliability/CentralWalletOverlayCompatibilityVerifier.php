<?php

namespace App\CentralWallet\Reliability;

final class CentralWalletOverlayCompatibilityVerifier
{
    public function __construct(
        private readonly CentralWalletProductionDependencyCatalog $dependencyCatalog,
        private readonly CentralWalletEffectiveConfigResolver $configResolver,
        private readonly CentralWalletServiceProviderRegistrationExtractor $registrationExtractor,
    ) {}

    /**
     * Compare baseline (production / overlay reference) against target (proposed release tree).
     *
     * @return array{status: string, checks: list<array<string, mixed>>, findings: list<array<string, mixed>>}
     */
    public function compareProjectRoots(string $baselineRoot, string $targetRoot): array
    {
        $contract = $this->dependencyCatalog->load();
        $checks = [];
        $findings = [];

        $baselineConfigPath = rtrim($baselineRoot, '/').'/config/central_wallet.php';
        $targetConfigPath = rtrim($targetRoot, '/').'/config/central_wallet.php';
        $baselineProviderPath = rtrim($baselineRoot, '/').'/app/Providers/CentralWalletServiceProvider.php';
        $targetProviderPath = rtrim($targetRoot, '/').'/app/Providers/CentralWalletServiceProvider.php';

        foreach ($contract['config_scenarios'] ?? [] as $scenario) {
            $scenarioId = (string) ($scenario['id'] ?? 'default');
            /** @var array<string, string|null> $env */
            $env = $scenario['env'] ?? [];

            try {
                $baselineConfig = $this->configResolver->loadConfigArray($baselineConfigPath, $env);
                $targetConfig = $this->configResolver->loadConfigArray($targetConfigPath, $env);
            } catch (\Throwable $exception) {
                $checks[] = [
                    'id' => 'config_scenario_'.$scenarioId,
                    'result' => 'FAIL',
                    'details' => ['message' => $exception->getMessage()],
                ];
                $findings[] = [
                    'category' => 'config_load_error',
                    'scenario' => $scenarioId,
                    'change' => 'UNKNOWN',
                ];
                continue;
            }

            foreach ($scenario['required_paths'] ?? [] as $requiredPath) {
                $path = (string) ($requiredPath['path'] ?? '');
                $classification = (string) ($requiredPath['classification'] ?? 'VERIFIED');
                $baselineExists = $this->configResolver->pathExists($baselineConfig, $path);
                $targetExists = $this->configResolver->pathExists($targetConfig, $path);
                $baselineValue = $this->configResolver->dotGet($baselineConfig, $path);
                $targetValue = $this->configResolver->dotGet($targetConfig, $path);

                $change = 'PRESERVED';
                $result = 'PASS';

                if ($baselineExists && ! $targetExists) {
                    $change = 'REMOVED';
                    $result = $classification === 'VERIFIED' ? 'FAIL' : 'WARN';
                } elseif (! $baselineExists && $targetExists) {
                    $change = 'ADDED';
                } elseif ($baselineExists && $targetExists) {
                    $expectedType = $requiredPath['required_type'] ?? null;
                    if ($expectedType === 'boolean' && is_bool($baselineValue) && ! is_bool($targetValue)) {
                        $change = 'CHANGED';
                        $result = 'FAIL';
                    } elseif ($this->requiresResolvableBoolean($requiredPath, $env)) {
                        $baselineBool = filter_var($baselineValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                        $targetBool = filter_var($targetValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                        if ($baselineBool === true && $targetBool !== true) {
                            $change = 'CHANGED';
                            $result = 'FAIL';
                        }
                    }
                } elseif ($classification === 'VERIFIED' && ($requiredPath['required_in_target'] ?? false)) {
                    $change = 'REMOVED';
                    $result = 'FAIL';
                }

                $checks[] = [
                    'id' => 'config_path_'.$scenarioId.'_'.str_replace('.', '_', $path),
                    'result' => $result,
                    'details' => [
                        'path' => $path,
                        'scenario' => $scenarioId,
                        'change' => $change,
                        'classification' => $classification,
                        'baseline_present' => $baselineExists,
                        'target_present' => $targetExists,
                    ],
                ];

                if ($result === 'FAIL') {
                    $findings[] = [
                        'category' => 'config_path',
                        'path' => $path,
                        'scenario' => $scenarioId,
                        'change' => $change,
                    ];
                }
            }
        }

        $baselineRegistrations = $this->registrationExtractor->extract($baselineProviderPath);
        $targetRegistrations = $this->registrationExtractor->extract($targetProviderPath);

        foreach ($contract['required_bindings'] ?? [] as $binding) {
            $class = ltrim((string) ($binding['class'] ?? ''), '\\');
            $classification = (string) ($binding['classification'] ?? 'VERIFIED');
            $baselineHas = $this->registrationsInclude($baselineRegistrations, $class);
            $targetHas = $this->registrationsInclude($targetRegistrations, $class);

            $change = 'PRESERVED';
            $result = 'PASS';

            if ($baselineHas && ! $targetHas) {
                $change = 'REMOVED';
                $result = $classification === 'VERIFIED' ? 'FAIL' : 'WARN';
            } elseif (! $baselineHas && $targetHas) {
                $change = 'ADDED';
            } elseif (($binding['required_in_target'] ?? true) && ! $targetHas) {
                $change = 'REMOVED';
                $result = $classification === 'VERIFIED' ? 'FAIL' : 'WARN';
            }

            $checks[] = [
                'id' => 'binding_'.str_replace('\\', '_', $class),
                'result' => $result,
                'details' => [
                    'class' => $class,
                    'change' => $change,
                    'classification' => $classification,
                    'baseline_registered' => $baselineHas,
                    'target_registered' => $targetHas,
                ],
            ];

            if ($result === 'FAIL') {
                $findings[] = [
                    'category' => 'binding',
                    'class' => $class,
                    'change' => $change,
                ];
            }
        }

        foreach ($contract['required_capabilities'] ?? [] as $capability) {
            $capId = (string) ($capability['id'] ?? '');
            $classification = (string) ($capability['classification'] ?? 'VERIFIED');
            $result = $this->evaluateCapability($capability, $targetConfigPath, $targetProviderPath, $targetRegistrations);
            $checks[] = [
                'id' => 'capability_'.$capId,
                'result' => $result,
                'details' => [
                    'capability' => $capId,
                    'classification' => $classification,
                ],
            ];

            if ($result === 'FAIL') {
                $findings[] = [
                    'category' => 'capability',
                    'capability' => $capId,
                    'change' => 'REMOVED',
                ];
            }
        }

        return [
            'status' => $this->aggregateStatus($checks),
            'checks' => $checks,
            'findings' => $findings,
        ];
    }

    /**
     * Verify live runtime (target root = application base path).
     *
     * @return array{status: string, checks: list<array<string, mixed>>}
     */
    public function verifyRuntimeClosure(string $projectRoot): array
    {
        $contract = $this->dependencyCatalog->load();
        $baselineRoot = $this->resolveBaselineRoot($contract, $projectRoot);

        if ($baselineRoot === null) {
            return [
                'status' => 'WARN',
                'checks' => [[
                    'id' => 'overlay_baseline_unconfigured',
                    'result' => 'WARN',
                    'details' => ['message' => 'No overlay baseline configured; runtime self-check only'],
                ]],
            ];
        }

        $report = $this->compareProjectRoots($baselineRoot, $projectRoot);

        return [
            'status' => $report['status'],
            'checks' => $report['checks'],
        ];
    }

    /**
     * @param  array<string, mixed>  $requiredPath
     * @param  array<string, string|null>  $env
     */
    private function requiresResolvableBoolean(array $requiredPath, array $env): bool
    {
        if (! filter_var($requiredPath['require_resolvable_when_env_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        $envKey = (string) ($requiredPath['env_enable_key'] ?? '');
        if ($envKey === '') {
            return false;
        }

        return filter_var($env[$envKey] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  array<string, mixed>  $capability
     * @param  list<string>  $targetRegistrations
     */
    private function evaluateCapability(
        array $capability,
        string $targetConfigPath,
        string $targetProviderPath,
        array $targetRegistrations,
    ): string {
        $requiresBindings = $capability['requires_bindings'] ?? [];
        foreach ($requiresBindings as $class) {
            if (! $this->registrationsInclude($targetRegistrations, (string) $class)) {
                return 'FAIL';
            }
        }

        $scenario = $capability['config_scenario'] ?? null;
        if (is_array($scenario)) {
            $env = $scenario['env'] ?? [];
            try {
                $targetConfig = $this->configResolver->loadConfigArray($targetConfigPath, $env);
            } catch (\Throwable) {
                return 'FAIL';
            }

            foreach ($scenario['required_paths'] ?? [] as $path) {
                $dotPath = is_array($path) ? (string) ($path['path'] ?? '') : (string) $path;
                if (! $this->configResolver->pathExists($targetConfig, $dotPath)) {
                    return 'FAIL';
                }
            }
        }

        return 'PASS';
    }

    /**
     * @param  array<string, mixed>  $contract
     */
    private function resolveBaselineRoot(array $contract, string $projectRoot): ?string
    {
        $configured = trim((string) config('central_wallet.release_gate.overlay_compatibility.baseline_root', ''));
        if ($configured !== '' && is_dir($configured)) {
            return $configured;
        }

        $fixtureRelative = trim((string) ($contract['overlay_baseline_fixture_root'] ?? ''), '/');
        if ($fixtureRelative === '') {
            return $projectRoot;
        }

        $fixturePath = $projectRoot.'/'.$fixtureRelative;

        return is_dir($fixturePath) ? $fixturePath : null;
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     */
    /**
     * @param  list<string>  $registrations
     */
    private function registrationsInclude(array $registrations, string $class): bool
    {
        $class = ltrim($class, '\\');
        $short = class_basename($class);

        if (in_array($class, $registrations, true) || in_array($short, $registrations, true)) {
            return true;
        }

        foreach ($registrations as $registered) {
            if (str_ends_with($class, '\\'.$registered) || $registered === $short) {
                return true;
            }
        }

        return false;
    }

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
}

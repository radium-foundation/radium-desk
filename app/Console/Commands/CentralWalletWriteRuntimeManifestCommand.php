<?php

namespace App\Console\Commands;

use App\CentralWallet\Reliability\CentralWalletRuntimeManifestBuilder;
use App\CentralWallet\Reliability\CentralWalletRuntimeManifestStore;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

final class CentralWalletWriteRuntimeManifestCommand extends Command
{
    protected $signature = 'central-wallet:write-runtime-manifest
                            {--deployment-type=git : git or overlay}
                            {--project=radium-desk : Project identifier}
                            {--environment= : Override APP_ENV}
                            {--git-sha= : Git SHA for this deployment}
                            {--git-branch= : Git branch if known}
                            {--overlay-prompt-id= : Overlay prompt/deployment ID}
                            {--overlay-deployment-id= : Overlay deployment identifier}
                            {--source-commit= : Source commit for overlay files}
                            {--rollback-reference= : Rollback artifact/reference}
                            {--file=* : Runtime file path relative to project root}';

    protected $description = 'Write Central Wallet runtime deployment manifest (coexists with release.json)';

    public function handle(
        CentralWalletRuntimeManifestBuilder $builder,
        CentralWalletRuntimeManifestStore $store,
    ): int {
        $files = $this->option('file');
        if (! is_array($files) || $files === []) {
            $files = $builder->defaultDeskRuntimeFiles();
        }

        $manifest = $builder->build(
            project: (string) $this->option('project'),
            environment: (string) ($this->option('environment') ?: config('app.env', 'production')),
            deploymentType: (string) $this->option('deployment-type'),
            runtimeFiles: array_values(array_map('strval', $files)),
            gitSha: $this->nullableOption('git-sha'),
            gitBranch: $this->nullableOption('git-branch'),
            overlayPromptId: $this->nullableOption('overlay-prompt-id'),
            overlayDeploymentId: $this->nullableOption('overlay-deployment-id'),
            sourceCommit: $this->nullableOption('source-commit'),
            rollbackReference: $this->nullableOption('rollback-reference'),
        );

        $store->write($manifest);

        $this->info('Runtime manifest written to '.$store->path());
        $this->line('contract_version='.$manifest['contract_version']);
        $this->line('runtime_files='.count($manifest['runtime_files'] ?? []));

        return SymfonyCommand::SUCCESS;
    }

    private function nullableOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}

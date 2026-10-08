<?php

namespace App\Console\Commands;

use App\CentralWallet\Reliability\CentralWalletGitWorktreeInspector;
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
                            {--source-component=* : Additional source component as commit:label}
                            {--rollback-reference= : Rollback artifact/reference}
                            {--require-clean-worktree : Fail if git worktree is dirty}';

    protected $description = 'Write Central Wallet runtime deployment manifest v2 (deterministic release identity)';

    public function handle(
        CentralWalletRuntimeManifestBuilder $builder,
        CentralWalletRuntimeManifestStore $store,
        CentralWalletGitWorktreeInspector $gitInspector,
    ): int {
        if ($this->option('require-clean-worktree') && ! $gitInspector->isClean()) {
            $this->error('Git worktree is dirty; resolve changes or omit --require-clean-worktree.');

            return SymfonyCommand::FAILURE;
        }

        $gitSha = $this->nullableOption('git-sha') ?? $gitInspector->headSha();
        $gitBranch = $this->nullableOption('git-branch') ?? $gitInspector->branch();

        $manifest = $builder->build(
            project: (string) $this->option('project'),
            environment: (string) ($this->option('environment') ?: config('app.env', 'production')),
            deploymentType: (string) $this->option('deployment-type'),
            gitSha: $gitSha,
            gitBranch: $gitBranch,
            overlayPromptId: $this->nullableOption('overlay-prompt-id'),
            overlayDeploymentId: $this->nullableOption('overlay-deployment-id'),
            sourceCommit: $this->nullableOption('source-commit'),
            rollbackReference: $this->nullableOption('rollback-reference'),
            gitWorktreeClean: $gitInspector->isClean(),
            sourceComponents: $this->parseSourceComponents(),
        );

        $store->write($manifest);

        $this->info('Runtime manifest written to '.$store->path());
        $this->line('schema_version='.($manifest['schema_version'] ?? null));
        $this->line('release_identity='.($manifest['release_identity'] ?? null));
        $this->line('managed_file_count='.($manifest['managed_file_count'] ?? 0));

        return SymfonyCommand::SUCCESS;
    }

    /**
     * @return list<array{commit: string, label: string}>
     */
    private function parseSourceComponents(): array
    {
        $components = [];
        $raw = $this->option('source-component');

        if (! is_array($raw)) {
            return $components;
        }

        foreach ($raw as $entry) {
            if (! is_string($entry) || trim($entry) === '') {
                continue;
            }

            [$commit, $label] = array_pad(explode(':', $entry, 2), 2, 'component');
            $commit = trim($commit);
            $label = trim($label);

            if ($commit === '') {
                continue;
            }

            $components[] = [
                'commit' => $commit,
                'label' => $label !== '' ? $label : 'component',
            ];
        }

        return $components;
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

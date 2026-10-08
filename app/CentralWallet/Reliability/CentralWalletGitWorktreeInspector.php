<?php

namespace App\CentralWallet\Reliability;

final class CentralWalletGitWorktreeInspector
{
    public function headSha(): ?string
    {
        $sha = $this->runGit('rev-parse HEAD');

        return $sha !== '' ? $sha : null;
    }

    public function branch(): ?string
    {
        $branch = $this->runGit('rev-parse --abbrev-ref HEAD');

        if ($branch === '' || $branch === 'HEAD') {
            return null;
        }

        return $branch;
    }

    public function isClean(): bool
    {
        return trim($this->runGit('status --porcelain')) === '';
    }

    public function porcelainStatus(): string
    {
        return $this->runGit('status --porcelain');
    }

    private function runGit(string $arguments): string
    {
        $basePath = base_path();
        $command = sprintf('git -C %s %s 2>/dev/null', escapeshellarg($basePath), $arguments);
        $output = shell_exec($command);

        return is_string($output) ? trim($output) : '';
    }
}

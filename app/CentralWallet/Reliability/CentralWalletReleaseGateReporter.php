<?php

namespace App\CentralWallet\Reliability;

final class CentralWalletReleaseGateReporter
{
    /**
     * @param  array<string, mixed>  $report
     */
    public function toHuman(array $report): string
    {
        $lines = [
            'CENTRAL WALLET RELEASE GATE',
            str_repeat('-', 28),
            'Project: '.($report['project'] ?? 'unknown'),
            'Role: '.($report['role'] ?? 'unknown'),
            'Phase: '.($report['phase'] ?? 'pre'),
            '',
        ];

        foreach ($report['sections'] ?? [] as $name => $section) {
            $lines[] = sprintf('%s: %s', $this->label($name), $section['status'] ?? 'UNKNOWN');
        }

        $lines[] = '';
        $lines[] = 'FINAL: '.($report['final'] ?? 'FAIL');

        if (($report['final_message'] ?? '') !== '') {
            $lines[] = $report['final_message'];
        }

        $lines[] = '';
        $lines[] = 'THIS GATE DOES NOT AUTHORIZE DEPLOYMENT.';

        return implode(PHP_EOL, $lines);
    }

    private function label(string $name): string
    {
        return match ($name) {
            'repository' => 'Repository/version',
            'contract' => 'Contract',
            'runtime_manifest' => 'Runtime manifest',
            'deployment_drift' => 'Deployment drift',
            'routes' => 'Routes',
            'authentication' => 'Authentication',
            'semantic_invariants' => 'Semantic invariants',
            'account_link_variance' => 'Account-link variance',
            'synthetic_wallet' => 'Synthetic wallet',
            'failure_semantics' => 'Failure semantics',
            default => $name,
        };
    }
}

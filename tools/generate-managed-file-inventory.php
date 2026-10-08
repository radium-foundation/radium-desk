<?php

declare(strict_types=1);

$project = $argv[1] ?? 'radium-desk';
$role = $argv[2] ?? 'provider';
$releaseBranch = $argv[3] ?? 'main';

$lists = [
    'radium-desk' => <<<'LIST'
app/CentralWallet/Reliability
routes/central_wallet.php
app/CentralWallet/Application/WalletVisibilityService.php
app/CentralWallet/Infrastructure/Http/Controllers/WalletVisibilityController.php
app/Providers/CentralWalletServiceProvider.php
config/central_wallet.php
contracts/central-wallet/v1
app/Console/Commands/CentralWalletVerifyReleaseGateCommand.php
app/Console/Commands/CentralWalletWriteRuntimeManifestCommand.php
app/Console/Commands/CentralWalletVerifyDeploymentDriftCommand.php
app/Console/Commands/CentralWalletVerifyOverlayIntegrityCommand.php
tools/commands/deploy-central-wallet-prod-gate.sh
tools/commands/central-wallet-runtime-manifest.sh
tools/commands/central-wallet-release-gate.sh
tools/commands/central-wallet-release-gate-all.sh
LIST,
    'rdservice.in' => <<<'LIST'
app/CentralWallet/Reliability
app/CentralWallet/Support/CentralWalletSpokeFailureSemantics.php
app/CentralWallet/Support/CentralWalletSpokeTrustPolicy.php
app/CentralWallet/Support/CentralWalletSpokeStateModel.php
app/CentralWallet/Support/TrustedVerificationMethod.php
app/Providers/CentralWalletServiceProvider.php
config/central_wallet.php
contracts/central-wallet/v1
app/Console/Commands/CentralWalletVerifyReleaseGateCommand.php
app/Console/Commands/CentralWalletWriteRuntimeManifestCommand.php
app/Console/Commands/CentralWalletVerifyDeploymentDriftCommand.php
app/Console/Commands/CentralWalletVerifyOverlayIntegrityCommand.php
LIST,
    'consumer-minimal' => <<<'LIST'
app/CentralWallet/Reliability
app/CentralWallet/Support/CentralWalletSpokeFailureSemantics.php
app/CentralWallet/Support/CentralWalletSpokeTrustPolicy.php
app/CentralWallet/Support/CentralWalletSpokeStateModel.php
app/CentralWallet/Support/TrustedVerificationMethod.php
app/CentralWallet/Services/CentralWalletHttpClient.php
app/CentralWallet/Services/CentralWalletBalanceReadService.php
app/CentralWallet/Support/CentralWalletVisibilityData.php
app/CentralWallet/Support/CentralWalletBalanceReadResult.php
app/Providers/CentralWalletServiceProvider.php
config/central_wallet.php
contracts/central-wallet/v1
app/Console/Commands/CentralWalletVerifyReleaseGateCommand.php
app/Console/Commands/CentralWalletWriteRuntimeManifestCommand.php
app/Console/Commands/CentralWalletVerifyDeploymentDriftCommand.php
app/Console/Commands/CentralWalletVerifyOverlayIntegrityCommand.php
LIST,
];

$key = in_array($project, ['radiumbox.com', 'rdservice.net'], true) ? 'consumer-minimal' : $project;
$lines = array_filter(array_map('trim', explode("\n", $lists[$key] ?? '')));
$files = [];

foreach ($lines as $line) {
    if ($line === '') {
        continue;
    }

    if (is_dir($line)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($line, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }
        continue;
    }

    if (is_dir(dirname($line)) && basename($line) === 'v1') {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($line, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }
        continue;
    }

    $files[] = $line;
}

$files = array_values(array_unique($files));
sort($files);

$entries = array_map(static function (string $path): array {
    return [
        'path' => $path,
        'classification' => str_ends_with($path, '.sh') ? 'deployment_support_file' : 'tracked_release_file',
    ];
}, $files);

$inventory = [
    'inventory_version' => '1.0.0',
    'project' => $project,
    'role' => $role,
    'release_branch' => $releaseBranch,
    'managed_scan_roots' => [[
        'path' => 'app/CentralWallet/Reliability',
        'extensions' => ['php'],
        'purpose' => 'Reliability and release-gate runtime code',
    ]],
    'files' => $entries,
];

$target = 'contracts/central-wallet/v1/managed-file-inventory.json';
file_put_contents($target, json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
fwrite(STDOUT, 'Wrote '.count($entries)." files to {$target}\n");

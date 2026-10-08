<?php

namespace App\CentralWallet\Reliability;

use PDO;
use RuntimeException;

/**
 * SELECT-only account-link reader for release-gate variance snapshots.
 */
final class CentralWalletAccountLinkReconciliationReader
{
    public function __construct(
        private readonly CentralWalletAccountLinkReconciliationAnalyzer $analyzer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function reconcileFromEnvPaths(string $deskEnvPath, string $spokeEnvPath, string $siteCode): array
    {
        $deskPdo = $this->pdoFromEnvFile($deskEnvPath);
        $spokePdo = $this->pdoFromEnvFile($spokeEnvPath);

        $deskRows = $this->fetchDeskLinks($deskPdo, $siteCode);
        $spokeRows = $this->fetchSpokeLinks($spokePdo, $siteCode);

        return $this->analyzer->reconcile($siteCode, $deskRows, $spokeRows);
    }

    /**
     * @return array<string, string>
     */
    private function parseEnvFile(string $path): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Env file not readable: {$path}");
        }

        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $values[trim($key)] = trim($value, " \t\"'");
        }

        return $values;
    }

    private function pdoFromEnvFile(string $path): PDO
    {
        $env = $this->parseEnvFile($path);
        $host = $env['DB_HOST'] ?? '127.0.0.1';
        $port = $env['DB_PORT'] ?? '3306';
        $database = $env['DB_DATABASE'] ?? '';
        $username = $env['DB_USERNAME'] ?? '';
        $password = $env['DB_PASSWORD'] ?? '';

        if ($database === '' || $username === '') {
            throw new RuntimeException('DB_DATABASE/DB_USERNAME missing from env');
        }

        return new PDO(
            "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchDeskLinks(PDO $pdo, string $siteCode): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, central_wallet_id, desk_customer_id, site_code, local_user_id, status, verification_method
             FROM central_wallet_account_links WHERE site_code = ? ORDER BY id'
        );
        $stmt->execute([$siteCode]);

        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchSpokeLinks(PDO $pdo, string $siteCode): array
    {
        $columns = $pdo->query('SHOW COLUMNS FROM central_wallet_account_links')->fetchAll();
        $available = array_column($columns, 'Field');
        $select = array_values(array_intersect(
            ['id', 'central_wallet_id', 'desk_customer_id', 'local_user_id', 'site_code', 'link_status', 'verification_method', 'desk_link_id'],
            $available,
        ));

        $sql = 'SELECT '.implode(', ', $select).' FROM central_wallet_account_links WHERE site_code = ? ORDER BY id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$siteCode]);

        return $stmt->fetchAll();
    }
}

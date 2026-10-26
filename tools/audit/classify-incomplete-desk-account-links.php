<?php

declare(strict_types=1);

/**
 * READ-ONLY classifier for active account links with NULL desk_customer_id.
 *
 * Usage:
 *   php tools/audit/classify-incomplete-desk-account-links.php --desk-env=/var/www/radium-desk/.env
 */

require __DIR__.'/../../vendor/autoload.php';

$options = getopt('', ['desk-env:']);
$envPath = $options['desk-env'] ?? null;
if (! is_string($envPath) || $envPath === '') {
    fwrite(STDERR, "Missing --desk-env\n");
    exit(1);
}

$env = parse_ini_file($envPath, false, INI_SCANNER_RAW) ?: [];
$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = $env['DB_PORT'] ?? '3306';
$db = $env['DB_DATABASE'] ?? '';
$user = $env['DB_USERNAME'] ?? '';
$pass = $env['DB_PASSWORD'] ?? '';

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$sql = <<<'SQL'
SELECT l.id, l.site_code, l.local_user_id, l.central_wallet_id, l.verification_method, l.created_by, l.linked_at,
       (SELECT COUNT(*) FROM central_customers cc WHERE cc.central_wallet_id = l.central_wallet_id) AS cust_cnt,
       (SELECT MAX(CASE
            WHEN JSON_UNQUOTE(JSON_EXTRACT(c.metadata, '$.local_user_id')) = l.local_user_id
             AND JSON_UNQUOTE(JSON_EXTRACT(c.metadata, '$.site_code')) = l.site_code
            THEN 1 ELSE 0 END)
        FROM central_customers cc2
        JOIN central_customer_identity_credentials c ON c.desk_customer_id = cc2.id
        WHERE cc2.central_wallet_id = l.central_wallet_id) AS cred_meta_match,
       (SELECT COALESCE(SUM(CASE WHEN e.entry_type = 'credit' AND e.status = 'posted' THEN e.amount
            WHEN e.entry_type = 'debit' AND e.status = 'posted' THEN -e.amount ELSE 0 END), 0)
        FROM central_wallet_ledger_entries e
        WHERE e.central_wallet_id = l.central_wallet_id AND e.status = 'posted') AS ledger_net
FROM central_wallet_account_links l
WHERE l.status = 'active' AND l.desk_customer_id IS NULL
ORDER BY l.site_code, l.id
SQL;

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$counts = [
    'safely_mappable' => 0,
    'unmatched' => 0,
    'ambiguous' => 0,
    'no_customer' => 0,
];
$bySite = [];
$positiveBalance = 0;

foreach ($rows as $row) {
    $site = (string) $row['site_code'];
    $bySite[$site] = ($bySite[$site] ?? 0) + 1;
    $custCnt = (int) $row['cust_cnt'];
    $credMatch = (int) ($row['cred_meta_match'] ?? 0);
    if ($custCnt === 0) {
        $status = 'no_customer';
    } elseif ($custCnt > 1) {
        $status = 'ambiguous';
    } elseif ($credMatch === 1) {
        $status = 'safely_mappable';
    } else {
        $status = 'unmatched';
    }
    $counts[$status]++;
    if ((float) $row['ledger_net'] > 0) {
        $positiveBalance++;
    }
}

echo json_encode([
    'total' => count($rows),
    'classification' => $counts,
    'by_site' => $bySite,
    'links_with_positive_ledger_net' => $positiveBalance,
], JSON_PRETTY_PRINT)."\n";

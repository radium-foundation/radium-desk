<?php

declare(strict_types=1);

/**
 * Repair active account links with NULL desk_customer_id when exactly one trusted mapping exists.
 *
 * Usage:
 *   php tools/audit/repair-incomplete-desk-account-links.php --desk-env=/var/www/radium-desk/.env [--apply] [--link-id=110]
 *
 * Default is dry-run (no UPDATE). Backups written to --backup-dir (default: /tmp/cw-link-repair-backup-{ts}).
 */
$options = getopt('', ['desk-env:', 'apply', 'link-id:', 'backup-dir:']);
$envPath = $options['desk-env'] ?? null;
$apply = array_key_exists('apply', $options);
$linkIdFilter = isset($options['link-id']) ? (int) $options['link-id'] : null;
$backupDir = $options['backup-dir'] ?? ('/tmp/cw-link-repair-backup-'.gmdate('YmdHis'));

if (! is_string($envPath) || $envPath === '') {
    fwrite(STDERR, "Missing --desk-env\n");
    exit(1);
}

$env = parse_ini_file($envPath, false, INI_SCANNER_RAW) ?: [];
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'] ?? '127.0.0.1', $env['DB_PORT'] ?? '3306', $env['DB_DATABASE'] ?? ''),
    $env['DB_USERNAME'] ?? '',
    $env['DB_PASSWORD'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$sql = <<<'SQL'
SELECT l.id, l.site_code, l.local_user_id, l.central_wallet_id, l.desk_customer_id, l.status,
       cc.id AS proposed_desk_customer_id,
       (SELECT COUNT(*) FROM central_customers x WHERE x.central_wallet_id = l.central_wallet_id) AS cust_cnt,
       (SELECT MAX(CASE
            WHEN JSON_UNQUOTE(JSON_EXTRACT(c.metadata, '$.local_user_id')) = l.local_user_id
             AND JSON_UNQUOTE(JSON_EXTRACT(c.metadata, '$.site_code')) = l.site_code
            THEN 1 ELSE 0 END)
        FROM central_customers cc2
        JOIN central_customer_identity_credentials c ON c.desk_customer_id = cc2.id
        WHERE cc2.central_wallet_id = l.central_wallet_id AND c.verified_at IS NOT NULL) AS cred_meta_match
FROM central_wallet_account_links l
LEFT JOIN central_customers cc ON cc.central_wallet_id = l.central_wallet_id
WHERE l.status = 'active' AND l.desk_customer_id IS NULL
SQL;

if ($linkIdFilter !== null) {
    $sql .= ' AND l.id = '.(int) $linkIdFilter;
}
$sql .= ' ORDER BY l.id';

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$results = ['dry_run' => ! $apply, 'repaired' => [], 'skipped' => [], 'failed' => []];

if ($apply && ! is_dir($backupDir)) {
    mkdir($backupDir, 0700, true);
}

foreach ($rows as $row) {
    $linkId = (int) $row['id'];
    $custCnt = (int) $row['cust_cnt'];
    $credMatch = (int) ($row['cred_meta_match'] ?? 0);
    $proposed = trim((string) ($row['proposed_desk_customer_id'] ?? ''));

    if ($custCnt !== 1 || $credMatch !== 1 || $proposed === '') {
        $results['skipped'][] = ['link_id' => $linkId, 'reason' => 'not_safely_mappable', 'cust_cnt' => $custCnt, 'cred_meta_match' => $credMatch];

        continue;
    }

    if (! $apply) {
        $results['repaired'][] = ['link_id' => $linkId, 'proposed_desk_customer_id' => $proposed, 'action' => 'would_update'];

        continue;
    }

    $backupFile = $backupDir.'/link-'.$linkId.'.json';
    $stmt = $pdo->prepare('SELECT * FROM central_wallet_account_links WHERE id = ? AND desk_customer_id IS NULL AND status = ?');
    $stmt->execute([$linkId, 'active']);
    $before = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($before === false) {
        $results['failed'][] = ['link_id' => $linkId, 'reason' => 'pre_check_mismatch'];

        continue;
    }
    file_put_contents($backupFile, json_encode($before, JSON_PRETTY_PRINT));

    $update = $pdo->prepare(
        'UPDATE central_wallet_account_links SET desk_customer_id = ?, updated_at = NOW()
         WHERE id = ? AND desk_customer_id IS NULL AND status = ? AND central_wallet_id = ?'
    );
    $update->execute([$proposed, $linkId, 'active', $row['central_wallet_id']]);
    if ($update->rowCount() !== 1) {
        $results['failed'][] = ['link_id' => $linkId, 'reason' => 'row_count_not_one', 'count' => $update->rowCount()];

        continue;
    }

    $verify = $pdo->prepare('SELECT desk_customer_id FROM central_wallet_account_links WHERE id = ?');
    $verify->execute([$linkId]);
    $after = (string) $verify->fetchColumn();
    if ($after !== $proposed) {
        $results['failed'][] = ['link_id' => $linkId, 'reason' => 'post_verify_failed'];

        continue;
    }

    $results['repaired'][] = ['link_id' => $linkId, 'desk_customer_id' => $proposed, 'backup' => $backupFile];
}

echo json_encode($results, JSON_PRETTY_PRINT)."\n";

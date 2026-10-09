<?php

namespace App\CentralWallet\Reliability;

/**
 * Pure read-only reconciliation classifier for Desk vs spoke account links.
 * No database access — callers supply SELECT-derived rows.
 */
final class CentralWalletAccountLinkReconciliationAnalyzer
{
    /**
     * @param  list<array<string, mixed>>  $deskRows
     * @param  list<array<string, mixed>>  $spokeRows
     * @return array<string, mixed>
     */
    public function reconcile(string $siteCode, array $deskRows, array $spokeRows): array
    {
        $deskActive = array_values(array_filter(
            $deskRows,
            fn (array $row): bool => ($row['status'] ?? '') === 'active',
        ));
        $spokeActive = array_values(array_filter(
            $spokeRows,
            fn (array $row): bool => ($row['link_status'] ?? '') === 'active',
        ));

        $deskByUser = [];
        foreach ($deskActive as $row) {
            $deskByUser[(string) $row['local_user_id']][] = $row;
        }

        $spokeByUser = [];
        foreach ($spokeActive as $row) {
            $spokeByUser[(string) $row['local_user_id']][] = $row;
        }

        $counts = [
            'match' => 0,
            'desk_missing_on_spoke' => 0,
            'spoke_only' => 0,
            'wallet_id_mismatch' => 0,
            'identity_mismatch' => 0,
            'duplicate_spoke_link' => 0,
            'duplicate_desk_link' => 0,
            'verification_method_mismatch' => 0,
        ];

        foreach ($deskByUser as $rows) {
            if (count($rows) > 1) {
                $counts['duplicate_desk_link']++;
            }
        }

        foreach ($spokeByUser as $rows) {
            if (count($rows) > 1) {
                $counts['duplicate_spoke_link']++;
            }
        }

        foreach ($deskByUser as $userId => $deskRowsForUser) {
            $desk = $deskRowsForUser[0];
            $spokeRowsForUser = $spokeByUser[$userId] ?? [];

            if ($spokeRowsForUser === []) {
                $counts['desk_missing_on_spoke']++;

                continue;
            }

            $spoke = $spokeRowsForUser[0];
            if (($desk['central_wallet_id'] ?? null) !== ($spoke['central_wallet_id'] ?? null)) {
                $counts['wallet_id_mismatch']++;

                continue;
            }

            $deskLinkId = isset($desk['id']) ? (int) $desk['id'] : null;
            $spokeDeskLinkId = isset($spoke['desk_link_id']) && $spoke['desk_link_id'] !== null
                ? (int) $spoke['desk_link_id']
                : null;

            if ($deskLinkId !== null && $spokeDeskLinkId !== null && $spokeDeskLinkId !== $deskLinkId) {
                $counts['identity_mismatch']++;
            }

            $deskVerify = trim((string) ($desk['verification_method'] ?? ''));
            $spokeVerify = trim((string) ($spoke['verification_method'] ?? ''));
            if ($deskVerify !== $spokeVerify) {
                $counts['verification_method_mismatch']++;
            }

            $counts['match']++;
        }

        foreach ($spokeByUser as $userId => $_) {
            if (! isset($deskByUser[$userId])) {
                $counts['spoke_only']++;
            }
        }

        return [
            'site_code' => $siteCode,
            'desk_active' => count($deskActive),
            'spoke_local_active' => count($spokeActive),
            'counts' => $counts,
        ];
    }
}

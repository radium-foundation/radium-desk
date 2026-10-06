<?php

namespace App\CentralWallet\Application;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;

final class HistoricalVisibilityContactIndexLoader
{
    /**
     * @return array{
     *     population_count: int,
     *     population_amount: string,
     *     rows: list<array<string, mixed>>,
     *     by_site_email_hash: array<string, list<array<string, mixed>>>,
     *     by_site_mobile_hash: array<string, list<array<string, mixed>>>,
     *     by_email_hash: array<string, list<array<string, mixed>>>,
     *     by_mobile_hash: array<string, list<array<string, mixed>>>
     * }
     */
    public function load(?string $path = null): array
    {
        $path ??= (string) config('central_wallet.historical_wallet_visibility.contact_index_manifest_path');
        if ($path === '' || ! File::exists($path)) {
            throw new InvalidArgumentException('historical_visibility_contact_index_not_found');
        }

        $decoded = json_decode(File::get($path), true);
        if (! is_array($decoded) || ! isset($decoded['rows']) || ! is_array($decoded['rows'])) {
            throw new InvalidArgumentException('historical_visibility_contact_index_invalid');
        }

        $rows = array_values($decoded['rows']);
        $bySiteEmailHash = [];
        $bySiteMobileHash = [];
        $byEmailHash = [];
        $byMobileHash = [];

        foreach ($rows as $row) {
            $site = strtolower(trim((string) ($row['site'] ?? '')));
            $emailHash = trim((string) ($row['order_email_hash'] ?? ''));
            $mobileHash = trim((string) ($row['order_mobile_hash'] ?? ''));

            if ($site !== '' && $emailHash !== '') {
                $bySiteEmailHash[$this->siteContactKey($site, $emailHash)][] = $row;
            }

            if ($site !== '' && $mobileHash !== '') {
                $bySiteMobileHash[$this->siteContactKey($site, $mobileHash)][] = $row;
            }

            if ($emailHash !== '') {
                $byEmailHash[$emailHash][] = $row;
            }

            if ($mobileHash !== '') {
                $byMobileHash[$mobileHash][] = $row;
            }
        }

        return [
            'population_count' => (int) ($decoded['population_count'] ?? count($rows)),
            'population_amount' => (string) ($decoded['population_amount'] ?? '0.00'),
            'rows' => $rows,
            'by_site_email_hash' => $bySiteEmailHash,
            'by_site_mobile_hash' => $bySiteMobileHash,
            'by_email_hash' => $byEmailHash,
            'by_mobile_hash' => $byMobileHash,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findBySiteEmailHash(array $manifest, string $siteCode, string $emailHash): array
    {
        return $manifest['by_site_email_hash'][$this->siteContactKey($siteCode, $emailHash)] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findBySiteMobileHash(array $manifest, string $siteCode, string $mobileHash): array
    {
        return $manifest['by_site_mobile_hash'][$this->siteContactKey($siteCode, $mobileHash)] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByEmailHash(array $manifest, string $emailHash): array
    {
        return $manifest['by_email_hash'][$emailHash] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByMobileHash(array $manifest, string $mobileHash): array
    {
        return $manifest['by_mobile_hash'][$mobileHash] ?? [];
    }

    public function siteContactKey(string $siteCode, string $contactHash): string
    {
        return strtolower(trim($siteCode)).':'.trim($contactHash);
    }
}

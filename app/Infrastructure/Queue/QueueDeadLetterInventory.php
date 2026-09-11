<?php

namespace App\Infrastructure\Queue;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class QueueDeadLetterInventory
{
    /**
     * @param  list<string>  $actionableUuids
     * @param  list<string>  $historicalUuids
     */
    private function __construct(
        private readonly array $actionableUuids,
        private readonly array $historicalUuids,
    ) {}

    public static function load(): self
    {
        if (! Schema::hasTable('failed_jobs')) {
            return new self([], []);
        }

        $rows = DB::table('failed_jobs')
            ->select(['uuid', 'exception'])
            ->orderBy('uuid')
            ->get();

        $actionable = [];
        $historical = [];

        foreach ($rows as $row) {
            $exception = (string) ($row->exception ?? '');
            $uuid = (string) $row->uuid;

            if (self::isRetiredInfrastructureException($exception)) {
                $historical[] = $uuid;

                continue;
            }

            if (self::isStaleKvm8Exception($exception)) {
                $historical[] = $uuid;

                continue;
            }

            if (self::isCurrentKvm8OperationalException($exception)) {
                $actionable[] = $uuid;

                continue;
            }

            // Preserve UNKNOWN as actionable — never guess historical.
            $actionable[] = $uuid;
        }

        return new self($actionable, $historical);
    }

    /**
     * @return list<string>
     */
    public function actionableUuids(): array
    {
        return $this->actionableUuids;
    }

    public function actionableCount(): int
    {
        return count($this->actionableUuids);
    }

    public function historicalCount(): int
    {
        return count($this->historicalUuids);
    }

    private static function isRetiredInfrastructureException(string $exception): bool
    {
        if (str_contains($exception, 'admin.radiumbox.com')) {
            return true;
        }

        if (str_contains($exception, 'HTTP 526')) {
            return true;
        }

        if (str_contains($exception, '/home/u215544208/')) {
            return true;
        }

        return false;
    }

    private static function isCurrentKvm8OperationalException(string $exception): bool
    {
        return str_contains($exception, '127.0.0.1')
            && str_contains($exception, 'cURL error 28');
    }

    private static function isStaleKvm8Exception(string $exception): bool
    {
        if (! self::isCurrentKvm8OperationalException($exception)) {
            return false;
        }

        $orderId = self::orderIdFromException($exception);

        if ($orderId === null || ! Order::supportsRadiumBoxSyncTracking()) {
            return false;
        }

        $syncStatus = Order::query()
            ->where('order_id', $orderId)
            ->value('radiumbox_sync_status');

        if ($syncStatus instanceof RadiumBoxEnrichmentSyncStatus) {
            return $syncStatus !== RadiumBoxEnrichmentSyncStatus::Failed;
        }

        if (! is_string($syncStatus) || $syncStatus === '') {
            return false;
        }

        return RadiumBoxEnrichmentSyncStatus::tryFrom($syncStatus) !== RadiumBoxEnrichmentSyncStatus::Failed;
    }

    private static function orderIdFromException(string $exception): ?string
    {
        if (preg_match('#/api/integrations/v1/[^/]+/([A-Z0-9]+)#i', $exception, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        if (preg_match('/\b(RDE\d+|RD\d+|RA\d+|RBP\d+)\b/', $exception, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        return null;
    }
}

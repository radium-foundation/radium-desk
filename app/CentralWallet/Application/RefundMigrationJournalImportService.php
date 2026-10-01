<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\RefundMigrationLane;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use App\CentralWallet\Domain\RefundMigrationIdempotencyKey;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RefundMigrationJournalImportService
{
    public function __construct(
        private readonly RefundMigrationManifestLoader $manifestLoader,
    ) {}

    /**
     * @return array{imported: int, skipped: int, batch_id: string}
     */
    public function import(?string $manifestPath = null): array
    {
        $manifest = $this->manifestLoader->load($manifestPath);
        $batchId = (string) $manifest['batch_id'];
        $imported = 0;
        $skipped = 0;

        DB::transaction(function () use ($manifest, $batchId, &$imported, &$skipped): void {
            foreach ($manifest['rows'] as $row) {
                $refundId = (int) $row['refund_id'];
                $existing = CentralWalletRefundMigration::query()
                    ->where('refund_id', $refundId)
                    ->first();

                if ($existing !== null) {
                    $skipped++;

                    continue;
                }

                $lane = RefundMigrationLane::fromManifestLane((string) $row['migration_lane']);
                $sourceType = match ($lane) {
                    RefundMigrationLane::Lane1SpokeCutover => 'spoke_wallet',
                    RefundMigrationLane::Lane2AmbiguityResolution => 'ambiguous_spoke',
                    RefundMigrationLane::Lane3RefundProvenance => 'refund_provenance',
                    RefundMigrationLane::Lane3BlockedInsufficientEvidence => 'none',
                };

                $spokeSite = $this->resolveSpokeSite($row);
                $spokeWalletId = $this->parseSpokeWalletId($row);
                $manifestCwid = isset($row['cwid']) && is_string($row['cwid']) && $row['cwid'] !== ''
                    ? $row['cwid']
                    : null;
                $cwid = $manifestCwid !== null && CentralWallet::query()->find($manifestCwid) !== null
                    ? $manifestCwid
                    : null;

                CentralWalletRefundMigration::query()->create([
                    'id' => (string) Str::uuid(),
                    'batch_id' => $batchId,
                    'refund_id' => $refundId,
                    'refund_reference' => (string) $row['reference_no'],
                    'amount' => (string) $row['amount'],
                    'source_type' => $sourceType,
                    'source_application' => $spokeSite,
                    'source_wallet_id' => $spokeWalletId,
                    'source_reference' => RefundMigrationIdempotencyKey::ledgerSourceReference($refundId),
                    'desk_customer_id' => null,
                    'cwid' => $cwid,
                    'lane' => $lane,
                    'status' => RefundMigrationStatus::Pending,
                    'idempotency_key' => RefundMigrationIdempotencyKey::forRefund($refundId),
                    'order_number' => isset($row['order_number']) ? (string) $row['order_number'] : null,
                    'identity_class' => isset($row['identity_class']) ? (string) $row['identity_class'] : null,
                    'metadata' => [
                        'destination_class_p24' => $row['destination_class_p24'] ?? null,
                        'identity_note' => $row['identity_note'] ?? null,
                        'order_resolved_user_id' => $row['order_resolved_user_id'] ?? null,
                        'manifest_lane' => $row['migration_lane'] ?? null,
                        'manifest_cwid' => $manifestCwid,
                    ],
                ]);

                $imported++;
            }
        });

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'batch_id' => $batchId,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function parseSpokeWalletId(array $row): ?int
    {
        $value = $row['spoke_wallet_id'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolveSpokeSite(array $row): ?string
    {
        if (isset($row['spoke_site']) && is_string($row['spoke_site']) && $row['spoke_site'] !== '') {
            return $row['spoke_site'];
        }

        $order = (string) ($row['order_number'] ?? '');
        if (preg_match('/^(RD|RIN|RDP)/', $order) === 1) {
            return 'rdservice.in';
        }

        if (preg_match('/^(RB|RBX|RDE|RBP)/', $order) === 1) {
            return 'radiumbox.com';
        }

        return null;
    }
}

<?php

namespace App\Services\Finance;

use App\Models\FinanceLegacyCashEntry;
use App\Services\Finance\Data\LegacyCashImportResult;
use App\Services\Finance\Data\LegacyCashSourceRow;
use App\Support\Finance\LegacyCashContract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LegacyCashImportService
{
    public function __construct(
        private readonly LegacyCashUserMapper $mapper,
    ) {}

    /**
     * @param  Collection<int, LegacyCashSourceRow>  $rows
     */
    public function import(Collection $rows, bool $dryRun = true): LegacyCashImportResult
    {
        $this->assertNoFabricatedDeletedRows($rows);

        $maps = $this->mapper->sync($rows, $dryRun);

        $credits = 0;
        $debits = 0;
        $creditTotal = 0.0;
        $debitTotal = 0.0;
        $mappedRows = 0;
        $unmappedRows = 0;
        $reviewRows = 0;
        $imported = 0;
        $skippedExisting = 0;
        $conflicts = [];
        $maxCreatedAt = null;
        $unmappedAdminCounts = [];

        $persist = function () use (
            $rows,
            $maps,
            $dryRun,
            &$credits,
            &$debits,
            &$creditTotal,
            &$debitTotal,
            &$mappedRows,
            &$unmappedRows,
            &$reviewRows,
            &$imported,
            &$skippedExisting,
            &$conflicts,
            &$maxCreatedAt,
            &$unmappedAdminCounts,
        ): void {
            $now = now();

            foreach ($rows as $row) {
                if ($row->type === FinanceLegacyCashEntry::TYPE_CREDIT) {
                    $credits++;
                    $creditTotal += (float) $row->amount();
                } else {
                    $debits++;
                    $debitTotal += (float) $row->amount();
                }

                if ($maxCreatedAt === null || $row->createdAt > $maxCreatedAt) {
                    $maxCreatedAt = $row->createdAt;
                }

                $map = $maps[$row->createdBy] ?? null;
                $deskUserId = $map?->desk_user_id;
                if ($deskUserId !== null) {
                    $mappedRows++;
                } else {
                    $unmappedRows++;
                    $unmappedAdminCounts[$row->createdBy] = ($unmappedAdminCounts[$row->createdBy] ?? 0) + 1;
                }

                $reviewReason = LegacyCashContract::REVIEW_REASONS[$row->id] ?? null;
                if ($reviewReason !== null) {
                    $reviewRows++;
                }

                if ($dryRun) {
                    $imported++;

                    continue;
                }

                $key = LegacyCashContract::idempotencyKey($row->id);
                $existing = FinanceLegacyCashEntry::query()
                    ->where('idempotency_key', $key)
                    ->lockForUpdate()
                    ->first();

                $payload = [
                    'legacy_source' => LegacyCashContract::SOURCE_LABEL,
                    'legacy_database' => LegacyCashContract::LEGACY_DATABASE,
                    'legacy_table' => LegacyCashContract::LEGACY_TABLE,
                    'legacy_transaction_id' => $row->id,
                    'idempotency_key' => $key,
                    'original_created_at' => $row->createdAt,
                    'original_updated_at' => $row->updatedAt,
                    'original_amount_raw' => $row->amountRaw,
                    'amount' => $row->amount(),
                    'entry_type' => $row->type,
                    'amount_type' => $row->amountType,
                    'description' => $row->description,
                    'legacy_created_by' => $row->createdBy,
                    'legacy_admin_name' => $row->adminName,
                    'desk_user_id' => $deskUserId,
                    'import_status' => FinanceLegacyCashEntry::IMPORT_IMPORTED,
                    'review_status' => $reviewReason === null
                        ? FinanceLegacyCashEntry::REVIEW_OK
                        : FinanceLegacyCashEntry::REVIEW_NEEDS_REVIEW,
                    'review_reason' => $reviewReason,
                    'imported_at' => $now,
                ];

                if ($existing === null) {
                    FinanceLegacyCashEntry::query()->create($payload);
                    $imported++;

                    continue;
                }

                if ($this->conflictsWithExisting($existing, $payload)) {
                    $conflicts[] = $key;

                    continue;
                }

                if ($existing->desk_user_id === null && $deskUserId !== null) {
                    $existing->desk_user_id = $deskUserId;
                    $existing->save();
                }

                $skippedExisting++;
            }
        };

        if ($dryRun) {
            $persist();
        } else {
            DB::transaction($persist);
        }

        $presentExcludedIds = $rows
            ->pluck('id')
            ->intersect(LegacyCashContract::EXCLUDED_LEGACY_IDS)
            ->sort()
            ->values()
            ->all();

        return new LegacyCashImportResult(
            sourceRows: $rows->count(),
            imported: $imported,
            skippedExisting: $skippedExisting,
            credits: $credits,
            debits: $debits,
            creditTotal: LegacyCashContract::money($creditTotal),
            debitTotal: LegacyCashContract::money($debitTotal),
            net: LegacyCashContract::money($creditTotal - $debitTotal),
            mappedRows: $mappedRows,
            unmappedRows: $unmappedRows,
            reviewRows: $reviewRows,
            presentExcludedIds: $presentExcludedIds,
            conflicts: $conflicts,
            unmappedAdminCounts: $unmappedAdminCounts,
            dryRun: $dryRun,
            maxCreatedAt: $maxCreatedAt,
        );
    }

    /**
     * @param  Collection<int, LegacyCashSourceRow>  $rows
     */
    private function assertNoFabricatedDeletedRows(Collection $rows): void
    {
        $present = $rows->pluck('id')->intersect(LegacyCashContract::EXCLUDED_LEGACY_IDS);
        if ($present->isNotEmpty()) {
            throw ValidationException::withMessages([
                'source' => 'Source includes hard-deleted Admin expense IDs that must remain absent: '.$present->sort()->implode(', '),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function conflictsWithExisting(FinanceLegacyCashEntry $existing, array $payload): bool
    {
        $fields = [
            'legacy_transaction_id',
            'original_amount_raw',
            'amount',
            'entry_type',
            'amount_type',
            'description',
            'legacy_created_by',
            'original_created_at',
        ];

        foreach ($fields as $field) {
            $expected = $payload[$field] ?? null;
            $actual = $existing->{$field};

            if ($field === 'amount') {
                if (LegacyCashContract::money((string) $actual) !== LegacyCashContract::money((string) $expected)) {
                    return true;
                }

                continue;
            }

            if ($field === 'original_created_at') {
                $actualStamp = $existing->original_created_at?->format('Y-m-d H:i:s');
                $expectedStamp = is_string($expected)
                    ? Carbon::parse($expected)->format('Y-m-d H:i:s')
                    : null;
                if ($actualStamp !== $expectedStamp) {
                    return true;
                }

                continue;
            }

            if (trim((string) ($actual ?? '')) !== trim((string) ($expected ?? ''))) {
                return true;
            }
        }

        return false;
    }
}

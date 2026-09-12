<?php

namespace App\Services\Purchasing;

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorImportBatch;
use App\Models\VendorImportConflict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LegacyVendorImportService
{
    public const SOURCE_DATABASE = 'radiumbox_prod';

    public const SOURCE_TABLE = 'supplier';

    public function __construct(
        private readonly PurchasingAuditService $audit,
    ) {}

    /**
     * @return array{imported: int, skipped: int, conflicted: int, batch: VendorImportBatch}
     */
    public function importFromJson(string $path, User $actor, ?string $batchReference = null): array
    {
        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload)) {
            throw new \InvalidArgumentException('Legacy vendor fixture must be a JSON array.');
        }

        $batchReference ??= 'LEGACY-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6));

        return DB::transaction(function () use ($payload, $actor, $batchReference): array {
            $batch = VendorImportBatch::query()->create([
                'batch_reference' => $batchReference,
                'source_database' => self::SOURCE_DATABASE,
                'source_table' => self::SOURCE_TABLE,
                'imported_by_user_id' => $actor->id,
            ]);

            $imported = 0;
            $skipped = 0;
            $conflicted = 0;

            foreach ($payload as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $batch->increment('records_attempted');
                $legacyId = (int) ($row['legacy_supplier_id'] ?? $row['id'] ?? 0);
                if ($legacyId < 1) {
                    $skipped++;
                    $batch->increment('records_skipped');

                    continue;
                }

                $existingByLegacy = Vendor::query()
                    ->where('legacy_source_database', self::SOURCE_DATABASE)
                    ->where('legacy_source_table', self::SOURCE_TABLE)
                    ->where('legacy_supplier_id', $legacyId)
                    ->first();

                if ($existingByLegacy !== null) {
                    $skipped++;
                    $batch->increment('records_skipped');

                    continue;
                }

                $mapped = $this->mapLegacyRow($row, $legacyId, $batchReference);
                $conflict = $this->detectConflict($mapped, $legacyId);

                if ($conflict !== null) {
                    VendorImportConflict::query()->create([
                        'vendor_import_batch_id' => $batch->id,
                        'legacy_supplier_id' => $legacyId,
                        'existing_vendor_id' => $conflict['vendor_id'],
                        'conflict_reason' => $conflict['reason'],
                        'legacy_payload' => $row,
                    ]);
                    $conflicted++;
                    $batch->increment('records_conflicted');

                    continue;
                }

                $vendor = Vendor::query()->create($mapped);
                $this->audit->log($actor, 'vendor.imported', $vendor, null, $vendor->toArray());
                $imported++;
                $batch->increment('records_imported');
            }

            $batch->update([
                'summary' => [
                    'imported' => $imported,
                    'skipped' => $skipped,
                    'conflicted' => $conflicted,
                ],
            ]);

            return [
                'imported' => $imported,
                'skipped' => $skipped,
                'conflicted' => $conflicted,
                'batch' => $batch->fresh(),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mapLegacyRow(array $row, int $legacyId, string $batchReference): array
    {
        return [
            'business_name' => trim((string) ($row['company_name'] ?? $row['business_name'] ?? 'Legacy supplier '.$legacyId)),
            'legal_name' => filled($row['name'] ?? null) ? trim((string) $row['name']) : null,
            'gstin' => filled($row['gstno'] ?? $row['gstin'] ?? null) ? strtoupper(trim((string) ($row['gstno'] ?? $row['gstin']))) : null,
            'pan' => filled($row['pancard'] ?? $row['pan'] ?? null) ? strtoupper(trim((string) ($row['pancard'] ?? $row['pan']))) : null,
            'phone' => filled($row['mobile'] ?? $row['phone'] ?? null) ? trim((string) ($row['mobile'] ?? $row['phone'])) : null,
            'email' => filled($row['email'] ?? null) ? trim((string) $row['email']) : null,
            'billing_address' => filled($row['address'] ?? null) ? trim((string) $row['address']) : null,
            'city' => filled($row['city'] ?? null) ? trim((string) $row['city']) : null,
            'state' => filled($row['state'] ?? null) ? trim((string) $row['state']) : null,
            'country' => 'India',
            'pin' => filled($row['pincode'] ?? $row['pin'] ?? null) ? trim((string) ($row['pincode'] ?? $row['pin'])) : null,
            'is_active' => (int) ($row['status'] ?? 1) === 1,
            'legacy_source_database' => self::SOURCE_DATABASE,
            'legacy_source_table' => self::SOURCE_TABLE,
            'legacy_supplier_id' => $legacyId,
            'legacy_import_batch' => $batchReference,
            'legacy_imported_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $mapped
     * @return array{vendor_id: int, reason: string}|null
     */
    private function detectConflict(array $mapped, int $legacyId): ?array
    {
        if (filled($mapped['gstin'])) {
            $match = Vendor::query()
                ->where('gstin', $mapped['gstin'])
                ->where(function ($query) use ($legacyId) {
                    $query->whereNull('legacy_supplier_id')
                        ->orWhere('legacy_supplier_id', '!=', $legacyId);
                })
                ->first();

            if ($match !== null) {
                return ['vendor_id' => $match->id, 'reason' => 'gstin_match_existing_vendor'];
            }
        }

        if (filled($mapped['pan'])) {
            $match = Vendor::query()
                ->where('pan', $mapped['pan'])
                ->where(function ($query) use ($legacyId) {
                    $query->whereNull('legacy_supplier_id')
                        ->orWhere('legacy_supplier_id', '!=', $legacyId);
                })
                ->first();

            if ($match !== null) {
                return ['vendor_id' => $match->id, 'reason' => 'pan_match_existing_vendor'];
            }
        }

        return null;
    }
}

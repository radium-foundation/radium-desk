<?php

namespace App\Services\Pos;

use App\Models\InventoryCustomer;
use App\Models\InventoryCustomerBillingProfile;
use App\Support\Finance\IndianStates;
use App\Support\StatutoryInvoice\StatutoryBillingStructured;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class AdminPosCustomerBillingImporter
{
    /**
     * @var list<int>
     */
    public const PROTECTED_CUSTOMER_IDS = [1, 2, 4, 5, 6];

    /**
     * @param  list<array{
     *     legacy_user_id: int,
     *     legacy_order_id?: int|null,
     *     phone: string,
     *     address?: ?string,
     *     district?: ?string,
     *     state?: ?string,
     *     pincode?: ?string
     * }>  $sourceRows
     * @return array{counts: array<string, int>, rows: list<array<string, mixed>>}
     */
    public function preview(array $sourceRows): array
    {
        $customers = InventoryCustomer::query()->get(['id', 'phone']);
        $byPhone = [];
        foreach ($customers as $customer) {
            $phone = preg_replace('/\s+/', '', trim((string) $customer->phone)) ?? '';
            if ($phone !== '') {
                $byPhone[$phone] = $customer;
            }
        }

        $existingProfiles = InventoryCustomerBillingProfile::query()
            ->pluck('customer_id')
            ->all();
        $existingSet = array_fill_keys(array_map('intval', $existingProfiles), true);
        $seenPhones = [];

        $counts = [
            'source' => count($sourceRows),
            'created' => 0,
            'matched' => 0,
            'skipped' => 0,
            'review' => 0,
        ];
        $rows = [];

        foreach ($sourceRows as $row) {
            $phone = preg_replace('/\s+/', '', trim((string) ($row['phone'] ?? ''))) ?? '';
            $legacyUserId = (int) ($row['legacy_user_id'] ?? 0);
            $legacyOrderId = isset($row['legacy_order_id']) ? (int) $row['legacy_order_id'] : null;
            $structured = $this->structuredFromSource($row);

            if ($phone !== '' && isset($seenPhones[$phone])) {
                $counts['skipped']++;
                $rows[] = $this->manifestRow(
                    $legacyUserId,
                    $legacyOrderId,
                    isset($byPhone[$phone]) ? $byPhone[$phone]->id : null,
                    'SKIPPED',
                    'duplicate_source_phone',
                );

                continue;
            }
            if ($phone !== '') {
                $seenPhones[$phone] = true;
            }

            if ($phone === '' || ! isset($byPhone[$phone])) {
                $counts['review']++;
                $counts['skipped']++;
                $rows[] = $this->manifestRow($legacyUserId, $legacyOrderId, null, 'REVIEW', 'desk_customer_not_found');

                continue;
            }

            $customer = $byPhone[$phone];
            if (in_array($customer->id, self::PROTECTED_CUSTOMER_IDS, true)) {
                $counts['skipped']++;
                $rows[] = $this->manifestRow($legacyUserId, $legacyOrderId, $customer->id, 'SKIPPED', 'protected_desk_customer');

                continue;
            }

            if ($legacyOrderId === null || $legacyOrderId < 1 || $structured === null) {
                $counts['review']++;
                $counts['skipped']++;
                $rows[] = $this->manifestRow($legacyUserId, $legacyOrderId, $customer->id, 'REVIEW', 'no_invoiced_pos_snapshot');

                continue;
            }

            if (isset($existingSet[$customer->id])) {
                $counts['matched']++;
                $rows[] = $this->manifestRow($legacyUserId, $legacyOrderId, $customer->id, 'MATCHED', 'profile_exists');

                continue;
            }

            $counts['created']++;
            $rows[] = $this->manifestRow($legacyUserId, $legacyOrderId, $customer->id, 'CREATED', 'last_invoiced_pos_order') + [
                'line1' => $structured['line1'] ?? null,
                'city' => $structured['city'] ?? null,
                'state' => $structured['state'] ?? null,
                'pincode' => $structured['pincode'] ?? null,
            ];
        }

        return ['counts' => $counts, 'rows' => $rows];
    }

    /**
     * @param  list<array<string, mixed>>  $sourceRows
     * @return array{counts: array<string, int>, rows: list<array<string, mixed>>}
     */
    public function execute(array $sourceRows): array
    {
        return DB::transaction(function () use ($sourceRows): array {
            $preview = $this->preview($sourceRows);

            foreach ($preview['rows'] as $row) {
                if ($row['action'] !== 'CREATED') {
                    continue;
                }

                try {
                    InventoryCustomerBillingProfile::query()->create([
                        'customer_id' => $row['target_id'],
                        'line1' => $row['line1'] ?? null,
                        'city' => $row['city'] ?? null,
                        'state' => $row['state'] ?? null,
                        'pincode' => $row['pincode'] ?? null,
                        'source' => InventoryCustomerBillingProfile::SOURCE_LAST_ADMIN_POS_ORDER,
                        'source_legacy_order_id' => $row['legacy_order_id'],
                    ]);
                } catch (UniqueConstraintViolationException) {
                    continue;
                }
            }

            return $preview;
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{line1?: string, city?: string, state?: string, pincode?: string}|null
     */
    public function structuredFromSource(array $row): ?array
    {
        $state = StatutoryBillingStructured::nullable($row['state'] ?? null);
        if ($state !== null && ! IndianStates::contains($state)) {
            $state = null;
        }

        return StatutoryBillingStructured::fromParts(
            StatutoryBillingStructured::nullable($row['address'] ?? null),
            StatutoryBillingStructured::nullable($row['district'] ?? null),
            $state,
            $row['pincode'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function manifestRow(int $legacyUserId, ?int $legacyOrderId, ?int $targetId, string $action, string $reason): array
    {
        return [
            'legacy_source' => 'radiumbox_prod.orders.userdetails',
            'legacy_user_id' => $legacyUserId,
            'legacy_order_id' => $legacyOrderId,
            'target_id' => $targetId,
            'action' => $action,
            'reason' => $reason,
        ];
    }
}

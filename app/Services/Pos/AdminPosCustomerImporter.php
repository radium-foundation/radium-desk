<?php

namespace App\Services\Pos;

use App\Models\InventoryCustomer;
use Illuminate\Support\Facades\DB;

final class AdminPosCustomerImporter
{
    public function __construct(
        private readonly AdminPosCustomerImportClassifier $classifier,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $sourceRows
     * @param  list<array{id: int, phone: string, name: string, email: ?string, gstin: ?string}>  $existingCustomers
     * @return array{
     *     counts: array<string, int>,
     *     rows: list<array<string, mixed>>
     * }
     */
    public function preview(array $sourceRows, array $existingCustomers): array
    {
        $classified = $this->classifier->classify($sourceRows);
        $existingByPhone = [];
        foreach ($existingCustomers as $customer) {
            $phone = $this->classifier->normalizePhone($customer['phone'] ?? null);
            if ($phone !== '') {
                $existingByPhone[$phone] = $customer;
            }
        }

        $counts = [
            'source' => count($sourceRows),
            'A' => 0,
            'B' => 0,
            'C' => 0,
            'D' => 0,
            'created' => 0,
            'matched' => 0,
            'skipped' => 0,
            'review' => 0,
        ];
        $rows = [];

        foreach ($classified as $row) {
            $counts[$row['class']]++;
            $action = 'SKIPPED';
            $targetId = null;
            $reason = $row['reason'];

            if ($row['class'] === AdminPosCustomerImportClassifier::CLASS_C) {
                $action = 'REVIEW';
                $counts['review']++;
                $counts['skipped']++;
            } elseif ($row['class'] === AdminPosCustomerImportClassifier::CLASS_D) {
                $counts['skipped']++;
            } elseif (isset($existingByPhone[$row['phone']])) {
                $action = 'MATCHED';
                $targetId = (int) $existingByPhone[$row['phone']]['id'];
                $reason = 'existing_desk_phone';
                $counts['matched']++;
            } else {
                $action = 'CREATED';
                $counts['created']++;
            }

            $rows[] = [
                'legacy_source' => 'radiumbox_prod.users',
                'legacy_user_id' => $row['legacy_user_id'],
                'class' => $row['class'],
                'action' => $action,
                'reason' => $reason,
                'target_id' => $targetId,
                'phone_hash' => hash('sha256', $row['phone']),
                'name' => $row['name'],
                'phone' => $row['phone'],
                'email' => $row['email'],
                'gstin' => $row['gstin'],
            ];
        }

        return ['counts' => $counts, 'rows' => $rows];
    }

    /**
     * INSERT-only for A+B phones that do not already exist. Never updates existing rows.
     *
     * @param  list<array<string, mixed>>  $sourceRows
     * @return array{counts: array<string, int>, created_ids: list<array{legacy_user_id: int, id: int}>}
     */
    public function execute(array $sourceRows): array
    {
        return DB::transaction(function () use ($sourceRows): array {
            $existing = InventoryCustomer::query()
                ->get(['id', 'name', 'phone', 'email', 'gstin'])
                ->map(fn (InventoryCustomer $customer): array => [
                    'id' => $customer->id,
                    'phone' => $customer->phone,
                    'name' => $customer->name,
                    'email' => $customer->email,
                    'gstin' => $customer->gstin,
                ])
                ->all();

            $preview = $this->preview($sourceRows, $existing);
            $createdIds = [];

            foreach ($preview['rows'] as $index => $row) {
                if ($row['action'] !== 'CREATED') {
                    continue;
                }

                $customer = InventoryCustomer::query()->create([
                    'name' => $row['name'],
                    'phone' => $row['phone'],
                    'email' => $row['email'],
                    'gstin' => $row['gstin'],
                ]);
                $createdIds[] = [
                    'legacy_user_id' => $row['legacy_user_id'],
                    'id' => $customer->id,
                ];
                $preview['rows'][$index]['target_id'] = $customer->id;
            }

            $preview['created_ids'] = $createdIds;

            return $preview;
        });
    }
}

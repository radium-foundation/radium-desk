<?php

namespace Tests\Support;

use App\Support\Finance\LegacyCashContract;

final class LegacyCashApprovedHistory
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function rows(): array
    {
        $ids = [];
        for ($id = 1; $id <= 1773; $id++) {
            if (in_array($id, LegacyCashContract::EXCLUDED_LEGACY_IDS, true)) {
                continue;
            }
            $ids[] = $id;
        }

        $creditCount = LegacyCashContract::EXPECTED_CREDITS;
        $debitCount = LegacyCashContract::EXPECTED_DEBITS;
        $creditRemainder = (int) ((float) LegacyCashContract::EXPECTED_CREDIT_TOTAL - ($creditCount - 1));
        $debitRemainder = (int) ((float) LegacyCashContract::EXPECTED_DEBIT_TOTAL - ($debitCount - 1));

        $rows = [];
        foreach ($ids as $index => $id) {
            $isCredit = $index < $creditCount;
            if ($isCredit) {
                $amount = $index === $creditCount - 1 ? $creditRemainder : 1;
                $type = 'credit';
            } else {
                $debitIndex = $index - $creditCount;
                $amount = $debitIndex === $debitCount - 1 ? $debitRemainder : 1;
                $type = 'debit';
            }

            [$createdBy, $adminName] = self::actorFor($id, $index);

            $rows[] = [
                'id' => $id,
                'created_by' => $createdBy,
                'amount' => (string) $amount,
                'type' => $type,
                'amount_type' => $id === 157 ? 'asa' : 'Office expenses',
                'description' => self::descriptionFor($id),
                'created_at' => $id === 1773 ? LegacyCashContract::CUTOFF_AT : '2024-01-01 10:00:00',
                'updated_at' => '2024-01-01 10:00:00',
                'admin_name' => $adminName,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function mappingSample(): array
    {
        return [
            [
                'id' => 10,
                'created_by' => '4',
                'amount' => '500',
                'type' => 'debit',
                'amount_type' => 'Courier',
                'description' => 'Courier',
                'created_at' => '2024-02-01 09:00:00',
                'updated_at' => '2024-02-01 09:00:00',
                'admin_name' => 'Gunjan Kumar',
            ],
            [
                'id' => 11,
                'created_by' => '6',
                'amount' => '1200',
                'type' => 'credit',
                'amount_type' => 'Cash',
                'description' => 'Old Admin Balance Added',
                'created_at' => '2024-02-02 09:00:00',
                'updated_at' => '2024-02-02 09:00:00',
                'admin_name' => 'Rafaquat Rana',
            ],
            [
                'id' => 12,
                'created_by' => '10',
                'amount' => '800',
                'type' => 'debit',
                'amount_type' => 'Office expenses',
                'description' => 'Office tea',
                'created_at' => '2024-02-03 09:00:00',
                'updated_at' => '2024-02-03 09:00:00',
                'admin_name' => 'Avinash',
            ],
            [
                'id' => 157,
                'created_by' => '10',
                'amount' => '1307977',
                'type' => 'debit',
                'amount_type' => 'asa',
                'description' => '26.40 L Given to sir',
                'created_at' => '2024-08-30 12:00:00',
                'updated_at' => '2024-08-30 12:00:00',
                'admin_name' => 'Avinash',
            ],
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function actorFor(int $id, int $index): array
    {
        if ($id === 4 || $index === 0) {
            return ['4', 'Gunjan Kumar'];
        }

        if ($id === 6 || $index === 1) {
            return ['6', 'Rafaquat Rana'];
        }

        return ['10', 'Avinash'];
    }

    private static function descriptionFor(int $id): string
    {
        return match ($id) {
            157 => '26.40 L Given to sir',
            876 => "Given To Monika Ma'am",
            999 => 'May 2025 - Dileep Hand Transfer',
            1110 => "Given to Shipra Ma'am",
            default => 'Historical cash '.$id,
        };
    }
}

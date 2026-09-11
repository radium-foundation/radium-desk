<?php

namespace App\Services\Pos;

/**
 * P-161 buckets for historical Admin POS users (orders.ordertype = POS).
 *
 * A — unique phone + name + at least one address + GSTIN empty or unique 15-char
 * B — unique phone + name + no address + same GSTIN rules
 * C — duplicate phone, bad GSTIN length, duplicate 15-char GSTIN, or other unique-phone rejects
 * D — missing phone or name
 *
 * Mutually exclusive. The P-161 published C=121 overlapped 31 multi-address A rows.
 */
final class AdminPosCustomerImportClassifier
{
    public const CLASS_A = 'A';

    public const CLASS_B = 'B';

    public const CLASS_C = 'C';

    public const CLASS_D = 'D';

    /**
     * @param  list<array{
     *     legacy_user_id: int,
     *     name: ?string,
     *     phone: ?string,
     *     email: ?string,
     *     gstin: ?string,
     *     address_count: int
     * }>  $sourceRows
     * @return list<array{
     *     legacy_user_id: int,
     *     name: string,
     *     phone: string,
     *     email: ?string,
     *     gstin: ?string,
     *     address_count: int,
     *     class: string,
     *     reason: string
     * }>
     */
    public function classify(array $sourceRows): array
    {
        $normalized = [];
        foreach ($sourceRows as $row) {
            $phone = $this->normalizePhone($row['phone'] ?? null);
            $name = $this->nullableString($row['name'] ?? null) ?? '';
            $gstin = $this->normalizeGstin($row['gstin'] ?? null);
            $normalized[] = [
                'legacy_user_id' => (int) $row['legacy_user_id'],
                'name' => $name,
                'phone' => $phone,
                'email' => $this->nullableString($row['email'] ?? null),
                'gstin' => $gstin,
                'address_count' => max(0, (int) ($row['address_count'] ?? 0)),
            ];
        }

        $phoneCounts = [];
        $gstinCounts = [];
        foreach ($normalized as $row) {
            if ($row['phone'] !== '') {
                $phoneCounts[$row['phone']] = ($phoneCounts[$row['phone']] ?? 0) + 1;
            }
            if ($row['gstin'] !== null && strlen($row['gstin']) === 15) {
                $gstinCounts[$row['gstin']] = ($gstinCounts[$row['gstin']] ?? 0) + 1;
            }
        }

        $classified = [];
        foreach ($normalized as $row) {
            if ($row['phone'] === '' || $row['name'] === '') {
                $classified[] = $row + [
                    'class' => self::CLASS_D,
                    'reason' => $row['phone'] === '' ? 'missing_phone' : 'missing_name',
                ];

                continue;
            }

            if (($phoneCounts[$row['phone']] ?? 0) !== 1) {
                $classified[] = $row + [
                    'class' => self::CLASS_C,
                    'reason' => 'duplicate_phone',
                ];

                continue;
            }

            if ($row['gstin'] !== null && strlen($row['gstin']) !== 15) {
                $classified[] = $row + [
                    'class' => self::CLASS_C,
                    'reason' => 'gstin_length_not_15',
                ];

                continue;
            }

            if ($row['gstin'] !== null && ($gstinCounts[$row['gstin']] ?? 0) > 1) {
                $classified[] = $row + [
                    'class' => self::CLASS_C,
                    'reason' => 'duplicate_gstin',
                ];

                continue;
            }

            if ($row['address_count'] < 1) {
                $classified[] = $row + [
                    'class' => self::CLASS_B,
                    'reason' => 'unique_phone_no_address',
                ];

                continue;
            }

            $classified[] = $row + [
                'class' => self::CLASS_A,
                'reason' => 'unique_phone_with_address',
            ];
        }

        return $classified;
    }

    public function normalizePhone(?string $phone): string
    {
        return preg_replace('/\s+/', '', trim((string) $phone)) ?? '';
    }

    public function normalizeGstin(?string $gstin): ?string
    {
        $value = $this->nullableString($gstin);
        if ($value === null) {
            return null;
        }

        return strtoupper($value);
    }

    public function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}

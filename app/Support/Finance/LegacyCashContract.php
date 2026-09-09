<?php

namespace App\Support\Finance;

/**
 * Approved RadiumBox Admin → Desk Legacy Cash cutover contract.
 *
 * Historical rows stay off the live Cash Book and GL. The operational opening
 * is a single identifiable journal, never a restatement of the 1,765 rows.
 */
final class LegacyCashContract
{
    public const SOURCE_LABEL = 'RadiumBox Admin';

    public const LEGACY_DATABASE = 'radiumbox_prod';

    public const LEGACY_TABLE = 'expenses';

    public const CONNECTION = 'legacy_radiumbox';

    public const CUTOFF_AT = '2026-09-04 21:25:51';

    public const EXPECTED_ROWS = 1765;

    public const EXPECTED_CREDITS = 675;

    public const EXPECTED_DEBITS = 1090;

    public const EXPECTED_CREDIT_TOTAL = '16647589.00';

    public const EXPECTED_DEBIT_TOTAL = '16296575.00';

    public const EXPECTED_NET = '351014.00';

    public const OPENING_AMOUNT = '351014.00';

    public const OPENING_DATE = '2026-09-04';

    public const OPENING_MEMO = 'Opening balance — RadiumBox Admin legacy cash';

    public const OPENING_IDEMPOTENCY_KEY = 'legacy:radiumbox_prod:opening:351014';

    public const OPENING_LINE_DESCRIPTION = 'Opening cash — RadiumBox Admin legacy';

    /**
     * Hard-deleted Admin expense IDs. Do not fabricate these rows.
     *
     * @var list<int>
     */
    public const EXCLUDED_LEGACY_IDS = [1, 2, 22, 23, 24, 45, 123, 201];

    /**
     * Large historical debits preserved as facts and flagged for review.
     *
     * @var array<int, string>
     */
    public const REVIEW_REASONS = [
        157 => 'UNKNOWN: large debit; description (26.40 L) does not match row amount',
        876 => 'UNKNOWN: large debit given to Monika Ma\'am; no matching credit',
        999 => 'UNKNOWN: Dileep hand transfer; book overdrawn at that instant',
        1110 => 'UNKNOWN: large debit given to Shipra Ma\'am; Shipra book never credited',
    ];

    /**
     * Approved Admin → Desk mappings. Resolved by unique Desk name, never by numeric ID.
     *
     * @var array<string, array{exact?: string, name_prefix?: string}>
     */
    public const APPROVED_ADMIN_MAPS = [
        '4' => ['exact' => 'Gunjan Kumar'],
        '6' => ['name_prefix' => 'Rafaquat'],
    ];

    public static function idempotencyKey(int $legacyTransactionId): string
    {
        return 'legacy:'.self::LEGACY_DATABASE.':'.self::LEGACY_TABLE.':'.$legacyTransactionId;
    }

    public static function money(string|int|float $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}

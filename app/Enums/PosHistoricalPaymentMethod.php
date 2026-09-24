<?php

namespace App\Enums;

enum PosHistoricalPaymentMethod: string
{
    case HdfcD = 'hdfc_d';
    case HdfcM = 'hdfc_m';
    case Indus = 'indus';
    case Cash = 'cash';
    case UpiHdfc = 'upi_hdfc';
    case UpiIndus = 'upi_indus';
    case Card = 'card';
    case OtherBank = 'other_bank';
    case OtherUpi = 'other_upi';

    public function label(): string
    {
        return match ($this) {
            self::HdfcD => 'HDFC D',
            self::HdfcM => 'HDFC M',
            self::Indus => 'INDUS',
            self::Cash => 'CASH',
            self::UpiHdfc => 'UPI - HDFC',
            self::UpiIndus => 'UPI - INDUS',
            self::Card => 'CARD',
            self::OtherBank => 'OTHER BANK',
            self::OtherUpi => 'OTHER UPI',
        };
    }

    /**
     * @return list<string>
     */
    public static function labels(): array
    {
        return array_map(
            fn (self $method): string => $method->label(),
            self::cases(),
        );
    }

    /**
     * Methods allowed for the historical POS payment backfill workflow only.
     *
     * @return list<self>
     */
    public static function backfillCases(): array
    {
        return [
            self::HdfcD,
            self::HdfcM,
            self::Indus,
            self::Cash,
        ];
    }

    public function isAllowedForHistoricalBackfill(): bool
    {
        return in_array($this, self::backfillCases(), true);
    }

    public function requiresReference(): bool
    {
        return match ($this) {
            self::Cash => false,
            default => true,
        };
    }

    public function requiresBankName(): bool
    {
        return match ($this) {
            self::OtherBank => true,
            default => false,
        };
    }

    public function requiresBankBranch(): bool
    {
        return match ($this) {
            self::OtherBank => false,
            default => false,
        };
    }

    public function defaultBankName(): ?string
    {
        return match ($this) {
            self::HdfcD, self::HdfcM, self::UpiHdfc => 'HDFC Bank',
            self::Indus, self::UpiIndus => 'IndusInd Bank',
            default => null,
        };
    }
}

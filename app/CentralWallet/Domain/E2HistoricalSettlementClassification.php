<?php

namespace App\CentralWallet\Domain;

final class E2HistoricalSettlementClassification
{
    public const OWNER_APPROVED_HISTORICAL_MANUAL_REFUND_SETTLEMENT = 'OWNER_APPROVED_HISTORICAL_MANUAL_REFUND_SETTLEMENT';

    public const MIGRATION_TYPE = 'owner_approved_historical_manual_refund_settlement';

    public const FORENSIC_REPORT_REF = 'RadiumDesk-P-30-10-19';

    public const AUDIT_DISCLAIMER = 'The original spoke wallet destination could not be reconstructed from authoritative historical evidence. '
        .'Owner authorized settlement of the historical refund amount through the Central Wallet settlement mechanism. '
        .'This entry is NOT evidence that the original historical spoke wallet was credited.';
}

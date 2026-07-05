<?php

namespace App\Services\Operations;

use App\Data\Operations\CashfreeDeviceEnrichmentQualitySummary;
use App\Models\AuditLog;
use App\Models\Order;
use App\Services\RadiumBox\RadiumBoxSyncAuditService;
use Illuminate\Database\Eloquent\Builder;

class OperationsCashfreeDeviceEnrichmentService
{
    public function qualitySummary(): CashfreeDeviceEnrichmentQualitySummary
    {
        $paidOrdersMissingDeviceInfo = $this->paidOrdersMissingDeviceInfoQuery()->count();
        $needCustomerContact = $this->paidOrdersMissingDeviceInfoQuery()
            ->where(function (Builder $query): void {
                $query->where(function (Builder $inner): void {
                    $inner->whereNull('customer_name')
                        ->orWhere('customer_name', '');
                })->where(function (Builder $inner): void {
                    $inner->whereNull('customer_email')
                        ->orWhere('customer_email', '');
                })->where(function (Builder $inner): void {
                    $inner->whereNull('customer_phone')
                        ->orWhere('customer_phone', '');
                });
            })
            ->count();

        $recoveredFromRadiumBox = AuditLog::query()
            ->where('event', RadiumBoxSyncAuditService::EVENT_ENRICHMENT_COMPLETED)
            ->where('auditable_type', (new Order)->getMorphClass())
            ->whereNotNull('new_values->fields_applied')
            ->count();

        return new CashfreeDeviceEnrichmentQualitySummary(
            paidOrdersMissingDeviceInfo: $paidOrdersMissingDeviceInfo,
            recoveredFromRadiumBox: $recoveredFromRadiumBox,
            needCustomerContact: $needCustomerContact,
        );
    }

    /**
     * @return Builder<Order>
     */
    private function paidOrdersMissingDeviceInfoQuery(): Builder
    {
        return Order::query()
            ->cashfreeVerified()
            ->missingDeviceEnrichment();
    }
}

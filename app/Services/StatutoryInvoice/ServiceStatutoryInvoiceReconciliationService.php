<?php

namespace App\Services\StatutoryInvoice;

use App\Data\StatutoryInvoice\ServiceStatutoryInvoiceReconciliationResult;
use App\Enums\StatutoryInvoice\ServiceStatutoryInvoiceMintTrigger;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\Order;
use App\Models\StatutoryInvoice;
use App\Services\AutomationIdentityService;
use App\Services\HardwareFulfilment\HardwareCommerceStatutoryInvoiceGuard;
use App\Support\BusinessOrderId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

final class ServiceStatutoryInvoiceReconciliationService
{
    public function __construct(
        private readonly StatutoryMintEligibility $eligibility,
        private readonly ServiceStatutoryInvoiceWorkflowCompletionGate $workflowGate,
        private readonly ServiceStatutoryInvoiceIssuanceCoordinator $coordinator,
        private readonly AutomationIdentityService $automationIdentity,
        private readonly HardwareCommerceStatutoryInvoiceGuard $hardwareGuard,
    ) {}

    public function reconcile(?int $limit = null, bool $dryRun = false): ServiceStatutoryInvoiceReconciliationResult
    {
        $attemptLimit = $limit ?? max(1, (int) config('service_statutory_invoice.reconciliation.batch_limit', 100));
        $maxScan = max($attemptLimit, (int) config('service_statutory_invoice.reconciliation.max_scan_per_run', 10_000));
        $scanned = 0;
        $attempted = 0;
        $skipped = 0;
        $alreadyInvoiced = 0;
        $ineligible = 0;
        $noWorkflow = 0;

        $actor = $this->automationIdentity->systemUser();

        foreach ($this->candidateQuery()->cursor() as $commerce) {
            if ($scanned >= $maxScan) {
                break;
            }

            $scanned++;

            if ($this->hardwareGuard->requiresHardwareSerialPath($commerce)) {
                $skipped++;

                continue;
            }

            if (! $this->isOnlineServiceCommerceOrder($commerce)) {
                $skipped++;

                continue;
            }

            if ($this->invoiceExists($commerce)) {
                $alreadyInvoiced++;

                continue;
            }

            $supportOrder = $this->resolveSupportOrder($commerce);
            if ($supportOrder === null || ! $this->workflowGate->supportOrderHasCompletedWorkflow($supportOrder)) {
                $noWorkflow++;

                continue;
            }

            $decision = $this->eligibility->evaluateOrder($commerce);
            if (! $decision->eligible) {
                $ineligible++;

                continue;
            }

            if ($attempted >= $attemptLimit) {
                break;
            }

            if ($dryRun) {
                $attempted++;

                continue;
            }

            $this->coordinator->attempt(
                $supportOrder,
                $actor,
                ServiceStatutoryInvoiceMintTrigger::InvoiceReconciliation,
            );
            $attempted++;

            Log::info('service_statutory_invoice.reconciliation_attempt', [
                'commerce_order_pk' => $commerce->id,
                'source_channel' => $commerce->channel->value,
                'source_order_id' => $commerce->source_id,
                'support_order_pk' => $supportOrder->id,
                'trigger' => ServiceStatutoryInvoiceMintTrigger::InvoiceReconciliation->value,
            ]);
        }

        return new ServiceStatutoryInvoiceReconciliationResult(
            scanned: $scanned,
            attempted: $attempted,
            skipped: $skipped,
            alreadyInvoiced: $alreadyInvoiced,
            ineligible: $ineligible,
            noWorkflow: $noWorkflow,
            dryRun: $dryRun,
        );
    }

    private function candidateQuery(): Builder
    {
        return CommerceOrder::query()
            ->with(['items'])
            ->where('payment_status', 'paid')
            ->whereNull('statutory_invoice_id')
            ->whereIn('channel', [
                StatutoryInvoiceChannel::RdServiceIn->value,
                StatutoryInvoiceChannel::RdServiceNet->value,
                StatutoryInvoiceChannel::RadiumBoxCom->value,
            ])
            ->whereDoesntHave('hardwareFulfilment')
            ->where(function (Builder $query): void {
                $this->applyOnlineServiceSourceIdFilter($query);
            })
            ->orderBy('id');
    }

    /**
     * Narrow reconciliation to online service source ids so hardware/product rows
     * that have a separate fulfilment invoice path do not occupy scan budget.
     */
    private function applyOnlineServiceSourceIdFilter(Builder $query): void
    {
        $query->where(function (Builder $service) {
            $service->where(function (Builder $rd) {
                $rd->where('source_id', 'like', 'RD%')
                    ->where('source_id', 'not like', 'RDP%')
                    ->where('source_id', 'not like', 'RDE%')
                    ->where('source_id', 'not like', 'RIN%');
            })->orWhere(function (Builder $rn) {
                $rn->where('source_id', 'like', 'RN%')
                    ->where('source_id', 'not like', 'RNP%');
            })->orWhere(function (Builder $ra) {
                $ra->where('source_id', 'like', 'RA%');
            })->orWhere(function (Builder $rb) {
                $rb->where('source_id', 'like', 'RB%')
                    ->where('source_id', 'not like', 'RBP%')
                    ->where('source_id', 'not like', 'RDE%')
                    ->where('source_id', 'not like', 'RBX%');
            });
        });
    }

    private function isOnlineServiceCommerceOrder(CommerceOrder $commerce): bool
    {
        $sourceId = trim((string) $commerce->source_id);
        if ($sourceId === '') {
            return false;
        }

        $parsed = BusinessOrderId::parse($sourceId);
        if ($parsed === null) {
            return false;
        }

        return $parsed['kind'] === 'service' && ! $parsed['hardware'];
    }

    private function invoiceExists(CommerceOrder $commerce): bool
    {
        if ($commerce->statutory_invoice_id !== null) {
            return true;
        }

        return StatutoryInvoice::query()
            ->where('channel', $commerce->channel->value)
            ->where('source_type', StatutoryInvoiceSourceType::CommerceOrder->value)
            ->where('source_id', $commerce->source_id)
            ->exists();
    }

    private function resolveSupportOrder(CommerceOrder $commerce): ?Order
    {
        if ($commerce->support_order_id !== null) {
            return Order::query()->find($commerce->support_order_id);
        }

        return Order::query()
            ->where('order_id', $commerce->source_id)
            ->first();
    }
}

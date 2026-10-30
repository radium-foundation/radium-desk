<?php

namespace App\Services\StatutoryCoverage;

use App\Data\StatutoryCoverage\StatutoryCoverageOrderRow;
use App\Enums\StatutoryCoverage\StatutoryCoverageClassification;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\CommerceOrder;
use App\Models\StatutoryInvoice;

final class StatutoryCoverageOrderClassifier
{
    public function classify(
        StatutoryInvoiceChannel $channel,
        string $sourceId,
        bool $fromDeskPopulation,
        bool $fromSourcePopulation,
        ?string $spokeObligationClass = null,
    ): StatutoryCoverageOrderRow {
        $sourceType = (string) config('statutory_coverage.default_source_type', 'commerce_order');
        $normalized = strtoupper(trim($sourceId));

        $commerceRows = CommerceOrder::query()
            ->where('channel', $channel->value)
            ->where('source_type', $sourceType)
            ->where('source_id', $normalized)
            ->orderBy('id')
            ->get();

        if ($commerceRows->count() > 1) {
            return new StatutoryCoverageOrderRow(
                sourceId: $normalized,
                classification: StatutoryCoverageClassification::UnmatchedConflict,
                commerceOrderId: (int) $commerceRows->first()->id,
                statutoryInvoiceId: null,
                reason: 'Multiple commerce rows share channel+source_type+source_id.',
                fromDeskPopulation: $fromDeskPopulation,
                fromSourcePopulation: $fromSourcePopulation,
            );
        }

        $commerce = $commerceRows->first();

        $issuedInvoice = $this->resolveIssuedInvoice($channel, $normalized, $commerce);

        if ($issuedInvoice !== null) {
            $syncState = $this->spokeSyncState($channel, $commerce);
            if ($syncState === 'pending') {
                return new StatutoryCoverageOrderRow(
                    sourceId: $normalized,
                    classification: StatutoryCoverageClassification::IssuedSyncPending,
                    commerceOrderId: $commerce?->id !== null ? (int) $commerce->id : null,
                    statutoryInvoiceId: $issuedInvoice,
                    reason: 'Statutory invoice issued; spoke synchronization explicitly pending.',
                    fromDeskPopulation: $fromDeskPopulation,
                    fromSourcePopulation: $fromSourcePopulation,
                );
            }

            if ($syncState === 'unknown') {
                return new StatutoryCoverageOrderRow(
                    sourceId: $normalized,
                    classification: StatutoryCoverageClassification::IssuedSyncUnknown,
                    commerceOrderId: $commerce?->id !== null ? (int) $commerce->id : null,
                    statutoryInvoiceId: $issuedInvoice,
                    reason: 'Statutory invoice issued; spoke synchronization state is unknown (no positive evidence).',
                    fromDeskPopulation: $fromDeskPopulation,
                    fromSourcePopulation: $fromSourcePopulation,
                );
            }

            return new StatutoryCoverageOrderRow(
                sourceId: $normalized,
                classification: StatutoryCoverageClassification::IssuedSynchronized,
                commerceOrderId: $commerce?->id !== null ? (int) $commerce->id : null,
                statutoryInvoiceId: $issuedInvoice,
                reason: 'Statutory invoice issued.',
                fromDeskPopulation: $fromDeskPopulation,
                fromSourcePopulation: $fromSourcePopulation,
            );
        }

        if ($commerce !== null) {
            if ((bool) $commerce->invoice_eligible
                && $commerce->payment_status === 'paid'
                && $commerce->statutory_invoice_id === null) {
                return new StatutoryCoverageOrderRow(
                    sourceId: $normalized,
                    classification: StatutoryCoverageClassification::EligibleMissingInvoice,
                    commerceOrderId: (int) $commerce->id,
                    statutoryInvoiceId: null,
                    reason: 'Commerce is invoice-eligible and paid but has no statutory invoice.',
                    fromDeskPopulation: $fromDeskPopulation,
                    fromSourcePopulation: $fromSourcePopulation,
                );
            }

            return new StatutoryCoverageOrderRow(
                sourceId: $normalized,
                classification: StatutoryCoverageClassification::IneligibleCommerce,
                commerceOrderId: (int) $commerce->id,
                statutoryInvoiceId: null,
                reason: $this->truncateReason((string) ($commerce->status_reason ?? 'Commerce is not invoice-eligible.')),
                fromDeskPopulation: $fromDeskPopulation,
                fromSourcePopulation: $fromSourcePopulation,
            );
        }

        if ($fromSourcePopulation && ! $fromDeskPopulation && $spokeObligationClass !== null
            && ! $this->isObligatingPaidSourceClass($spokeObligationClass)) {
            return new StatutoryCoverageOrderRow(
                sourceId: $normalized,
                classification: StatutoryCoverageClassification::SourceNonObligating,
                commerceOrderId: null,
                statutoryInvoiceId: null,
                reason: 'Spoke source obligation class: '.$spokeObligationClass.'.',
                fromDeskPopulation: $fromDeskPopulation,
                fromSourcePopulation: $fromSourcePopulation,
            );
        }

        return new StatutoryCoverageOrderRow(
            sourceId: $normalized,
            classification: StatutoryCoverageClassification::MissingCommerce,
            commerceOrderId: null,
            statutoryInvoiceId: null,
            reason: 'No Desk commerce row for channel+source_type+source_id.',
            fromDeskPopulation: $fromDeskPopulation,
            fromSourcePopulation: $fromSourcePopulation,
        );
    }

    private function resolveIssuedInvoice(StatutoryInvoiceChannel $channel, string $sourceId, ?CommerceOrder $commerce): ?int
    {
        if ($commerce !== null && $commerce->statutory_invoice_id !== null) {
            return (int) $commerce->statutory_invoice_id;
        }

        $invoice = StatutoryInvoice::query()
            ->where('channel', $channel->value)
            ->where('source_type', StatutoryInvoiceSourceType::CommerceOrder->value)
            ->where('source_id', $sourceId)
            ->where('status', StatutoryInvoiceStatus::Issued->value)
            ->orderByDesc('id')
            ->first();

        return $invoice !== null ? (int) $invoice->id : null;
    }

    /**
     * @return 'synchronized'|'pending'|'unknown'|'not_applicable'
     */
    private function spokeSyncState(StatutoryInvoiceChannel $channel, ?CommerceOrder $commerce): string
    {
        $config = config('statutory_coverage.channels.'.$channel->value, []);
        if (! is_array($config) || ! (bool) ($config['spoke_sync_capable'] ?? false)) {
            return 'not_applicable';
        }

        $metadata = is_array($commerce?->metadata) ? $commerce->metadata : [];
        $coverage = is_array($metadata['statutory_coverage'] ?? null) ? $metadata['statutory_coverage'] : [];
        $explicit = $coverage['spoke_sync'] ?? null;
        if ($explicit === 'synchronized') {
            return 'synchronized';
        }
        if ($explicit === 'pending') {
            return 'pending';
        }

        return 'unknown';
    }

    private function truncateReason(string $reason): string
    {
        $reason = trim($reason);

        return mb_strlen($reason) > 160 ? mb_substr($reason, 0, 157).'...' : $reason;
    }

    private function isObligatingPaidSourceClass(?string $spokeObligationClass): bool
    {
        return in_array($spokeObligationClass, [
            'paid_completed_service',
            'paid_completed_hardware',
        ], true);
    }
}

<?php

namespace App\Reports\CaMonthly;

use App\Models\CommerceOrder;
use App\Models\HardwareFulfilmentPaymentEvidence;
use App\Models\InventorySale;
use App\Models\Order;
use App\Models\StatutoryInvoice;
use App\Support\Inventory\PosSalePaymentState;

/**
 * CA-facing normalized payment channel for statutory invoice register exports.
 *
 * Primary channel vocabulary: CF, HDFC M, HDFC D, Cash, Unpaid, Partial Paid.
 * Underlying instrument/provider/reference remain in source data for internal reconciliation.
 */
final class CaMonthlyReportPaymentChannelResolver
{
    public const CHANNEL_CF = 'CF';

    public const CHANNEL_HDFC_M = 'HDFC M';

    public const CHANNEL_HDFC_D = 'HDFC D';

    public const CHANNEL_CASH = 'Cash';

    public const CHANNEL_UNPAID = 'Unpaid';

    public const CHANNEL_PARTIAL_PAID = 'Partial Paid';

    /**
     * @var list<string>
     */
    private const GATEWAY_PROVIDER_ALIASES = [
        'cashfree',
        'payumoney',
        'payu',
        'razorpay',
    ];

    public function __construct(
        private readonly CaMonthlyReportPaidAmountResolver $paidAmounts,
    ) {}

    /**
     * @param  iterable<int, StatutoryInvoice>  $invoices
     * @return array<int, Order>
     */
    public function supportOrdersForInvoices(iterable $invoices): array
    {
        $supportOrderIds = [];
        foreach ($invoices as $invoice) {
            if ($invoice->support_order_id !== null) {
                $supportOrderIds[] = (int) $invoice->support_order_id;
            }
        }

        if ($supportOrderIds === []) {
            return [];
        }

        $orders = Order::query()
            ->whereIn('id', array_values(array_unique($supportOrderIds)))
            ->get()
            ->keyBy('id');

        $byInvoice = [];
        foreach ($invoices as $invoice) {
            if ($invoice->support_order_id === null) {
                continue;
            }

            $order = $orders->get((int) $invoice->support_order_id);
            if ($order !== null) {
                $byInvoice[$invoice->id] = $order;
            }
        }

        return $byInvoice;
    }

    /**
     * @param  iterable<int, StatutoryInvoice>  $invoices
     * @return array<int, HardwareFulfilmentPaymentEvidence>
     */
    public function hardwareEvidenceForInvoices(iterable $invoices): array
    {
        $sourceIds = [];
        foreach ($invoices as $invoice) {
            $sourceId = $this->nullableString($invoice->source_order_id)
                ?? $this->nullableString($invoice->source_id);
            if ($sourceId !== null) {
                $sourceIds[strtoupper($sourceId)] = true;
            }
        }

        if ($sourceIds === []) {
            return [];
        }

        $rows = HardwareFulfilmentPaymentEvidence::query()
            ->where('verified', true)
            ->whereIn('source_id', array_keys($sourceIds))
            ->orderBy('id')
            ->get();

        $bySource = [];
        foreach ($rows as $row) {
            $sourceId = strtoupper((string) $row->source_id);
            if ($sourceId !== '' && ! isset($bySource[$sourceId])) {
                $bySource[$sourceId] = $row;
            }
        }

        $byInvoice = [];
        foreach ($invoices as $invoice) {
            $sourceId = strtoupper((string) ($this->nullableString($invoice->source_order_id)
                ?? $this->nullableString($invoice->source_id)
                ?? ''));
            if ($sourceId !== '' && isset($bySource[$sourceId])) {
                $byInvoice[$invoice->id] = $bySource[$sourceId];
            }
        }

        return $byInvoice;
    }

    public function resolvePaymentChannelDisplay(
        StatutoryInvoice $invoice,
        ?CommerceOrder $commerceOrder = null,
        ?Order $supportOrder = null,
        ?HardwareFulfilmentPaymentEvidence $hardwareEvidence = null,
        float $allocationTotal = 0.0,
        ?InventorySale $inventorySale = null,
    ): string {
        $invoiceValue = round((float) $invoice->invoice_value, 2);
        $verifiedPaid = $this->paidAmounts->resolveVerifiedPaidAmount(
            $invoice,
            $commerceOrder,
            $supportOrder,
            $allocationTotal,
            $inventorySale,
        );

        if ($this->isUnpaid($invoice, $commerceOrder, $supportOrder, $hardwareEvidence, $allocationTotal, $inventorySale, $verifiedPaid)) {
            return self::CHANNEL_UNPAID;
        }

        if ($this->paidAmounts->isPartiallyPaid($verifiedPaid, $invoiceValue)) {
            return self::CHANNEL_PARTIAL_PAID;
        }

        if ($this->hasCashfreeGatewayEvidence($invoice, $commerceOrder, $supportOrder, $hardwareEvidence)) {
            return self::CHANNEL_CF;
        }

        $directChannel = $this->resolveDirectChannel(
            $invoice,
            $commerceOrder,
            $supportOrder,
            $hardwareEvidence,
            $inventorySale,
        );
        if ($directChannel !== null) {
            return $directChannel;
        }

        return '';
    }

    public function resolvePaymentReferenceDisplay(
        StatutoryInvoice $invoice,
        ?CommerceOrder $commerceOrder = null,
        ?Order $supportOrder = null,
        ?HardwareFulfilmentPaymentEvidence $hardwareEvidence = null,
        ?InventorySale $inventorySale = null,
    ): string {
        foreach ([
            $this->exportableReference($invoice->payment_reference),
            $this->exportableReference($commerceOrder?->payment_reference),
            $this->exportableReference($supportOrder?->transaction_id),
            $this->exportableReference($hardwareEvidence?->cashfree_payment_id),
            $this->exportableReference($supportOrder?->cashfree_payment_id),
            $this->exportableReference($inventorySale?->payment_reference),
        ] as $candidate) {
            if ($candidate !== null && $candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    private function isUnpaid(
        StatutoryInvoice $invoice,
        ?CommerceOrder $commerceOrder,
        ?Order $supportOrder,
        ?HardwareFulfilmentPaymentEvidence $hardwareEvidence,
        float $allocationTotal,
        ?InventorySale $inventorySale,
        float $verifiedPaid,
    ): bool {
        if (PosSalePaymentState::displaysAsPaymentPending($inventorySale)) {
            return true;
        }

        if ($verifiedPaid > 0) {
            return false;
        }

        if ($allocationTotal > 0) {
            return false;
        }

        if ($this->hasCashfreeGatewayEvidence($invoice, $commerceOrder, $supportOrder, $hardwareEvidence)) {
            return false;
        }

        $rawMethods = [
            $invoice->payment_method,
            $commerceOrder?->payment_method,
            $supportOrder?->payment_method,
            $hardwareEvidence?->payment_method,
            $inventorySale?->payment_method,
        ];

        foreach ($rawMethods as $method) {
            if ($this->nullableString($method) !== null) {
                return false;
            }
        }

        $references = [
            $invoice->payment_reference,
            $commerceOrder?->payment_reference,
            $supportOrder?->transaction_id,
            $hardwareEvidence?->cashfree_payment_id,
            $supportOrder?->cashfree_payment_id,
        ];

        foreach ($references as $reference) {
            if ($this->exportableReference($reference) !== null) {
                return false;
            }
        }

        return true;
    }

    private function hasCashfreeGatewayEvidence(
        StatutoryInvoice $invoice,
        ?CommerceOrder $commerceOrder,
        ?Order $supportOrder,
        ?HardwareFulfilmentPaymentEvidence $hardwareEvidence,
    ): bool {
        foreach ([
            $invoice->payment_method,
            $commerceOrder?->payment_method,
            $supportOrder?->payment_method,
            $hardwareEvidence?->payment_method,
        ] as $value) {
            if ($this->isGatewayProvider($value, 'cashfree')) {
                return true;
            }
        }

        if ($this->nullableString($hardwareEvidence?->cashfree_payment_id) !== null) {
            return true;
        }

        if ($this->nullableString($supportOrder?->cashfree_payment_id) !== null) {
            return true;
        }

        return false;
    }

    private function resolveDirectChannel(
        StatutoryInvoice $invoice,
        ?CommerceOrder $commerceOrder,
        ?Order $supportOrder,
        ?HardwareFulfilmentPaymentEvidence $hardwareEvidence,
        ?InventorySale $inventorySale,
    ): ?string {
        foreach ([
            $hardwareEvidence?->payment_method,
            $supportOrder?->payment_method,
            $commerceOrder?->payment_method,
            $invoice->payment_method,
            $inventorySale?->payment_method,
        ] as $value) {
            $channel = $this->directChannelFromRawMethod($value);
            if ($channel !== null) {
                return $channel;
            }
        }

        return null;
    }

    private function directChannelFromRawMethod(mixed $value): ?string
    {
        $trimmed = $this->nullableString($value);
        if ($trimmed === null) {
            return null;
        }

        if ($this->isGatewayProvider($trimmed)) {
            return null;
        }

        $normalized = strtolower(str_replace(['_', '-'], ' ', $trimmed));

        return match ($normalized) {
            'hdfc m', 'hdfc_m' => self::CHANNEL_HDFC_M,
            'hdfc d', 'hdfc_d' => self::CHANNEL_HDFC_D,
            'cash' => self::CHANNEL_CASH,
            default => null,
        };
    }

    private function isGatewayProvider(mixed $value, ?string $only = null): bool
    {
        $trimmed = $this->nullableString($value);
        if ($trimmed === null) {
            return false;
        }

        $normalized = strtolower(str_replace(['_', '-'], ' ', $trimmed));
        $aliases = $only !== null ? [$only] : self::GATEWAY_PROVIDER_ALIASES;

        foreach ($aliases as $provider) {
            if ($normalized === $provider || str_contains($normalized, $provider)) {
                return true;
            }
        }

        return false;
    }

    private function exportableReference(mixed $value): ?string
    {
        $trimmed = $this->nullableString($value);
        if ($trimmed === null) {
            return null;
        }

        if ($trimmed === PosSalePaymentState::PAYMENT_PENDING_REFERENCE) {
            return null;
        }

        return $trimmed;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}

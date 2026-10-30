<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoice\ServiceStatutoryInvoiceMintTrigger;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\Order;
use App\Models\StatutoryInvoice;
use App\Support\BusinessOrderId;
use Illuminate\Support\Facades\Log;

/**
 * Unified gate for workflow, reconciliation, and outbox automatic service statutory mint.
 * Manual Finance Hub issuance via issueFromCommerceOrder is intentionally out of scope.
 */
final class ServiceStatutoryAutoIssuancePolicy
{
    private const MODE_ALLOW_AUTOMATIC = 'allow_automatic';

    private const MODE_REQUIRE_EXPLICIT_APPROVAL = 'require_explicit_approval';

    public function assertAutomaticIssuanceAllowed(
        Order $order,
        ServiceStatutoryInvoiceMintTrigger $trigger,
    ): void {
        if (! (bool) config('service_statutory_invoice.auto_issuance.enforce', true)) {
            return;
        }

        if ($this->invoiceAlreadyExists($order)) {
            return;
        }

        $sourceId = trim((string) $order->order_id);
        if ($sourceId === '') {
            return;
        }

        $channel = $this->resolveChannel($sourceId);
        if ($this->channelMode($channel) !== self::MODE_REQUIRE_EXPLICIT_APPROVAL) {
            return;
        }

        $commerce = CommerceOrder::query()
            ->where('channel', $channel)
            ->where('source_type', StatutoryInvoiceSourceType::CommerceOrder->value)
            ->where('source_id', $sourceId)
            ->first();

        if ($commerce === null) {
            $this->deny(
                order: $order,
                trigger: $trigger,
                channel: $channel,
                classification: 'auto_issuance_commerce_unlinked',
                message: sprintf(
                    'Automatic statutory invoice issuance for %s (%s) is blocked: no linked commerce order.',
                    $sourceId,
                    $channel->value,
                ),
            );
        }

        if ($commerce->statutory_auto_issue_approved_at !== null) {
            return;
        }

        $this->deny(
            order: $order,
            trigger: $trigger,
            channel: $channel,
            classification: 'auto_issuance_approval_required',
            message: sprintf(
                'Automatic statutory invoice issuance for %s (%s) is blocked until explicit per-order approval.',
                $sourceId,
                $channel->value,
            ),
        );
    }

    private function deny(
        Order $order,
        ServiceStatutoryInvoiceMintTrigger $trigger,
        StatutoryInvoiceChannel $channel,
        string $classification,
        string $message,
    ): never {
        Log::warning('service_statutory_invoice.auto_issuance_denied', [
            'order_pk' => $order->id,
            'order_id' => $order->order_id,
            'trigger' => $trigger->value,
            'channel' => $channel->value,
            'classification' => $classification,
        ]);

        throw new ServiceStatutoryInvoicePermanentFailureException(
            message: $message,
            classification: $classification,
        );
    }

    private function channelMode(StatutoryInvoiceChannel $channel): string
    {
        $defaults = config('service_statutory_invoice.auto_issuance.channel_defaults', []);
        $mode = $defaults[$channel->value] ?? self::MODE_ALLOW_AUTOMATIC;

        return $mode === self::MODE_REQUIRE_EXPLICIT_APPROVAL
            ? self::MODE_REQUIRE_EXPLICIT_APPROVAL
            : self::MODE_ALLOW_AUTOMATIC;
    }

    private function invoiceAlreadyExists(Order $order): bool
    {
        $sourceId = trim((string) $order->order_id);
        if ($sourceId === '') {
            return false;
        }

        return StatutoryInvoice::query()
            ->where('channel', $this->resolveChannel($sourceId)->value)
            ->where('source_type', StatutoryInvoiceSourceType::CommerceOrder->value)
            ->where('source_id', $sourceId)
            ->exists();
    }

    private function resolveChannel(string $sourceId): StatutoryInvoiceChannel
    {
        if (BusinessOrderId::isRadiumBoxService($sourceId)) {
            return StatutoryInvoiceChannel::RadiumBoxCom;
        }

        $parsed = BusinessOrderId::parse($sourceId);
        if ($parsed !== null
            && $parsed['owner'] === 'rdservice.net'
            && $parsed['kind'] === 'service') {
            return StatutoryInvoiceChannel::RdServiceNet;
        }

        return StatutoryInvoiceChannel::RdServiceIn;
    }
}

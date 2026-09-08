<?php

namespace App\Services\ChannelIngest;

use App\Enums\ChannelIngestOutcome;
use App\Enums\CommerceOrderStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelIngestAttempt;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\FinanceJournal;
use App\Models\StatutoryInvoice;
use App\Services\ChannelIngest\Data\ChannelIngestResult;
use App\Services\ChannelIngest\Data\ChannelOrderIngestRequest;
use App\Services\HardwareFulfilment\HardwareFulfilmentFoundationService;
use App\Services\HardwareFulfilment\HardwareHandoffTenderContract;
use App\Services\StatutoryInvoice\ServiceSacResolver;
use App\Services\StatutoryInvoice\StatutoryInvoiceAccountingPolicy;
use App\Services\StatutoryInvoice\StatutoryInvoiceNumberingService;
use App\Services\StatutoryInvoice\StatutoryInvoiceScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ChannelIngestService
{
    public const ATTEMPTS = 5;

    public function __construct(
        private readonly ChannelIngestPayloadValidator $validator,
        private readonly ChannelIngestPayloadHasher $hasher,
        private readonly HardwareFulfilmentFoundationService $hardwareFulfilments,
        private readonly StatutoryInvoiceNumberingService $numbering,
        private readonly StatutoryInvoiceAccountingPolicy $accounting,
        private readonly ServiceSacResolver $serviceSac,
        private readonly HardwareHandoffTenderContract $tenders = new HardwareHandoffTenderContract,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function ingest(
        array $payload,
        StatutoryInvoiceChannel $authenticatedChannel,
        ?string $remoteIp = null,
        ?string $rawHash = null,
        ?string $idempotencyHeader = null,
    ): ChannelIngestResult {
        $this->assertMustNotAutoMint();

        try {
            $request = $this->validator->validate($payload, $authenticatedChannel);
            $this->tenders->assertExistingCashfree($request);
        } catch (ValidationException $exception) {
            $this->recordAttempt(
                outcome: ChannelIngestOutcome::Rejected,
                httpStatus: 422,
                channel: $authenticatedChannel,
                sourceType: is_string($payload['source_type'] ?? null) ? (string) $payload['source_type'] : null,
                sourceId: is_string($payload['source_id'] ?? null) ? (string) $payload['source_id'] : null,
                error: 'Payload validation failed.',
                remoteIp: $remoteIp,
                payloadHash: $rawHash,
            );

            throw $exception;
        }

        if ($idempotencyHeader !== null && $idempotencyHeader !== '' && $idempotencyHeader !== $request->idempotencyKey()) {
            $this->recordAttempt(
                outcome: ChannelIngestOutcome::Rejected,
                httpStatus: 422,
                channel: $authenticatedChannel,
                sourceType: $request->sourceType->value,
                sourceId: $request->sourceId,
                error: 'Idempotency-Key must equal statutory:{channel}:{source_type}:{source_id}.',
                remoteIp: $remoteIp,
                payloadHash: $rawHash,
                idempotencyKey: $request->idempotencyKey(),
            );

            throw ValidationException::withMessages([
                'Idempotency-Key' => 'Idempotency-Key must equal statutory:{channel}:{source_type}:{source_id}. Do not send a second key.',
            ]);
        }

        try {
            return DB::transaction(function () use ($request, $remoteIp): ChannelIngestResult {
                $existing = $this->findBySource($request);
                $hash = $this->hasher->hash($request);

                if ($existing !== null) {
                    if (! $this->hasher->matchesStored((string) $existing->payload_hash, $request)) {
                        $result = new ChannelIngestResult(
                            outcome: ChannelIngestOutcome::Conflict,
                            httpStatus: 409,
                            order: $existing,
                            error: 'Source already ingested with a different payload.',
                        );
                        $this->recordAttemptFromRequest($request, $result, $remoteIp, $hash);

                        return $result;
                    }

                    $this->hardwareFulfilments->ensureIngested($existing, $request);

                    $result = new ChannelIngestResult(
                        outcome: ChannelIngestOutcome::Duplicate,
                        httpStatus: 200,
                        order: $existing->load('items'),
                        duplicate: true,
                    );
                    $this->recordAttemptFromRequest($request, $result, $remoteIp, $hash);

                    return $result;
                }

                $order = $this->createOrder($request, $hash);
                $this->hardwareFulfilments->ensureIngested($order, $request);
                $this->assertNoFinanceOrInvoiceSideEffects($order);

                $result = new ChannelIngestResult(
                    outcome: ChannelIngestOutcome::Accepted,
                    httpStatus: 201,
                    order: $order,
                );
                $this->recordAttemptFromRequest($request, $result, $remoteIp, $hash);

                return $result;
            }, self::ATTEMPTS);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->findBySource($request);
            if ($existing !== null && $this->hasher->matchesStored((string) $existing->payload_hash, $request)) {
                $this->hardwareFulfilments->ensureIngested($existing, $request);
                $result = new ChannelIngestResult(
                    outcome: ChannelIngestOutcome::Duplicate,
                    httpStatus: 200,
                    order: $existing->load('items'),
                    duplicate: true,
                );
                $this->recordAttemptFromRequest($request, $result, $remoteIp, $this->hasher->hash($request));

                return $result;
            }

            throw $exception;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->recordAttemptFromRequest(
                $request,
                new ChannelIngestResult(
                    outcome: ChannelIngestOutcome::Failed,
                    httpStatus: 500,
                    error: 'Channel ingest failed.',
                ),
                $remoteIp,
                $this->hasher->hash($request),
            );

            Log::error('[Channel ingest] Processing failed', [
                'channel' => $request->channel->value,
                'source_type' => $request->sourceType->value,
                'source_id' => $request->sourceId,
                'exception' => $exception::class,
            ]);

            throw $exception;
        }
    }

    public function findBySource(ChannelOrderIngestRequest $request): ?CommerceOrder
    {
        return CommerceOrder::query()
            ->where('channel', $request->channel->value)
            ->where('source_type', $request->sourceType->value)
            ->where('source_id', $request->sourceId)
            ->first();
    }

    private function createOrder(ChannelOrderIngestRequest $request, string $hash): CommerceOrder
    {
        $eligibility = $this->eligibility($request);
        $status = $eligibility['eligible']
            ? CommerceOrderStatus::InvoicePending
            : CommerceOrderStatus::Validated;

        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-TMP-'.bin2hex(random_bytes(8)),
            'channel' => $request->channel,
            'source_type' => $request->sourceType->value,
            'source_id' => $request->sourceId,
            'source_order_id' => $request->sourceOrderId,
            'idempotency_key' => $request->idempotencyKey(),
            'payload_hash' => $hash,
            'status' => $status,
            'invoice_eligible' => $eligibility['eligible'],
            'payment_status' => $request->paymentStatus,
            'payment_provider' => $request->paymentProvider,
            'payment_reference' => $request->paymentReference,
            'payment_method' => $request->paymentMethod,
            'currency' => $request->currency,
            'customer_name' => $request->customerName,
            'customer_phone' => $request->customerPhone,
            'customer_email' => $request->customerEmail,
            'buyer_gstin' => $request->buyerGstin,
            'billing_address' => $request->billingAddress,
            'billing_state' => $request->billingState,
            'billing_address_structured' => $request->billingAddressStructured,
            'shipping_address' => $request->shippingAddress,
            'shipping_address_structured' => $request->shippingAddressStructured,
            'parcel' => $request->parcel,
            'seller_gstin' => $request->sellerGstin,
            'seller_name' => $request->sellerName,
            'branch_code' => $request->branchCode,
            'place_of_supply_state' => $request->placeOfSupplyState,
            'taxable_value' => $eligibility['taxable_value'],
            'discount' => $eligibility['discount'],
            'tax_total' => $eligibility['tax_total'],
            'order_value' => $eligibility['order_value'],
            'wallet_tender_amount' => $this->tenders->walletAmount($request),
            'wallet_tender_reference' => $this->tenders->walletReference($request),
            'metadata' => $request->metadata,
            'ordered_at' => $request->orderedAt,
            'paid_at' => $request->paidAt,
            'received_at' => now(),
            'status_reason' => $eligibility['reason'],
            'support_order_id' => $request->supportOrderId,
        ]);

        $order->update([
            'order_no' => sprintf('CO-%06d', $order->id),
        ]);

        foreach ($request->lines as $index => $line) {
            CommerceOrderItem::query()->create([
                'commerce_order_id' => $order->id,
                'line_no' => $index + 1,
                'sku' => $line->sku,
                'shipping_line_kind' => $line->shippingLineKind,
                'requires_shipping' => $line->requiresShipping,
                'product_id' => $line->productId,
                'model_id' => $line->modelId,
                'catalog_sku' => $line->catalogSku,
                'rdserviceid' => $line->rdserviceid,
                'amcid' => $line->amcid,
                'otgid' => $line->otgid,
                'variant' => $line->variant,
                'description' => $line->description,
                'hsn_sac' => $this->serviceSac->resolve(
                    $request->channel->value,
                    $line->sku,
                    $line->description,
                    $line->hsnSac,
                    $line->amcid,
                ),
                'qty' => $line->qty,
                'unit_price' => $line->unitPrice,
                'discount' => $line->discount,
                'gst_percentage' => $line->gstPercentage,
                'taxable_value' => $line->taxableValue,
                'tax_total' => $line->taxTotal,
                'cgst' => $line->cgst,
                'sgst' => $line->sgst,
                'igst' => $line->igst,
                'line_total' => $line->lineTotal,
            ]);
        }

        return $order->fresh(['items']) ?? $order;
    }

    /**
     * @return array{eligible: bool, reason: string, taxable_value: ?float, discount: ?float, tax_total: ?float, order_value: ?float}
     */
    private function eligibility(ChannelOrderIngestRequest $request): array
    {
        $missing = [];
        if ($request->paymentStatus !== 'paid') {
            $missing[] = 'payment is not paid';
        }
        if ($request->sellerGstin === null) {
            $missing[] = 'seller GSTIN';
        }
        if ($request->placeOfSupplyState === null) {
            $missing[] = 'place of supply';
        }
        if (! StatutoryInvoiceScope::contains($this->commercialDate($request))) {
            $missing[] = 'commercial date is before 2026-09-01';
        }

        $taxable = 0.0;
        $tax = 0.0;
        $discount = $request->discount ?? 0.0;
        $lineTotal = 0.0;
        $hasTaxable = true;
        $hasTax = true;
        $hasLineTotal = true;
        $hasDiscountLines = $request->discount !== null;

        foreach ($request->lines as $line) {
            if ($line->hsnSac === null) {
                $missing[] = 'HSN/SAC';
            }
            if ($line->taxableValue === null) {
                $hasTaxable = false;
            } else {
                $taxable += $line->taxableValue;
            }
            if ($line->taxTotal === null) {
                $hasTax = false;
            } else {
                $tax += $line->taxTotal;
            }
            if ($line->lineTotal === null) {
                $hasLineTotal = false;
            } else {
                $lineTotal += $line->lineTotal;
            }
            if ($line->discount !== null) {
                $hasDiscountLines = true;
                $discount += $line->discount;
            }
        }

        $missing = array_values(array_unique($missing));
        $eligible = $missing === [];

        $reason = $eligible
            ? ($this->numbering->isConfigured()
                ? 'Eligible but statutory invoice was not minted: auto-issue is disabled until CA cutover.'
                : 'Eligible but statutory invoice was not minted: legal series unset and channel cutover is not approved.')
            : 'Accepted. Invoice eligibility incomplete: '.implode(', ', $missing).'. Missing tax fields were not invented.';

        return [
            'eligible' => $eligible,
            'reason' => $reason,
            'taxable_value' => $hasTaxable ? round($taxable, 2) : null,
            'discount' => $hasDiscountLines ? round($discount, 2) : null,
            'tax_total' => $hasTax ? round($tax, 2) : null,
            'order_value' => $hasLineTotal ? round($lineTotal - ($request->discount ?? 0), 2) : null,
        ];
    }

    private function commercialDate(ChannelOrderIngestRequest $request): ?Carbon
    {
        foreach ([$request->orderedAt, $request->paidAt] as $raw) {
            if (is_string($raw) && trim($raw) !== '') {
                return Carbon::parse($raw, config('app.timezone'));
            }
        }

        return now();
    }

    private function assertMustNotAutoMint(): void
    {
        if (config('channel_ingest.auto_issue_invoice')) {
            throw ValidationException::withMessages([
                'invoice' => 'Auto-issuing a statutory invoice on channel ingest is disabled until CA numbering and cutover are approved.',
            ]);
        }

        $this->accounting->assertJournalsMustNotPost();
    }

    private function assertNoFinanceOrInvoiceSideEffects(CommerceOrder $order): void
    {
        if ($order->statutory_invoice_id !== null) {
            throw ValidationException::withMessages([
                'invoice' => 'Channel ingest must not mint a statutory invoice in this foundation.',
            ]);
        }

        $invoices = StatutoryInvoice::query()
            ->where('channel', $order->channel->value)
            ->where('source_type', $order->source_type)
            ->where('source_id', $order->source_id)
            ->count();
        if ($invoices > 0) {
            throw ValidationException::withMessages([
                'invoice' => 'Channel ingest must not mint a statutory invoice in this foundation.',
            ]);
        }

        $journals = FinanceJournal::query()
            ->where('source_type', 'commerce_order')
            ->where('source_id', $order->id)
            ->count();
        if ($journals > 0) {
            throw ValidationException::withMessages([
                'finance' => 'Channel ingest must not post finance journals.',
            ]);
        }
    }

    private function recordAttemptFromRequest(
        ChannelOrderIngestRequest $request,
        ChannelIngestResult $result,
        ?string $remoteIp,
        string $hash,
    ): void {
        $this->recordAttempt(
            outcome: $result->outcome,
            httpStatus: $result->httpStatus,
            channel: $request->channel,
            sourceType: $request->sourceType->value,
            sourceId: $request->sourceId,
            error: $result->error,
            remoteIp: $remoteIp,
            payloadHash: $hash,
            order: $result->order,
            idempotencyKey: $request->idempotencyKey(),
        );
    }

    private function recordAttempt(
        ChannelIngestOutcome $outcome,
        int $httpStatus,
        ?StatutoryInvoiceChannel $channel = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $error = null,
        ?string $remoteIp = null,
        ?string $payloadHash = null,
        ?CommerceOrder $order = null,
        ?string $idempotencyKey = null,
    ): void {
        ChannelIngestAttempt::query()->create([
            'channel' => $channel,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'idempotency_key' => $idempotencyKey,
            'payload_hash' => $payloadHash,
            'outcome' => $outcome,
            'http_status' => $httpStatus,
            'signature_ok' => true,
            'commerce_order_id' => $order?->id,
            'statutory_invoice_id' => $order?->statutory_invoice_id,
            'invoice_number' => null,
            'error' => $error,
            'remote_ip' => $remoteIp,
            'received_at' => now(),
        ]);
    }
}

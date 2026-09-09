<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\Order;
use App\Services\ChannelIngest\Data\ChannelOrderIngestRequest;
use App\Services\ChannelIngest\Data\ChannelOrderLineDraft;
use App\Services\ChannelIngest\Data\ChannelOrderTenderDraft;
use Illuminate\Validation\ValidationException;

/**
 * Explicit Box hardware split-tender contract. Inactive when `tenders` is omitted.
 * Does not change Cashfree support-order facts and does not invent payment ids.
 */
final class HardwareHandoffTenderContract
{
    /**
     * @return list<ChannelOrderTenderDraft>
     */
    public function parse(mixed $raw): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }

        if (! is_array($raw)) {
            throw ValidationException::withMessages([
                'tenders' => 'tenders must be an array of cashfree and wallet rows.',
            ]);
        }

        $out = [];
        foreach ($raw as $index => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages([
                    'tenders.'.$index => 'Each tender must be an object.',
                ]);
            }

            $type = strtolower(trim((string) ($row['type'] ?? '')));
            if (! in_array($type, [ChannelOrderTenderDraft::TYPE_CASHFREE, ChannelOrderTenderDraft::TYPE_WALLET], true)) {
                throw ValidationException::withMessages([
                    'tenders.'.$index.'.type' => 'Tender type must be cashfree or wallet.',
                ]);
            }

            if (! isset($row['amount']) || ! is_numeric($row['amount'])) {
                throw ValidationException::withMessages([
                    'tenders.'.$index.'.amount' => 'Tender amount is required.',
                ]);
            }

            $amount = round((float) $row['amount'], 2);
            if ($amount < 0.01) {
                throw ValidationException::withMessages([
                    'tenders.'.$index.'.amount' => 'Tender amount must be at least 0.01.',
                ]);
            }

            $reference = $row['reference'] ?? null;
            if ($reference !== null && ! is_scalar($reference)) {
                throw ValidationException::withMessages([
                    'tenders.'.$index.'.reference' => 'Tender reference must be a string when present.',
                ]);
            }
            $reference = is_scalar($reference) ? trim((string) $reference) : '';
            $reference = $reference === '' ? null : $reference;

            $out[] = new ChannelOrderTenderDraft(
                type: $type,
                amount: $amount,
                reference: $reference,
            );
        }

        return $out;
    }

    public function assertPayload(ChannelOrderIngestRequest $request): void
    {
        if ($request->tenders === []) {
            return;
        }

        if ($request->channel !== StatutoryInvoiceChannel::RadiumBoxCom) {
            throw ValidationException::withMessages([
                'tenders' => 'Split tenders are accepted only on radiumbox_com hardware handoffs.',
            ]);
        }

        $byType = $this->uniqueByType($request);
        $cashfree = $byType[ChannelOrderTenderDraft::TYPE_CASHFREE];
        $wallet = $byType[ChannelOrderTenderDraft::TYPE_WALLET];

        if ($wallet->reference !== null && $cashfree->reference !== null && hash_equals($cashfree->reference, $wallet->reference)) {
            throw ValidationException::withMessages([
                'tenders' => 'Wallet tender must not reuse the Cashfree payment reference.',
            ]);
        }

        $orderValue = $this->orderValue($request);
        if ($orderValue === null) {
            throw ValidationException::withMessages([
                'tenders' => 'Split tender requires line_total on every line so the commercial total can be checked.',
            ]);
        }

        $tendered = round($cashfree->amount + $wallet->amount, 2);
        if (abs($tendered - $orderValue) >= 0.01) {
            throw ValidationException::withMessages([
                'tenders' => 'Wallet plus Cashfree tenders must equal the commerce line total.',
            ]);
        }
    }

    public function assertExistingCashfree(ChannelOrderIngestRequest $request): void
    {
        if ($request->tenders === []) {
            return;
        }

        $byType = $this->uniqueByType($request);
        $cashfree = $byType[ChannelOrderTenderDraft::TYPE_CASHFREE];
        $wallet = $byType[ChannelOrderTenderDraft::TYPE_WALLET];
        $this->assertUniqueCashfreeReference($request, $cashfree);

        $support = Order::query()->where('order_id', $request->sourceId)->first();
        if ($support === null) {
            return;
        }

        if ($support->payment_amount !== null && abs((float) $support->payment_amount - $cashfree->amount) >= 0.01) {
            throw ValidationException::withMessages([
                'tenders' => 'Cashfree tender amount must match the existing Desk Cashfree payment amount. The support payment is not rewritten.',
            ]);
        }

        $existingCf = trim((string) ($support->cashfree_payment_id ?? ''));
        if ($cashfree->reference !== null && $existingCf !== '' && ! hash_equals($existingCf, $cashfree->reference)) {
            throw ValidationException::withMessages([
                'tenders' => 'Cashfree tender reference must match the existing Desk Cashfree payment id. Values are not invented.',
            ]);
        }

        if ($wallet->reference !== null && $existingCf !== '' && hash_equals($existingCf, $wallet->reference)) {
            throw ValidationException::withMessages([
                'tenders' => 'Wallet tender must not be represented as the Cashfree payment id.',
            ]);
        }
    }

    public function assertUniqueCashfreeReference(ChannelOrderIngestRequest $request, ChannelOrderTenderDraft $cashfree): void
    {
        $reference = $cashfree->reference;
        if ($reference === null) {
            return;
        }

        $taken = CommerceOrder::query()
            ->where('channel', StatutoryInvoiceChannel::RadiumBoxCom)
            ->where('source_id', '!=', $request->sourceId)
            ->where('payment_reference', $reference)
            ->exists();
        $fulfilmentTaken = HardwareFulfilment::query()
            ->where('source_id', '!=', $request->sourceId)
            ->where('cashfree_payment_id', $reference)
            ->exists();
        if ($taken || $fulfilmentTaken) {
            throw ValidationException::withMessages([
                'tenders' => 'Cashfree tender reference is already used by another commerce order.',
            ]);
        }
    }

    /**
     * @return array<string, ChannelOrderTenderDraft>
     */
    private function uniqueByType(ChannelOrderIngestRequest $request): array
    {
        $byType = [];
        foreach ($request->tenders as $tender) {
            if (isset($byType[$tender->type])) {
                throw ValidationException::withMessages([
                    'tenders' => 'A hardware handoff may include at most one cashfree tender and one wallet tender.',
                ]);
            }
            $byType[$tender->type] = $tender;
        }

        if (! isset($byType[ChannelOrderTenderDraft::TYPE_CASHFREE], $byType[ChannelOrderTenderDraft::TYPE_WALLET])) {
            throw ValidationException::withMessages([
                'tenders' => 'A split-tender handoff must include exactly one cashfree tender and one wallet tender.',
            ]);
        }

        return $byType;
    }

    public function walletAmount(ChannelOrderIngestRequest $request): ?float
    {
        return $this->find($request, ChannelOrderTenderDraft::TYPE_WALLET)?->amount;
    }

    public function walletReference(ChannelOrderIngestRequest $request): ?string
    {
        return $this->find($request, ChannelOrderTenderDraft::TYPE_WALLET)?->reference;
    }

    /**
     * @return list<array{type: string, amount: float, reference: ?string}>
     */
    public function canonical(ChannelOrderIngestRequest $request): array
    {
        $rows = array_map(
            static fn (ChannelOrderTenderDraft $tender): array => [
                'type' => $tender->type,
                'amount' => $tender->amount,
                'reference' => $tender->reference,
            ],
            $request->tenders,
        );
        usort($rows, static fn (array $left, array $right): int => strcmp($left['type'], $right['type']));

        return $rows;
    }

    private function find(ChannelOrderIngestRequest $request, string $type): ?ChannelOrderTenderDraft
    {
        foreach ($request->tenders as $tender) {
            if ($tender->type === $type) {
                return $tender;
            }
        }

        return null;
    }

    private function orderValue(ChannelOrderIngestRequest $request): ?float
    {
        $total = 0.0;
        foreach ($request->lines as $line) {
            if (! $line instanceof ChannelOrderLineDraft || $line->lineTotal === null) {
                return null;
            }
            $total += $line->lineTotal;
        }

        return round($total - ($request->discount ?? 0), 2);
    }
}

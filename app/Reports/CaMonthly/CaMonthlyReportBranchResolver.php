<?php

namespace App\Reports\CaMonthly;

use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\InventoryBranch;
use App\Models\InventorySale;
use App\Models\StatutoryInvoice;
use Illuminate\Support\Collection;

final class CaMonthlyReportBranchResolver
{
    /**
     * @param  iterable<int, StatutoryInvoice>  $invoices
     * @return array<int, string>
     */
    public function resolveForInvoices(iterable $invoices, array $commerceOrdersByInvoiceId = []): array
    {
        $branchCodes = [];
        foreach ($invoices as $invoice) {
            $commerce = $commerceOrdersByInvoiceId[$invoice->id] ?? null;
            if ($commerce instanceof CommerceOrder) {
                $code = $this->nullableString($commerce->branch_code);
                if ($code !== null) {
                    $branchCodes[$code] = true;
                }
            }
        }

        $branchesByCode = $branchCodes === []
            ? []
            : InventoryBranch::query()
                ->whereIn('code', array_keys($branchCodes))
                ->get()
                ->keyBy(fn (InventoryBranch $branch): string => strtoupper((string) $branch->code))
                ->all();

        $resolved = [];
        foreach ($invoices as $invoice) {
            $resolved[$invoice->id] = $this->resolveOne(
                $invoice,
                $commerceOrdersByInvoiceId[$invoice->id] ?? null,
                $branchesByCode,
            );
        }

        return $resolved;
    }

    /**
     * @param  array<string, InventoryBranch>  $branchesByCode
     */
    public function resolveOne(
        StatutoryInvoice $invoice,
        ?CommerceOrder $commerceOrder = null,
        array $branchesByCode = [],
    ): string {
        $fromRelation = $this->nullableString($invoice->branch?->name)
            ?? $this->nullableString($invoice->branch?->code);
        if ($fromRelation !== null) {
            return $fromRelation;
        }

        $sale = $invoice->inventorySale;
        if ($sale instanceof InventorySale) {
            $saleBranch = $this->nullableString($sale->branch?->name)
                ?? $this->nullableString($sale->branch?->code);
            if ($saleBranch !== null) {
                return $saleBranch;
            }
        }

        $commerceCode = $this->nullableString($commerceOrder?->branch_code);
        if ($commerceCode !== null) {
            $mapped = $branchesByCode[strtoupper($commerceCode)] ?? null;
            if ($mapped instanceof InventoryBranch) {
                return $this->nullableString($mapped->name)
                    ?? $this->nullableString($mapped->code)
                    ?? '';
            }

            return $commerceCode;
        }

        return '';
    }

    /**
     * @param  Collection<int, StatutoryInvoice>  $invoices
     * @return array<int, CommerceOrder>
     */
    public function commerceOrdersForInvoices(Collection $invoices): array
    {
        $keys = [];
        foreach ($invoices as $invoice) {
            if ($this->sourceTypeValue($invoice) !== StatutoryInvoiceSourceType::CommerceOrder->value) {
                continue;
            }

            $keys[] = [
                'channel' => $invoice->channel->value,
                'source_type' => $this->sourceTypeValue($invoice),
                'source_id' => (string) $invoice->source_id,
            ];
        }

        if ($keys === []) {
            return [];
        }

        $query = CommerceOrder::query()->where(function ($outer) use ($keys): void {
            foreach ($keys as $key) {
                $outer->orWhere(function ($inner) use ($key): void {
                    $inner->where('channel', $key['channel'])
                        ->where('source_type', $key['source_type'])
                        ->where('source_id', $key['source_id']);
                });
            }
        });

        $byKey = [];
        foreach ($query->get() as $order) {
            $byKey[$this->commerceKey(
                $order->channel->value,
                $order->source_type,
                (string) $order->source_id,
            )] = $order;
        }

        $mapped = [];
        foreach ($invoices as $invoice) {
            if ($this->sourceTypeValue($invoice) !== StatutoryInvoiceSourceType::CommerceOrder->value) {
                continue;
            }

            $commerce = $byKey[$this->commerceKey(
                $invoice->channel->value,
                $this->sourceTypeValue($invoice),
                (string) $invoice->source_id,
            )] ?? null;

            if ($commerce !== null) {
                $mapped[$invoice->id] = $commerce;
            }
        }

        return $mapped;
    }

    private function sourceTypeValue(StatutoryInvoice $invoice): string
    {
        $sourceType = $invoice->source_type;

        return $sourceType instanceof StatutoryInvoiceSourceType
            ? $sourceType->value
            : (string) $sourceType;
    }

    private function commerceKey(string $channel, string $sourceType, string $sourceId): string
    {
        return $channel.'|'.$sourceType.'|'.$sourceId;
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

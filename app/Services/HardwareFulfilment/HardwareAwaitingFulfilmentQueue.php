<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\HardwareAwaitingFulfilmentReason;
use App\Models\HardwareFulfilment;
use App\Models\Order;
use App\Services\HardwareFulfilment\Data\HardwareAwaitingFulfilmentClassifier;
use App\Services\HardwareFulfilment\Data\HardwareAwaitingFulfilmentRow;
use App\Services\HardwareFulfilment\Data\HardwareAwaitingFulfilmentSummary;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only queue of Desk RDE support orders that do not yet have a HardwareFulfilment.
 * Does not ingest, create fulfilments, or call shipping providers.
 */
final class HardwareAwaitingFulfilmentQueue
{
    public const FILTER_REVIEW = 'review';

    public const FILTER_EXCLUDED = 'excluded';

    public const FILTER_HISTORICAL = 'historical';

    public const FILTER_COMPLETED = 'completed';

    public const FILTER_UNPAID = 'unpaid';

    public const FILTER_ALL = 'all';

    /**
     * @return LengthAwarePaginator<int, HardwareAwaitingFulfilmentRow>
     */
    public function paginate(string $filter = self::FILTER_REVIEW, string $orderSearch = '', int $perPage = 40): LengthAwarePaginator
    {
        $query = $this->rdeWithoutFulfilment()->orderByDesc('id');
        $this->applyFilter($query, $filter);

        if ($orderSearch !== '') {
            $query->where('order_id', 'like', '%'.$orderSearch.'%');
        }

        $page = $query->paginate($perPage)->withQueryString();
        $page->setCollection(
            $page->getCollection()->map(
                static fn (Order $order): HardwareAwaitingFulfilmentRow => HardwareAwaitingFulfilmentRow::fromOrder($order)
            )
        );

        return $page;
    }

    public function summary(): HardwareAwaitingFulfilmentSummary
    {
        $without = $this->rdeWithoutFulfilment();

        return new HardwareAwaitingFulfilmentSummary(
            rdeTotal: Order::query()->where('order_id', 'like', HardwareFulfilmentEligibility::SOURCE_PREFIX.'%')->count(),
            withFulfilment: HardwareFulfilment::query()->count(),
            withoutFulfilment: (clone $without)->count(),
            reviewCandidates: $this->countFilter(self::FILTER_REVIEW),
            frozen: (clone $without)->whereIn('order_id', HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS)->count(),
            hold: (clone $without)->whereIn('order_id', HardwareFulfilmentEligibility::HOLD_SOURCE_IDS)->count(),
            blocked: (clone $without)->whereIn('order_id', HardwareFulfilmentEligibility::BLOCKED_UNTIL_AUTHORIZED_SOURCE_IDS)->count(),
            unpaid: $this->countFilter(self::FILTER_UNPAID),
            preCutoff: $this->countFilter(self::FILTER_HISTORICAL),
            deskAlreadyCompleted: $this->countFilter(self::FILTER_COMPLETED),
            rin: Order::query()->where('order_id', 'like', 'RIN%')->count(),
        );
    }

    public function classify(Order $order): HardwareAwaitingFulfilmentReason
    {
        return HardwareAwaitingFulfilmentClassifier::reason($order);
    }

    /**
     * Paid RDE review candidates plus frozen/HOLD/blocked rows in the window.
     * Unpaid, Desk-completed, RIN, and out-of-window rows are omitted.
     *
     * @return Collection<int, Order>
     */
    public function workCandidateOrders(Carbon $fromIst, Carbon $toIst): Collection
    {
        $blockedIds = $this->ownerBlockedSourceIds();
        $fromBound = HardwareFulfilmentEligibility::createdAtSqlBound($fromIst);
        $toBound = HardwareFulfilmentEligibility::createdAtSqlBound($toIst);

        $review = $this->rdeWithoutFulfilment()
            ->cashfreeVerified()
            ->whereNotIn('order_id', $blockedIds)
            ->where(function (Builder $inner): void {
                $inner->whereNull('serial_number')->orWhere('serial_number', '');
            })
            ->where(function (Builder $inner): void {
                $inner->whereNull('transaction_id')->orWhere('transaction_id', '');
            })
            ->where('created_at', '>=', $fromBound)
            ->where('created_at', '<=', $toBound)
            ->orderByDesc('id')
            ->get();

        $blocked = $this->rdeWithoutFulfilment()
            ->whereIn('order_id', $blockedIds)
            ->where('created_at', '>=', $fromBound)
            ->where('created_at', '<=', $toBound)
            ->orderByDesc('id')
            ->get();

        return $review->concat($blocked);
    }

    /**
     * @return array{unpaid: int, desk_completed: int, rin: int}
     */
    public function excludedFromWorkQueue(Carbon $fromIst, Carbon $toIst): array
    {
        $blockedIds = $this->ownerBlockedSourceIds();
        $fromBound = HardwareFulfilmentEligibility::createdAtSqlBound($fromIst);
        $toBound = HardwareFulfilmentEligibility::createdAtSqlBound($toIst);
        $inWindow = $this->rdeWithoutFulfilment()
            ->where('created_at', '>=', $fromBound)
            ->where('created_at', '<=', $toBound);

        return [
            'unpaid' => (clone $inWindow)
                ->where(function (Builder $inner): void {
                    $inner->whereNull('cashfree_payment_id')
                        ->orWhere('cashfree_payment_id', '');
                })
                ->whereNotIn('order_id', $blockedIds)
                ->count(),
            'desk_completed' => (clone $inWindow)
                ->cashfreeVerified()
                ->whereNotIn('order_id', $blockedIds)
                ->where(function (Builder $inner): void {
                    $inner->where(function (Builder $serial): void {
                        $serial->whereNotNull('serial_number')
                            ->where('serial_number', '!=', '');
                    })->orWhere(function (Builder $tx): void {
                        $tx->whereNotNull('transaction_id')
                            ->where('transaction_id', '!=', '');
                    });
                })
                ->count(),
            'rin' => Order::query()->where('order_id', 'like', 'RIN%')->count(),
        ];
    }

    /**
     * @return list<string>
     */
    private function ownerBlockedSourceIds(): array
    {
        return array_values(array_unique(array_merge(
            HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS,
            HardwareFulfilmentEligibility::HOLD_SOURCE_IDS,
            HardwareFulfilmentEligibility::BLOCKED_UNTIL_AUTHORIZED_SOURCE_IDS,
        )));
    }

    private function countFilter(string $filter): int
    {
        $query = $this->rdeWithoutFulfilment();
        $this->applyFilter($query, $filter);

        return $query->count();
    }

    private function rdeWithoutFulfilment(): Builder
    {
        $sourceIds = HardwareFulfilment::query()->pluck('source_id')->filter()->all();
        $supportIds = HardwareFulfilment::query()
            ->whereNotNull('support_order_id')
            ->pluck('support_order_id')
            ->all();

        return Order::query()
            ->where('order_id', 'like', HardwareFulfilmentEligibility::SOURCE_PREFIX.'%')
            ->when($sourceIds !== [], static function (Builder $query) use ($sourceIds): void {
                $query->whereNotIn('order_id', $sourceIds);
            })
            ->when($supportIds !== [], static function (Builder $query) use ($supportIds): void {
                $query->whereNotIn('id', $supportIds);
            });
    }

    private function applyFilter(Builder $query, string $filter): void
    {
        $excluded = array_values(array_unique(array_merge(
            HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS,
            HardwareFulfilmentEligibility::HOLD_SOURCE_IDS,
            HardwareFulfilmentEligibility::BLOCKED_UNTIL_AUTHORIZED_SOURCE_IDS,
        )));

        match ($filter) {
            self::FILTER_EXCLUDED => $query->whereIn('order_id', $excluded),
            self::FILTER_UNPAID => $query
                ->where(function (Builder $inner): void {
                    $inner->whereNull('cashfree_payment_id')
                        ->orWhere('cashfree_payment_id', '');
                })
                ->whereNotIn('order_id', $excluded),
            self::FILTER_COMPLETED => $query
                ->cashfreeVerified()
                ->whereNotIn('order_id', $excluded)
                ->where(function (Builder $inner): void {
                    $inner->where(function (Builder $serial): void {
                        $serial->whereNotNull('serial_number')
                            ->where('serial_number', '!=', '');
                    })->orWhere(function (Builder $tx): void {
                        $tx->whereNotNull('transaction_id')
                            ->where('transaction_id', '!=', '');
                    });
                }),
            self::FILTER_HISTORICAL => $query
                ->cashfreeVerified()
                ->whereNotIn('order_id', $excluded)
                ->where(function (Builder $inner): void {
                    $inner->whereNull('serial_number')->orWhere('serial_number', '');
                })
                ->where(function (Builder $inner): void {
                    $inner->whereNull('transaction_id')->orWhere('transaction_id', '');
                })
                ->where('created_at', '<', HardwareFulfilmentEligibility::createdAtSqlBound(
                    HardwareFulfilmentEligibility::cutoffInstant()
                )),
            self::FILTER_ALL => null,
            default => $query
                ->cashfreeVerified()
                ->whereNotIn('order_id', $excluded)
                ->where(function (Builder $inner): void {
                    $inner->whereNull('serial_number')->orWhere('serial_number', '');
                })
                ->where(function (Builder $inner): void {
                    $inner->whereNull('transaction_id')->orWhere('transaction_id', '');
                })
                ->where('created_at', '>=', HardwareFulfilmentEligibility::createdAtSqlBound(
                    HardwareFulfilmentEligibility::cutoffInstant()
                )),
        };
    }
}
